#!/bin/bash
# Uji etalase internasional imagine.kitlab.id (Tahap 1: English saja, database sama).
#
# Yang dibuktikan:
# - site.php: host imagine → <html lang="en" data-site="imagine" data-langs="en">, meta <head> English;
#   host lain → index.html apa adanya (ResepFoto tidak berubah satu byte pun).
# - looksIndonesian(): prompt Indonesia terdeteksi, prompt Inggris tidak.
# - prompt_en: tersimpan, TIDAK hilang kalau klien lama tidak mengirimnya, dan dikirim ke klien (promptEn/promptId).
# - en_status / en_fill: hanya mengisi kolom EN yang kosong, kategori dari peta tanpa AI, prompt Indonesia
#   diterjemahkan ke prompt_en, kolom Indonesia & prompt utama tidak pernah berubah, id karangan AI diabaikan.
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

# AI tiruan: menerjemahkan dengan menambah awalan "EN " (prompt: "EN PROMPT "). mock.ds = ok|asing|kosong
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
foreach ($items as $it) {
  $o = ['id' => $mode === 'asing' ? 'zzz' . $it['id'] : $it['id']];
  foreach ($it as $k => $v) if ($k !== 'id') $o[$k] = $mode === 'kosong' ? '' : ($k === 'prompt' ? 'EN PROMPT ' : 'EN ') . $v;
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

echo
echo "Hasil: $pass lolos, $fail gagal"
[ "$fail" -eq 0 ]
