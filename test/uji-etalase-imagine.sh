#!/bin/bash
# Uji etalase internasional imagine.kitlab.id (Tahap 1: English saja) dan imagine.kitlab.id/th (Tahap 2: Thai + English),
# satu database.
#
# Yang dibuktikan:
# - site.php: host imagine → <html lang="en" data-site="imagine" data-langs="en">, meta <head> English;
#   host lain → index.html apa adanya (ResepFoto tidak berubah satu byte pun).
# - looksIndonesian(): prompt Indonesia terdeteksi, prompt Inggris tidak.
# - prompt_en: tersimpan, TIDAK hilang kalau klien lama tidak mengirimnya, dan dikirim ke klien (promptEn/promptId).
# - en_status / en_fill: hanya mengisi kolom EN yang kosong, kategori dari peta tanpa AI, prompt Indonesia
#   diterjemahkan ke prompt_en, kolom Indonesia & prompt utama tidak pernah berubah, id karangan AI diabaikan.
# - /th: site.php?s=th → <html lang="th" data-langs="th,en">, meta Thai, font Thai, manifest /th/; kamus Thai lengkap.
# - th_status / th_fill: kolom *_th hanya diisi kalau kosong, kategori dari peta, jawaban tanpa huruf Thai ditolak,
#   prompt tidak pernah dikirim/diterjemahkan, kolom Indonesia & English tidak berubah, suntingan admin dipertahankan.
# AI diganti server tiruan (RF_DEEPSEEK_BASE / RF_GEMINI_BASE). TIDAK ada API key asli yang dipakai.
# Jalankan:  bash test/uji-etalase-imagine.sh
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/rf-im.XXXXXX")"
freeport(){ php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s){echo 0; exit;} $n=stream_socket_get_name($s,false); fclose($s); echo (int)substr(strrchr($n,":"),1);'; }
PORT="${PORT:-$(freeport)}"; MPORT="${MPORT:-$(freeport)}"
case "$PORT$MPORT" in *[!0-9]*|"") echo "freeport gagal"; exit 1 ;; esac
BASE="http://127.0.0.1:$PORT"; MOCK="http://127.0.0.1:$MPORT"
pass=0; fail=0
ok(){ printf '  OK    %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  GAGAL %s\n' "$1"; fail=$((fail+1)); }
cleanup(){ [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; [ -n "${MSRV:-}" ] && kill "$MSRV" 2>/dev/null; wait 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT

cp -a "$R/app/." "$T/"
rm -rf "$T/data"; mkdir -p "$T/data"; cp "$R"/app/data/*.json "$T/data/" 2>/dev/null
cat > "$T/config.php" <<'PHP'
<?php
define('ADMIN_USER', 'admin');
define('ADMIN_HASH', '$2y$10$Q1xZ0vVJm2sT1nJmWbC6eO0m1kqkqQ2y9k1r8wq0lJq6Q6i0mYg1e');
define('DEMO_MEMBER', ['demo', 'Member Demo', 'RF-DEMO-0000']);
define('DB_FILE', 'rf-uji.sqlite');
PHP

# AI tiruan: menerjemahkan dengan menambah awalan "EN " (prompt: "EN PROMPT "); permintaan Thai → "ไทย " + sumber English
# (atau Indonesia). mock.ds = ok|asing|kosong|latin (latin = jawaban Thai tanpa huruf Thai)
cat > "$T/mock-ai.php" <<'PHP'
<?php
$dir = __DIR__; $mode = trim(@file_get_contents("$dir/mock.ds") ?: 'ok');
$body = file_get_contents('php://input');
file_put_contents("$dir/mock.log", json_encode(['path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$j = json_decode($body, true); $txt = '';
foreach (($j['messages'][0]['content'] ?? []) as $p) $txt .= $p['text'] ?? '';
$items = json_decode(substr($txt, strpos($txt, "ITEMS (JSON):\n") + 14), true) ?: [];
$out = [];
$thai = strpos($txt, 'Thai storefront') !== false;
foreach ($items as $it) {
  $o = ['id' => $mode === 'asing' ? 'zzz' . $it['id'] : $it['id']];
  foreach ($it as $k => $v) if ($k !== 'id') {
    if ($thai) $o[$k] = $mode === 'kosong' ? '' : ($mode === 'latin' ? 'Latin only ' : 'ไทย ') . ($v['en'] ?? $v['id'] ?? '');
    else $o[$k] = $mode === 'kosong' ? '' : ($k === 'prompt' ? 'EN PROMPT ' : 'EN ') . $v;
  }
  $out[] = $o;
}
echo json_encode(['choices' => [['message' => ['content' => json_encode(['items' => $out])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]]);
PHP
echo ok > "$T/mock.ds"; : > "$T/mock.log"

echo "== sintaks =="
for f in site.php api.php lib.php ai.php; do php -l "$T/$f" >/dev/null 2>&1 && ok "sintaks $f" || { no "sintaks $f"; php -l "$T/$f"; }; done

echo "== server =="
(cd "$T" && exec php -S "127.0.0.1:$MPORT" mock-ai.php >/dev/null 2>&1) & MSRV=$!
(cd "$T" && RF_DEEPSEEK_BASE="$MOCK" RF_GEMINI_BASE="$MOCK" exec php -S "127.0.0.1:$PORT" >/dev/null 2>&1) & SRV=$!
for i in $(seq 1 40); do curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && break; sleep 0.3; done
curl -s "$BASE/api.php?a=me" | grep -q '"ok":true' && ok "server aplikasi hidup" || no "server aplikasi tidak hidup"

echo "== site.php: etalase imagine =="
H=$(curl -s -H "Host: imagine.kitlab.id" "$BASE/site.php")
echo "$H" | grep -q '<html lang="en" data-site="imagine" data-langs="en" data-brand="Imagine" data-title="Imagine · AI Photo Recipes">' && ok "<html> English + data-site/langs/brand" || no "atribut <html> salah: $(echo "$H" | grep -o '<html[^>]*>')"
echo "$H" | grep -q '<title>Imagine · AI Photo Recipes</title>' && ok "judul tab Imagine" || no "judul salah"
echo "$H" | grep -q 'rel="canonical" href="https://imagine.kitlab.id/"' && ok "canonical ke imagine.kitlab.id" || no "canonical salah"
echo "$H" | grep -q 'og:locale" content="en_US"' && ok "og:locale en_US" || no "og:locale salah"
HEAD=$(echo "$H" | sed -n '/<!--site:head-->/,/<!--\/site:head-->/p')
echo "$HEAD" | grep -qiE 'Foto biasa|copas|resep prompt|resepfoto\.kitlab|ResepFoto' && no "masih ada teks Indonesia/ResepFoto di meta: $(echo "$HEAD" | grep -iE 'Foto biasa|copas|resep|ResepFoto' | head -2)" || ok "meta <head> bebas teks Indonesia & nama ResepFoto"
echo "$H" | grep -q 'id="s-login"' && echo "$H" | grep -q 'id="im-lockup"' && ok "isi app (index.html) tetap utuh, logo Imagine tersedia" || no "isi app hilang"
[ "$(echo "$H" | grep -c '<meta name="description"')" = "1" ] && ok "tidak ada meta ganda" || no "meta description ganda"
M=$(curl -s -H "Host: imagine.kitlab.id" "$BASE/site.php?f=manifest")
echo "$M" | php -r '$d=json_decode(stream_get_contents(STDIN),true); exit(($d["name"]??"")==="Imagine" && ($d["short_name"]??"")==="Imagine" ? 0 : 1);' && ok "manifest bernama Imagine" || no "manifest salah: $M"

echo "== site.php: host lain = ResepFoto apa adanya =="
a=$(curl -s -H "Host: resepfoto.kitlab.id" "$BASE/site.php" | md5sum | cut -c1-32); b=$(md5sum < "$T/index.html" | cut -c1-32)
[ "$a" = "$b" ] && ok "resepfoto.kitlab.id menerima index.html byte-per-byte" || no "keluaran host resepfoto berbeda dari index.html"
[ "$(grep -c '<!--site:head-->' "$T/index.html")" = "1" ] && [ "$(grep -c '<!--/site:head-->' "$T/index.html")" = "1" ] && ok "penanda site:head ada tepat sekali" || no "penanda site:head hilang/ganda"
grep -q 'RewriteCond %{HTTP_HOST} ^imagine\\. \[NC\]' "$T/.htaccess" && grep -q 'RewriteRule ^(index\\.html)?$ site.php \[L\]' "$T/.htaccess" && ok ".htaccess mengarahkan halaman utama imagine ke site.php" || no "aturan .htaccess imagine tidak ada"

echo "== deteksi prompt Indonesia =="
(cd "$T" && php -r 'require "config.php"; require "lib.php";
$id = ["UBAH FOTO INI MENJADI MEDIUM-SHOT STUDIO PORTRAIT dengan latar belakang putih, wajah sama persis dengan foto yang diupload",
       "Gunakan wajah yang sama persis dengan foto referensi, buat saya sedang berdiri di jalan dengan baju batik"];
$en = ["I have uploaded one reference photo of my face. Using the given image reconstruct my face accurately, place me in this exact subway station scene.",
       "Ultra-realistic 4K portrait of the uploaded person on a Jakarta street in the 1980s, keep the face identical, film grain, warm tones."];
foreach ($id as $s) if (!looksIndonesian($s)) { echo "ID-TIDAK-TERDETEKSI: $s\n"; }
foreach ($en as $s) if (looksIndonesian($s)) { echo "EN-SALAH-DETEKSI: $s\n"; }
$n = 0; foreach (db()->query("SELECT prompt FROM prompts") as $r) if (looksIndonesian($r["prompt"])) $n++; echo "seed-indonesia=$n\n";') > "$T/cek"
grep -q "TERDETEKSI\|SALAH-DETEKSI" "$T/cek" && no "deteksi bahasa: $(grep -E 'TERDETEKSI|SALAH' "$T/cek")" || ok "prompt Indonesia terdeteksi, prompt Inggris tidak"

echo "== prompt_en & data resep =="
csrf(){ curl -s -c "$1" -b "$1" "$BASE/api.php?a=me" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"] ?? "";'; }
postb(){ local c; c=$(csrf "$1"); curl -s -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
postc(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
login(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=login" -d "{\"username\":\"$2\",\"code\":\"$3\"}"; }
q(){ (cd "$T" && php -r 'require "config.php"; require "lib.php"; $r=db()->query($argv[1])->fetch(PDO::FETCH_NUM); echo $r===false?"":implode("|",$r);' "$1"); }
(cd "$T" && php -r 'require "config.php"; require "lib.php";
foreach ([["bos","super_admin","RF-BOSS-0001","Admin"],["anggota","","RF-ANGG-0001","Premium"]] as $u)
saveMember(["username"=>$u[0],"name"=>$u[0],"code_hash"=>password_hash($u[2], PASSWORD_DEFAULT),"code_hint"=>"0001","plan"=>$u[3],"expires"=>"","active"=>1,
"created_at"=>gmdate("c"),"last_login"=>null,"email"=>$u[0]."@contoh.com","phone"=>"","role"=>$u[1],"avatar"=>""]);
$ins = db()->prepare("INSERT INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, updated_at, created_at, cat_en, title_en, descr_en, tips_en, prompt_en) VALUES (?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?)");
$now = gmdate("c");
setSetting("deepseek_api_key", "uji-palsu-bukan-key-asli-000000000001");   // key palsu, AI-nya server tiruan
$ins->execute(["zid1", 900, "Keluarga", "Foto Keluarga Lebaran", "Foto keluarga pakai baju lebaran", "[\"Gemini\"]", "Gunakan wajah yang sama persis dengan foto, buat kami sekeluarga memakai baju lebaran dengan latar belakang ruang tamu", "Pakai foto yang terang", "img/p01.jpg", $now, $now, "", "", "", "", ""]);
$ins->execute(["zid2", 901, "Kategori Baru Uji", "Judul Uji", "", "[\"Gemini\"]", "Ultra-realistic portrait of the uploaded person, keep the face identical, cinematic light", "", "img/p02.jpg", $now, $now, "", "KEEP JUDUL LAMA", "", "", ""]);')
S="$T/jarS"; M2="$T/jarM"
login "$S" bos RF-BOSS-0001; login "$M2" anggota RF-ANGG-0001
r=$(curl -s -b "$M2" "$BASE/api.php?a=prompts")
printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=[]; foreach($d["prompts"] as $x) $p[$x["id"]]=$x;
echo (isset($p["zid1"]) && $p["zid1"]["promptId"]===true && $p["zid1"]["promptEn"]==="" && isset($p["zid2"]) && $p["zid2"]["promptId"]===false) ? "ok" : "salah";' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "API resep mengirim promptId (prompt Indonesia?) & promptEn" || no "promptId/promptEn salah"

code=$(postc "$M2" en_fill '{}'); [ "$code" = "403" ] && ok "member biasa ditolak di en_fill (403)" || no "en_fill member: $code"
code=$(postc "$M2" en_status '{}'); [ "$code" = "403" ] && ok "member biasa ditolak di en_status (403)" || no "en_status member: $code"

echo "== en_status & en_fill =="
s=$(curl -s -b "$S" "$BASE/api.php?a=en_status")
printf '%s' "$s" | php -r '$d=json_decode(stream_get_contents(STDIN),true)["status"]; echo ($d["fields"]["prompt"]>=1 && $d["fields"]["title"]>=1 && $d["visible"] < $d["total"]) ? "ok" : json_encode($d);' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "en_status menghitung kolom EN kosong & resep yang belum tampil" || no "en_status: $(cat "$T/cek")"
PROMPT_ASLI=$(q "SELECT prompt FROM prompts WHERE id='zid1'"); TITLE_ASLI=$(q "SELECT title FROM prompts WHERE id='zid1'")
echo asing > "$T/mock.ds"
r=$(postb "$S" en_fill '{}')
[ "$(q "SELECT title_en FROM prompts WHERE id='zid1'")" = "" ] && ok "id karangan AI diabaikan (tidak ada yang tertulis)" || no "id asing tertulis"
echo ok > "$T/mock.ds"; : > "$T/mock.log"
for i in $(seq 1 30); do r=$(postb "$S" en_fill '{}'); left=$(printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo array_sum($d["status"]["fields"] ?? [1]);'); [ "$left" = "0" ] && break; done
[ "$left" = "0" ] && ok "en_fill berulang sampai tidak ada kolom EN kosong ($i putaran)" || no "en_fill tidak selesai: $r"
[ "$(q "SELECT prompt_en FROM prompts WHERE id='zid1'")" = "EN PROMPT $PROMPT_ASLI" ] && ok "prompt Indonesia diterjemahkan ke prompt_en" || no "prompt_en salah: $(q "SELECT prompt_en FROM prompts WHERE id='zid1'")"
[ "$(q "SELECT prompt FROM prompts WHERE id='zid1'")" = "$PROMPT_ASLI" ] && [ "$(q "SELECT title FROM prompts WHERE id='zid1'")" = "$TITLE_ASLI" ] && ok "prompt utama & judul Indonesia tidak berubah" || no "kolom Indonesia berubah!"
[ "$(q "SELECT title_en FROM prompts WHERE id='zid2'")" = "KEEP JUDUL LAMA" ] && ok "judul EN yang sudah ada tidak ditimpa" || no "judul EN tertimpa: $(q "SELECT title_en FROM prompts WHERE id='zid2'")"
[ "$(q "SELECT prompt_en FROM prompts WHERE id='zid2'")" = "" ] && ok "prompt yang sudah Inggris tidak diberi prompt_en" || no "prompt Inggris ikut diterjemahkan"
[ "$(q "SELECT cat_en FROM prompts WHERE id='zid1'")" = "Family" ] && ok "kategori dikenal diisi dari peta (Keluarga → Family) tanpa AI" || no "cat_en zid1: $(q "SELECT cat_en FROM prompts WHERE id='zid1'")"
[ "$(q "SELECT cat_en FROM prompts WHERE id='zid2'")" = "EN Kategori Baru Uji" ] && ok "kategori baru diterjemahkan AI" || no "cat_en zid2: $(q "SELECT cat_en FROM prompts WHERE id='zid2'")"
php -r '$cats=[]; foreach (file($argv[1]) as $l) { $b=json_decode(json_decode($l,true)["body"],true); $t=""; foreach (($b["messages"][0]["content"]??[]) as $p) $t.=$p["text"]??"";
  $i=strpos($t,"ITEMS (JSON):\n"); if ($i===false) continue; foreach (json_decode(substr($t,$i+14),true)?:[] as $it) if (isset($it["cat"])) $cats[]=$it["cat"]; }
  echo in_array("Keluarga",$cats,true) ? "terkirim" : "ok";' "$T/mock.log" > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "kategori yang sudah dipetakan tidak dikirim ke AI" || no "kategori terpeta ikut dikirim ke AI"
s=$(curl -s -b "$S" "$BASE/api.php?a=en_status")
printf '%s' "$s" | php -r '$d=json_decode(stream_get_contents(STDIN),true)["status"]; echo $d["visible"]===$d["total"] ? "ok" : json_encode($d);' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "sesudah en_fill semua resep tampil di etalase EN" || no "visible != total: $(cat "$T/cek")"
r=$(postb "$S" en_fill '{}'); [ "$(printf '%s' "$r" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["done"] ?? "x";')" = "0" ] && ok "en_fill saat sudah lengkap tidak memanggil apa-apa (done=0)" || no "en_fill kosong: $r"

echo "== prompt_save & prompt_en =="
postb "$S" prompt_save '{"id":"zid1","title":"Foto Keluarga Lebaran","cat":"Keluarga","prompt":"'"$PROMPT_ASLI"'","tools":"[\"Gemini\"]"}' >/dev/null
[ "$(q "SELECT prompt_en FROM prompts WHERE id='zid1'")" = "EN PROMPT $PROMPT_ASLI" ] && ok "simpan tanpa kolom prompt_en (klien lama) → prompt_en dipertahankan" || no "prompt_en hilang saat disimpan klien lama"
postb "$S" prompt_save '{"id":"zid1","title":"Foto Keluarga Lebaran","cat":"Keluarga","prompt":"'"$PROMPT_ASLI"'","prompt_en":"MANUAL EN","tools":"[\"Gemini\"]"}' >/dev/null
[ "$(q "SELECT prompt_en FROM prompts WHERE id='zid1'")" = "MANUAL EN" ] && ok "prompt_en bisa disunting admin" || no "prompt_en tidak tersimpan"
r=$(curl -s -b "$M2" "$BASE/api.php?a=prompts")
printf '%s' "$r" | grep -q '"promptEn":"MANUAL EN"' && ok "promptEn terkirim ke member" || no "promptEn tidak terkirim"

echo "== site.php: etalase Thailand (/th) =="
H=$(curl -s -H "Host: imagine.kitlab.id" "$BASE/site.php?s=th")
echo "$H" | grep -q '<html lang="th" data-site="imagine-th" data-langs="th,en" data-brand="Imagine" data-title="Imagine · สูตรภาพถ่าย AI">' && ok "<html> Thai bawaan + English (data-langs th,en)" || no "atribut <html> /th salah: $(echo "$H" | grep -o '<html[^>]*>')"
echo "$H" | grep -q '<title>Imagine · สูตรภาพถ่าย AI</title>' && ok "judul tab berbahasa Thai" || no "judul /th salah"
echo "$H" | grep -q 'rel="canonical" href="https://imagine.kitlab.id/th/"' && ok "canonical ke imagine.kitlab.id/th/" || no "canonical /th salah"
echo "$H" | grep -q 'og:locale" content="th_TH"' && ok "og:locale th_TH" || no "og:locale /th salah"
echo "$H" | grep -q 'family=Noto+Sans+Thai' && ok "font Noto Sans Thai dimuat" || no "font Thai tidak dimuat"
echo "$H" | grep -q 'rel="manifest" href="/th/site.webmanifest"' && ok "manifest /th/site.webmanifest" || no "link manifest /th salah"
echo "$H" | grep -q 'hreflang="en" href="https://imagine.kitlab.id/"' && echo "$H" | grep -q 'hreflang="th" href="https://imagine.kitlab.id/th/"' && ok "hreflang en ↔ th" || no "hreflang tidak lengkap"
HEAD=$(echo "$H" | sed -n '/<!--site:head-->/,/<!--\/site:head-->/p')
echo "$HEAD" | grep -qiE 'Foto biasa|copas|resep prompt|resepfoto\.kitlab|ResepFoto' && no "masih ada teks Indonesia/ResepFoto di meta /th" || ok "meta <head> /th bebas teks Indonesia & nama ResepFoto"
M=$(curl -s -H "Host: imagine.kitlab.id" "$BASE/site.php?s=th&f=manifest")
echo "$M" | php -r '$d=json_decode(stream_get_contents(STDIN),true); exit(($d["start_url"]??"")==="/th/" && ($d["scope"]??"")==="/th/" && ($d["lang"]??"")==="th" ? 0 : 1);' && ok "manifest /th: start_url & scope /th/, lang th" || no "manifest /th salah: $M"
H=$(curl -s -H "Host: imagine.kitlab.id" "$BASE/site.php")
echo "$H" | grep -q 'data-site="imagine" data-langs="en"' && echo "$H" | grep -q 'hreflang="th"' && ! echo "$H" | grep -q 'Noto+Sans+Thai' && ok "etalase English tetap English (+hreflang ke /th, tanpa font Thai)" || no "etalase English berubah"
curl -s -H "Host: imagine.kitlab.id" "$BASE/site.php?s=xx" | grep -q 'data-site="imagine" data-langs="en"' && ok "?s= tak dikenal → etalase English" || no "?s= tak dikenal salah"
a=$(curl -s -H "Host: resepfoto.kitlab.id" "$BASE/site.php?s=th" | md5sum | cut -c1-32); b=$(md5sum < "$T/index.html" | cut -c1-32)
[ "$a" = "$b" ] && ok "resepfoto.kitlab.id dengan ?s=th tetap index.html apa adanya" || no "host resepfoto terpengaruh ?s=th"
grep -q 'RewriteRule ^th/(index\\.html)?$ site.php?s=th \[L,QSA\]' "$T/.htaccess" && grep -q 'RewriteRule ^th/(.+)$ $1 \[L\]' "$T/.htaccess" && grep -q 'RewriteRule ^th$ /th/ \[R=301,L\]' "$T/.htaccess" && ok ".htaccess: /th → site.php?s=th, file lain di /th/ dari folder utama" || no "aturan .htaccess /th tidak lengkap"
awk '/\^th\/\(\.\+\)\$/{a=NR} /\^\(index\\\.html\)\?\$ site\.php \[L\]/{b=NR} /\^th\/\(index/{c=NR} END{exit !(c<a && a<b)}' "$T/.htaccess" && ok ".htaccess: urutan aturan /th benar (halaman /th sebelum file /th/*, sebelum beranda)" || no "urutan aturan .htaccess /th salah"

echo "== kamus Thai & tampilan =="
grep -q 'html\[lang="th"\]{--font:"Inter", "Noto Sans Thai"' "$T/index.html" && ok "CSS: huruf Thai pakai Noto Sans Thai" || no "CSS font Thai tidak ada"
if command -v node >/dev/null 2>&1; then
  node -e '
const fs=require("fs");const h=fs.readFileSync(process.argv[1],"utf8");
const s=[...h.matchAll(/<script(?![^>]*src)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).find(x=>x.includes("const I18N"));
const I=new Function(s.slice(s.indexOf("const I18N"),s.indexOf("/* Etalase: resepfoto.kitlab.id"))+";return I18N;")();
const miss=Object.keys(I.en).filter(k=>!/^(adm|pf|mf)\./.test(k)&&!(k in I.th));
const bad=Object.entries(I.th).filter(([k,v])=>!/[฀-๿]/.test(v)&&!/^(detail\.openGemini|detail\.openGpt)$/.test(k)).map(([k])=>k);
const ph=Object.keys(I.th).filter(k=>JSON.stringify((I.en[k].match(/\{\w+\}/g)||[]).sort())!==JSON.stringify((I.th[k].match(/\{\w+\}/g)||[]).sort()));
console.log(miss.length||bad.length||ph.length?"miss="+miss+" bad="+bad+" ph="+ph:"ok");' "$T/index.html" > "$T/cek" 2>&1
  [ "$(cat "$T/cek")" = "ok" ] && ok "kamus Thai: semua teks pembeli ada, berhuruf Thai, placeholder {n}/{d} sama" || no "kamus Thai: $(cat "$T/cek")"
else ok "(node tidak ada: cek kamus Thai dilewati)"; fi

echo "== kolom Thai & th_fill =="
cols=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; echo implode(",", array_column(db()->query("PRAGMA table_info(prompts)")->fetchAll(), "name"));')
case ",$cols," in *,cat_th,*title_th,*descr_th,*tips_th,*) ok "migrasi menambah cat_th, title_th, descr_th, tips_th" ;; *) no "kolom Thai tidak ada: $cols" ;; esac
code=$(postc "$M2" th_fill '{}'); [ "$code" = "403" ] && ok "member biasa ditolak di th_fill (403)" || no "th_fill member: $code"
code=$(postc "$M2" th_status '{}'); [ "$code" = "403" ] && ok "member biasa ditolak di th_status (403)" || no "th_status member: $code"
(cd "$T" && php -r 'require "config.php"; require "lib.php"; db()->exec("UPDATE prompts SET title_th = '"'"'คงเดิม'"'"' WHERE id = '"'"'zid2'"'"'");')
BEFORE=$(q "SELECT title||'|'||title_en||'|'||prompt||'|'||prompt_en||'|'||descr||'|'||tips||'|'||cat||'|'||cat_en FROM prompts WHERE id='zid1'")
s=$(curl -s -b "$S" "$BASE/api.php?a=th_status")
printf '%s' "$s" | php -r '$d=json_decode(stream_get_contents(STDIN),true)["status"]; echo ($d["fields"]["title"]>=1 && $d["complete"] < $d["total"] && !isset($d["fields"]["prompt"])) ? "ok" : json_encode($d);' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "th_status menghitung kolom Thai kosong (tanpa prompt)" || no "th_status: $(cat "$T/cek")"
echo latin > "$T/mock.ds"
postb "$S" th_fill '{}' >/dev/null
[ "$(q "SELECT title_th FROM prompts WHERE id='zid1'")" = "" ] && ok "jawaban AI tanpa huruf Thai tidak disimpan" || no "teks non-Thai tersimpan: $(q "SELECT title_th FROM prompts WHERE id='zid1'")"
echo asing > "$T/mock.ds"
postb "$S" th_fill '{}' >/dev/null
[ "$(q "SELECT title_th FROM prompts WHERE id='zid1'")" = "" ] && ok "th_fill: id karangan AI diabaikan" || no "th_fill: id asing tertulis"
echo ok > "$T/mock.ds"; : > "$T/mock.log"
for i in $(seq 1 30); do r=$(postb "$S" th_fill '{}'); left=$(printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo array_sum($d["status"]["fields"] ?? [1]);'); [ "$left" = "0" ] && break; done
[ "$left" = "0" ] && ok "th_fill berulang sampai tidak ada kolom Thai kosong ($i putaran)" || no "th_fill tidak selesai: $r"
# zid1 tidak punya judul English lagi (dikosongkan klien lama di atas) → sumbernya judul Indonesia; resep bawaan punya keduanya.
[ "$(q "SELECT title_th FROM prompts WHERE id='zid1'")" = "ไทย Foto Keluarga Lebaran" ] && ok "tanpa judul English, judul Thai ditulis dari sumber Indonesia" || no "title_th zid1: $(q "SELECT title_th FROM prompts WHERE id='zid1'")"
[ "$(q "SELECT COUNT(*) FROM prompts WHERE title_en <> '' AND title_th <> 'ไทย ' || title_en AND id NOT IN ('zid1','zid2')")" = "0" ] && ok "resep yang punya judul English → judul Thai dari sumber English" || no "sumber English tidak dipakai"
[ "$(q "SELECT title_th FROM prompts WHERE id='zid2'")" = "คงเดิม" ] && ok "judul Thai yang sudah ada tidak ditimpa" || no "title_th zid2 tertimpa"
[ "$(q "SELECT cat_th FROM prompts WHERE id='zid1'")" = "ครอบครัว" ] && ok "kategori dikenal diisi dari peta (Keluarga → ครอบครัว) tanpa AI" || no "cat_th zid1: $(q "SELECT cat_th FROM prompts WHERE id='zid1'")"
[ "$(q "SELECT cat_th FROM prompts WHERE id='zid2'")" = "ไทย EN Kategori Baru Uji" ] && ok "kategori baru ditulis AI" || no "cat_th zid2: $(q "SELECT cat_th FROM prompts WHERE id='zid2'")"
[ "$(q "SELECT title||'|'||title_en||'|'||prompt||'|'||prompt_en||'|'||descr||'|'||tips||'|'||cat||'|'||cat_en FROM prompts WHERE id='zid1'")" = "$BEFORE" ] && ok "kolom Indonesia, English, dan prompt tidak berubah oleh th_fill" || no "th_fill mengubah kolom lain!"
php -r '$bad=0; $both=0; foreach (file($argv[1]) as $l) { $b=json_decode(json_decode($l,true)["body"],true); $t=""; foreach (($b["messages"][0]["content"]??[]) as $p) $t.=$p["text"]??"";
  $i=strpos($t,"ITEMS (JSON):\n"); if ($i===false || strpos($t,"Thai storefront")===false) continue;
  foreach (json_decode(substr($t,$i+14),true)?:[] as $it) { if (isset($it["prompt"])) $bad++; if (($it["cat"]["id"] ?? "")==="Keluarga") $bad++;
    if (isset($it["title"]["id"], $it["title"]["en"])) $both++; } }
  echo $bad ? "prompt/kategori-terpeta terkirim" : ($both ? "ok" : "sumber id+en tidak terkirim");' "$T/mock.log" > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "ke AI: sumber Indonesia + English terkirim, prompt & kategori terpeta tidak" || no "isi permintaan th_fill: $(cat "$T/cek")"
[ "$(q "SELECT COUNT(*) FROM ai_log WHERE action = 'translate_th'")" -ge 1 ] && ok "pemakaian AI dicatat sebagai translate_th" || no "log translate_th tidak ada"
r=$(postb "$S" th_fill '{}'); [ "$(printf '%s' "$r" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["done"] ?? "x";')" = "0" ] && ok "th_fill saat sudah lengkap tidak memanggil apa-apa (done=0)" || no "th_fill kosong: $r"
r=$(curl -s -b "$M2" "$BASE/api.php?a=prompts")
printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=[]; foreach($d["prompts"] as $x) $p[$x["id"]]=$x;
echo (($p["zid1"]["catTh"]??"")==="ครอบครัว" && strpos($p["zid1"]["titleTh"]??"","ไทย ")===0 && array_key_exists("descTh",$p["zid1"]) && array_key_exists("tipsTh",$p["zid1"])) ? "ok" : json_encode($p["zid1"] ?? null, JSON_UNESCAPED_UNICODE);' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "API resep mengirim titleTh/descTh/tipsTh/catTh ke member" || no "field Thai tidak terkirim: $(cat "$T/cek")"
TH1=$(q "SELECT title_th FROM prompts WHERE id='zid1'")
postb "$S" prompt_save '{"id":"zid1","title":"Foto Keluarga Lebaran","cat":"Keluarga","prompt":"'"$PROMPT_ASLI"'","tools":"[\"Gemini\"]"}' >/dev/null
[ "$(q "SELECT title_th FROM prompts WHERE id='zid1'")" = "$TH1" ] && [ "$(q "SELECT cat_th FROM prompts WHERE id='zid1'")" = "ครอบครัว" ] && ok "simpan tanpa kolom Thai (klien lama) → teks Thai dipertahankan" || no "teks Thai hilang saat disimpan klien lama"
# JSON berhuruf Thai lewat file: argumen baris perintah di Windows tidak selalu UTF-8.
printf '%s' '{"id":"zid1","title":"Foto Keluarga Lebaran","cat":"Keluarga","prompt":"'"$PROMPT_ASLI"'","title_th":"รูปครอบครัววันรายอ","tips_th":"","tools":"[\"Gemini\"]"}' > "$T/th.json"
postb "$S" prompt_save "@$(cygpath -w "$T/th.json" 2>/dev/null || echo "$T/th.json")" >/dev/null
[ "$(q "SELECT title_th FROM prompts WHERE id='zid1'")" = "รูปครอบครัววันรายอ" ] && [ "$(q "SELECT tips_th FROM prompts WHERE id='zid1'")" = "" ] && ok "teks Thai bisa disunting / dikosongkan admin" || no "suntingan Thai tidak tersimpan"

echo
echo "Hasil: $pass lolos, $fail gagal"
[ "$fail" -eq 0 ]
