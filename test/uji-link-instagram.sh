#!/bin/bash
# Uji mode "Link Instagram" di studio resep: ai_reference + setelan DeepSeek.
#
# Instagram, DeepSeek, dan Gemini semuanya diganti SATU server tiruan lewat env
# RF_IG_BASE, RF_DEEPSEEK_BASE, dan RF_GEMINI_BASE. Yang dibuktikan: pembacaan
# halaman postingan (data carousel dan cadangan tag og:), penyaringan URL gambar,
# bentuk permintaan ke DeepSeek (vision + JSON + thinking mati), urutan mesin
# utama/cadangan, pesan error, dan gambar slide yang bisa langsung disimpan jadi
# gambar resep.
#
# TIDAK ada permintaan ke Instagram, DeepSeek, atau Gemini sungguhan, dan TIDAK
# ada API key asli yang dipakai.
# Jalankan:  bash test/uji-link-instagram.sh
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/rf-ig.XXXXXX")"
freeport(){ php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s){echo 0; exit;} $n=stream_socket_get_name($s,false); fclose($s); echo (int)substr(strrchr($n,":"),1);'; }
PORT="${PORT:-$(freeport)}"
MPORT="${MPORT:-$(freeport)}"
case "$PORT$MPORT" in *[!0-9]*|"") echo "freeport gagal (PORT='$PORT' MPORT='$MPORT')"; exit 1 ;; esac
[ "$PORT" -gt 0 ] && [ "$MPORT" -gt 0 ] || { echo "freeport mengembalikan 0"; exit 1; }
BASE="http://127.0.0.1:$PORT"
MOCK="http://127.0.0.1:$MPORT"
pass=0; fail=0
ok(){ printf '  OK    %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  GAGAL %s\n' "$1"; fail=$((fail+1)); }
cleanup(){ [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; [ -n "${MSRV:-}" ] && kill "$MSRV" 2>/dev/null; wait 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT

cp -a "$R/app/." "$T/"
rm -rf "$T/data"; mkdir -p "$T/data"
cp "$R"/app/data/seed-*.json "$T/data/" 2>/dev/null
cat > "$T/config.php" <<'PHP'
<?php
define('ADMIN_USER', 'admin');
define('ADMIN_HASH', '$2y$10$Q1xZ0vVJm2sT1nJmWbC6eO0m1kqkqQ2y9k1r8wq0lJq6Q6i0mYg1e');
define('DEMO_MEMBER', ['demo', 'Member Demo', 'RF-DEMO-0000']);
define('DB_FILE', 'rf-uji.sqlite');
PHP

# ---------- server tiruan: Instagram + DeepSeek + Gemini ----------
# Tiap permintaan dicatat ke mock.log (satu baris JSON). Perilakunya diatur file
# mock.ig (data|meta|login|404), mock.ds (ok|402|401|sampah|kosong|luar), mock.gm (ok|500).
cat > "$T/mock-ai.php" <<'PHP'
<?php
$dir  = __DIR__;
$mode = function ($f, $d) use ($dir) { return trim(@file_get_contents("$dir/mock.$f") ?: $d); };
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents("$dir/mock.log", json_encode([
  'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'path' => $path,
  'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
  'gkey' => $_SERVER['HTTP_X_GOOG_API_KEY'] ?? '', 'body' => $body,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$self = 'http://' . $_SERVER['HTTP_HOST'];

// gambar slide: JPEG 600x750 berwarna beda-beda
if (preg_match('#^/img/(\d+)\.jpg$#', $path, $m)) {
  $im = imagecreatetruecolor(600, 750);
  imagefill($im, 0, 0, imagecolorallocate($im, 40 * (int)$m[1] % 255, 90, 160));
  header('Content-Type: image/jpeg'); imagejpeg($im, null, 80); exit;
}
// halaman postingan
if (preg_match('#^/p/([A-Za-z0-9_-]+)/$#', $path, $m)) {
  $code = $m[1]; $ig = $mode('ig', 'data');
  if ($ig === '404') { http_response_code(404); echo '<html><title>Page not found</title></html>'; exit; }
  header('Content-Type: text/html');
  if ($ig === 'login') { echo '<html><head><title>Instagram</title></head><body>login challenge checkpoint</body></html>'; exit; }
  if ($ig === 'meta') {
    echo '<html><head><meta property="og:image" content="' . $self . '/img/7.jpg" />'
      . '<meta property="og:description" content="12 likes, 3 comments - akun_meta on September 25, 2026: &quot;Prompt: make me a cinematic portrait&quot;." />'
      . '</head><body></body></html>'; exit;
  }
  $media = ['code' => $code, 'pk' => '1', 'media_type' => 8, 'comment_count' => 31,
    'user' => ['username' => 'akun_uji'],
    'caption' => ['text' => "1. Buka Gemini\n2. Upload fotomu\n3. Salin prompt di slide 3 #uji"],
    'carousel_media' => [
      ['image_versions2' => ['candidates' => [['url' => "$self/img/1.jpg"], ['url' => "$self/img/99.jpg"]]]],
      ['display_uri' => "$self/img/2.jpg"],
      ['image_versions2' => ['candidates' => [['url' => "$self/img/33.jpg", 'width' => 320], ['url' => "$self/img/3.jpg", 'width' => 1080]]]],
      ['image_versions2' => ['candidates' => [['url' => 'http://169.254.169.254/latest/meta-data.jpg']]]],
    ]];
  // bentuk mirip halaman asli: data postingan terkubur di dalam script JSON lain
  echo '<html><head><title>Instagram</title></head><body>'
    . '<script type="application/json" data-sjs>{"require":[["x",{"lain":1}]]}</script>'
    . '<script type="application/json" data-sjs>' . json_encode(['require' => [['ScheduledServerJS', 'handle', null,
        [['__bbox' => ['result' => ['data' => ['xdt_api__v1__media__shortcode__web_info' => ['items' => [$media]]]]]]]]]])
    . '</script></body></html>'; exit;
}
$jawab = function (array $ex) {
  return json_encode(array_merge(['title' => 'Subway Sinematik', 'titleEn' => 'Cinematic Subway', 'cat' => 'Gaya Jalanan', 'catEn' => 'Street Style',
    'catIsNew' => false, 'desc' => 'Selfie jadi foto sinematik di stasiun.', 'descEn' => 'Selfie to cinematic station shot.',
    'tips' => 'Pakai foto wajah yang terang.', 'tipsEn' => 'Use a well-lit face photo.', 'prompt' => 'PROMPT RAPI dari slide',
    'tool' => 'Gemini', 'confidence' => 0.9, 'notes' => '', 'found' => true, 'promptSource' => 'image', 'promptSlide' => 3,
    'ocr' => 'PROMPT VERBATIM dari slide', 'exampleSlide' => 1, 'multiple' => 2], $ex));
};
// DeepSeek (format OpenAI)
if ($path === '/chat/completions') {
  header('Content-Type: application/json');
  $ds = $mode('ds', 'ok');
  if ($ds === '402') { http_response_code(402); echo '{"error":{"message":"Insufficient Balance","type":"unknown_error"}}'; exit; }
  if ($ds === '401') { http_response_code(401); echo '{"error":{"message":"Authentication Fails (no such user)","type":"authentication_error"}}'; exit; }
  $isi = $ds === 'sampah' ? 'maaf saya tidak bisa' : ($ds === 'kosong' ? $jawab(['found' => false, 'prompt' => '', 'ocr' => ''])
    : ($ds === 'luar' ? $jawab(['exampleSlide' => 9, 'promptSlide' => 1]) : $jawab([])));
  $j = json_decode($body, true);
  if (($j['max_tokens'] ?? 0) <= 20) $isi = 'SIAP';
  echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $isi], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 1234, 'completion_tokens' => 56]]); exit;
}
// Gemini
if (preg_match('#^/v1beta/models/[^:]+:generateContent$#', $path)) {
  header('Content-Type: application/json');
  $jb = json_decode($body, true);
  if (isset($jb['generationConfig']['responseModalities'])) {   // model gambar (thumbnail)
    $gi = $mode('gimg', 'ok');
    if ($gi === 'noaspect' && isset($jb['generationConfig']['imageConfig'])) {
      http_response_code(400); echo json_encode(['error' => ['message' => 'Invalid JSON payload received. Unknown name "imageConfig" at \'generation_config\'']]); exit;
    }
    if ($gi === 'noimg') { echo json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Maaf, tidak bisa.']]], 'finishReason' => 'STOP']]]); exit; }
    $im = imagecreatetruecolor(800, 800);   // sengaja kotak: rasio 4:5 harus dirapikan saat disimpan
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40));
    ob_start(); imagejpeg($im, null, 80); $b64 = base64_encode(ob_get_clean());
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $b64]]]], 'finishReason' => 'STOP']],
      'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]]); exit;
  }
  if ($mode('gm', 'ok') === '500') { http_response_code(500); echo '{"error":{"message":"internal"}}'; exit; }
  echo json_encode(['candidates' => [['content' => ['parts' => [['text' => $jawab(['prompt' => 'PROMPT DARI GEMINI'])]]], 'finishReason' => 'STOP']],
    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]]); exit;
}
http_response_code(404); echo 'tidak ada';
PHP
echo data > "$T/mock.ig"; echo ok > "$T/mock.ds"; echo ok > "$T/mock.gm"; echo ok > "$T/mock.gimg"; : > "$T/mock.log"

echo "== sintaks =="
for f in api.php lib.php ai.php; do
  if php -l "$T/$f" >/dev/null 2>&1; then ok "sintaks $f"; else no "sintaks $f"; php -l "$T/$f"; fi
done

echo "== server =="
(cd "$T" && exec php -S "127.0.0.1:$MPORT" mock-ai.php >/dev/null 2>&1) & MSRV=$!
(cd "$T" && RF_IG_BASE="$MOCK" RF_DEEPSEEK_BASE="$MOCK" RF_GEMINI_BASE="$MOCK" exec php -S "127.0.0.1:$PORT" >/dev/null 2>&1) & SRV=$!
for i in $(seq 1 40); do curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && break; sleep 0.3; done
for i in $(seq 1 40); do curl -s "$MOCK/img/1.jpg" >/dev/null 2>&1 && break; sleep 0.3; done
curl -s "$BASE/api.php?a=me" >/dev/null 2>&1 && ok "server aplikasi hidup" || no "server aplikasi tidak hidup"

csrf(){ curl -s -c "$1" -b "$1" "$BASE/api.php?a=me" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"] ?? "";'; }
postb(){ local c; c=$(csrf "$1"); curl -s -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
postc(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
login(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=login" -d "{\"username\":\"$2\",\"code\":\"$3\"}"; }
# ambil nilai dari JSON: jv '<json>' 'recipe.images.0'
jv(){ printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "";exit;} $d=$d[$k]; } echo is_bool($d)?($d?"true":"false"):(is_array($d)?json_encode($d):(string)$d);' "$2"; }
# baris terakhir mock.log yang path-nya cocok
last(){ grep "\"path\":\"$1" "$T/mock.log" | tail -1; }
count(){ grep -c "\"path\":\"$1" "$T/mock.log"; }

(cd "$T" && php -r 'require "config.php"; require "lib.php";
foreach ([["bos","super_admin","RF-BOSS-0001"],["adminbiasa","admin","RF-BIASA-0001"],["anggota","","RF-ANGG-0001"]] as $u)
saveMember(["username"=>$u[0],"name"=>$u[0],"code_hash"=>password_hash($u[2], PASSWORD_DEFAULT),"code_hint"=>"0001",
"plan"=>$u[1]===""?"Premium":"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>$u[0]."@contoh.com","phone"=>"","role"=>$u[1],"avatar"=>""]);')
S="$T/jarS"; A="$T/jarA"; M="$T/jarM"
login "$S" "bos" "RF-BOSS-0001"
login "$A" "adminbiasa" "RF-BIASA-0001"
login "$M" "anggota" "RF-ANGG-0001"
LINK="https://www.instagram.com/p/UjiKode123/?utm_source=ig_web_copy_link&stkn=abc"

echo "== hak akses =="
code=$(postc "$M" ai_reference "{\"url\":\"$LINK\"}")
[ "$code" = "403" ] && ok "member biasa ditolak di ai_reference (403)" || no "member seharusnya 403, dapat $code"
code=$(postc "$A" ai_settings_save '{"dsKey":"sk-ujicoba0000000000000000000000000"}')
[ "$code" = "403" ] && ok "admin biasa tidak bisa mengubah setelan AI (403)" || no "admin biasa seharusnya 403, dapat $code"

echo "== sebelum ada API key =="
: > "$T/mock.log"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
echo "$r" | grep -q "Isi dulu API key DeepSeek atau Gemini" && ok "tanpa key ditolak dengan pesan jelas" || no "pesan tanpa key salah: $r"
[ ! -s "$T/mock.log" ] && ok "tanpa key Instagram tidak disentuh sama sekali" || no "seharusnya tidak ada permintaan keluar"
r=$(postb "$A" ai_reference '{"url":"https://example.com/p/UjiKode123/"}')
echo "$r" | grep -q "Link harus link postingan Instagram" && ok "link bukan Instagram ditolak" || no "link asing lolos: $r"
r=$(postb "$A" ai_reference '{"url":""}')
echo "$r" | grep -q "Tempel link postingan Instagram dulu" && ok "link kosong ditolak" || no "link kosong lolos: $r"

echo "== setelan DeepSeek =="
code=$(postc "$S" ai_settings_save '{"dsKey":"pendek"}')
[ "$code" = "400" ] && ok "format key DeepSeek aneh ditolak" || no "key aneh seharusnya 400, dapat $code"
code=$(postc "$S" ai_settings_save '{"dsModel":"Model Aneh!"}')
[ "$code" = "400" ] && ok "nama model DeepSeek aneh ditolak" || no "model aneh seharusnya 400, dapat $code"
r=$(postb "$S" ai_settings_save '{"dsKey":"sk-ujicoba0000000000000000000009999","engine":"gemini"}')
[ "$(jv "$r" ok)" = "true" ] && ok "key DeepSeek tersimpan" || no "simpan key gagal: $r"
r=$(curl -s -b "$S" "$BASE/api.php?a=ai_settings")
[ "$(jv "$r" settings.dsHasKey)" = "true" ] && [ "$(jv "$r" settings.dsKeyHint)" = "9999" ] && ok "panel tahu key ada, hanya 4 digit terakhir" || no "dsHasKey/dsKeyHint salah: $r"
echo "$r" | grep -q "sk-ujicoba" && no "key utuh bocor ke panel" || ok "key utuh tidak pernah dikirim ke panel"
[ "$(jv "$r" settings.engine)" = "gemini" ] && ok "mesin utama bisa diganti ke Gemini" || no "engine seharusnya gemini"
[ "$(jv "$r" settings.dsModel)" = "deepseek-flash" ] && ok "model bawaan deepseek-flash" || no "model bawaan salah: $(jv "$r" settings.dsModel)"
postb "$S" ai_settings_save '{"engine":"ngawur"}' >/dev/null
r=$(curl -s -b "$S" "$BASE/api.php?a=ai_settings")
[ "$(jv "$r" settings.engine)" = "deepseek" ] && ok "nilai mesin tak dikenal jatuh ke DeepSeek" || no "engine ngawur seharusnya jadi deepseek"

echo "== tes koneksi DeepSeek =="
: > "$T/mock.log"
r=$(postb "$S" ai_test '{"engine":"deepseek"}')
[ "$(jv "$r" reply)" = "SIAP" ] && ok "ai_test engine=deepseek memanggil DeepSeek" || no "ai_test deepseek gagal: $r"
[ "$(count /chat/completions)" = "1" ] && ok "tepat satu panggilan ke /chat/completions" || no "jumlah panggilan salah"

echo "== jalur utama: DeepSeek membaca caption + slide =="
: > "$T/mock.log"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\",\"extra\":\"komentar: pakai kacamata\"}")
[ "$(jv "$r" ok)" = "true" ] && ok "ai_reference berhasil" || no "ai_reference gagal: $r"
[ "$(jv "$r" recipe.engine)" = "DeepSeek" ] && ok "dibaca DeepSeek (mesin utama)" || no "engine salah: $(jv "$r" recipe.engine)"
[ "$(jv "$r" recipe.fallback)" = "" ] && ok "tidak ada cadangan yang dipakai" || no "fallback seharusnya kosong"
[ "$(jv "$r" recipe.prompt)" = "PROMPT RAPI dari slide" ] && ok "kolom prompt terisi" || no "prompt salah: $(jv "$r" recipe.prompt)"
[ "$(jv "$r" recipe.ocr)" = "PROMPT VERBATIM dari slide" ] && ok "teks verbatim ikut dikirim" || no "ocr salah"
[ "$(jv "$r" recipe.title)" = "Subway Sinematik" ] && [ "$(jv "$r" recipe.cat)" = "Gaya Jalanan" ] && [ "$(jv "$r" recipe.titleEn)" = "Cinematic Subway" ] \
  && ok "judul, kategori, dan versi EN terisi" || no "metadata salah: $r"
[ "$(jv "$r" recipe.author)" = "akun_uji" ] && ok "akun sumber terbaca" || no "author salah"
[ "$(jv "$r" recipe.source)" = "https://www.instagram.com/p/UjiKode123/" ] && ok "link sumber dirapikan (utm & stkn dibuang)" || no "source salah: $(jv "$r" recipe.source)"
[ "$(jv "$r" recipe.promptSlide)" = "3" ] && [ "$(jv "$r" recipe.promptSource)" = "image" ] && ok "posisi prompt: slide 3, dari gambar" || no "promptSlide/Source salah"
[ "$(jv "$r" recipe.exampleIndex)" = "0" ] && ok "gambar contoh = slide 1 (index 0)" || no "exampleIndex salah: $(jv "$r" recipe.exampleIndex)"
[ "$(jv "$r" recipe.multiple)" = "2" ] && ok "jumlah prompt di postingan dilaporkan" || no "multiple salah"
imgs=$(jv "$r" recipe.images)
n=$(printf '%s' "$imgs" | php -r 'echo count(json_decode(stream_get_contents(STDIN),true) ?: []);')
[ "$n" = "3" ] && ok "3 slide diunduh; URL ke host di luar CDN Instagram dilewati" || no "jumlah slide salah: $n ($imgs)"
IMG0=$(jv "$r" recipe.images.0)
echo "$IMG0" | grep -qE '^uploads/tmp_[a-f0-9]{16}\.jpg$' && [ -f "$T/$IMG0" ] && ok "slide tersimpan sebagai gambar sementara" || no "gambar sementara tidak ada: $IMG0"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$IMG0")" = "200" ] && ok "gambar sementara bisa ditampilkan di studio" || no "gambar sementara tidak bisa dibuka"
grep -q '"path":"/img/3.jpg"' "$T/mock.log" && ! grep -q '"path":"/img/33.jpg"' "$T/mock.log" && ok "dipilih kandidat gambar terlebar" || no "kandidat gambar salah"
grep -q '"path":"/img/1.jpg"' "$T/mock.log" && ! grep -q '"path":"/img/99.jpg"' "$T/mock.log" && ok "tanpa lebar, kandidat pertama dipakai" || no "kandidat tanpa lebar salah"
ig=$(last /p/UjiKode123/)
echo "$ig" | grep -q 'Googlebot' && ok "halaman postingan diminta sebagai crawler" || no "UA permintaan Instagram salah: $ig"
ds=$(last /chat/completions)
printf '%s' "$ds" | php -r '
$l=json_decode(stream_get_contents(STDIN),true); $b=json_decode($l["body"],true); $c=$b["messages"][0]["content"]; $bad=[];
if(($l["auth"]??"")!=="Bearer sk-ujicoba0000000000000000000009999") $bad[]="auth";
if(($b["model"]??"")!=="deepseek-flash") $bad[]="model";
if(($b["thinking"]["type"]??"")!=="disabled") $bad[]="thinking";
if(($b["response_format"]["type"]??"")!=="json_object") $bad[]="json";
$img=array_values(array_filter($c,fn($p)=>($p["type"]??"")==="image_url"));
if(count($img)!==3) $bad[]="jumlah gambar ".count($img);
foreach($img as $p) if(strpos($p["image_url"]["url"],"data:image/jpeg;base64,")!==0) $bad[]="bukan data URI";
$txt=implode("\n",array_map(fn($p)=>$p["text"]??"",$c));
foreach(["SLIDE 1:","SLIDE 3:","Salin prompt di slide 3","komentar: pakai kacamata","@akun_uji","JANGAN menyebut slide","exampleSlide","Tren Viral"] as $s) if(strpos($txt,$s)===false) $bad[]="teks tanpa \"$s\"";
echo $bad?implode(", ",$bad):"ok";' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "permintaan DeepSeek: key, model, thinking mati, JSON, 3 gambar berlabel, caption & teks tambahan" || no "bentuk permintaan DeepSeek: $(cat "$T/cek")"
[ "$(count /v1beta/)" = "0" ] && ok "Gemini tidak dipanggil selama DeepSeek berhasil" || no "Gemini seharusnya tidak dipanggil"

echo "== slide langsung jadi gambar resep =="
r=$(postb "$A" prompt_save "{\"title\":\"Subway Sinematik\",\"cat\":\"Gaya Jalanan\",\"prompt\":\"PROMPT RAPI dari slide\",\"image_temp\":\"$IMG0\",\"tools\":\"[\\\"Gemini\\\"]\"}")
[ "$(jv "$r" ok)" = "true" ] && ok "resep tersimpan dengan slide sebagai gambar contoh" || no "prompt_save gagal: $r"
saved=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; echo db()->query("SELECT image FROM prompts WHERE title = '\''Subway Sinematik'\''")->fetchColumn();')
echo "$saved" | grep -qE '^uploads/[a-f0-9]{16}\.jpg$' && [ -f "$T/$saved" ] && ok "gambar sementara dipindah jadi gambar tetap ($saved)" || no "gambar resep tidak tersimpan: $saved"

echo "== cadangan: DeepSeek gagal → Gemini =="
(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("gemini_api_key","AIzaUJICOBA00000000000000000000000000");')
echo 402 > "$T/mock.ds"; : > "$T/mock.log"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
[ "$(jv "$r" recipe.engine)" = "Gemini" ] && ok "saldo DeepSeek habis → otomatis Gemini" || no "cadangan tidak jalan: $r"
echo "$(jv "$r" recipe.fallback)" | grep -q "Saldo DeepSeek habis" && ok "alasan cadangan dilaporkan ke admin" || no "fallback tanpa alasan: $(jv "$r" recipe.fallback)"
[ "$(jv "$r" recipe.prompt)" = "PROMPT DARI GEMINI" ] && ok "hasil memakai jawaban Gemini" || no "prompt bukan dari Gemini"
gm=$(last /v1beta/)
printf '%s' "$gm" | php -r '$l=json_decode(stream_get_contents(STDIN),true); $b=json_decode($l["body"],true); $n=0; foreach($b["contents"][0]["parts"] as $p) if(isset($p["inline_data"])) $n++; echo $n;' > "$T/cek"
[ "$(cat "$T/cek")" = "3" ] && ok "Gemini menerima 3 slide yang sama" || no "Gemini menerima $(cat "$T/cek") gambar"

REF=$(jv "$r" recipe.images.1)

echo "== thumbnail bikinan sendiri (review slide → generate) =="
postf(){ local jar="$1" ep="$2"; shift 2; local c; c=$(csrf "$jar"); curl -s -b "$jar" -c "$jar" -H "X-CSRF: $c" -X POST "$BASE/api.php?a=$ep" "$@"; }
facecount(){ (cd "$T" && php -r 'require "config.php"; require "lib.php"; echo db()->query("SELECT COUNT(*) FROM faces")->fetchColumn();'); }
php -r '$i=imagecreatetruecolor(400,500); imagefill($i,0,0,imagecolorallocate($i,230,200,180)); imagejpeg($i,$argv[1],85);' "$T/wajah.jpg"
FACEF="$T/wajah.jpg"; command -v cygpath >/dev/null 2>&1 && FACEF="$(cygpath -w "$FACEF")"   # curl Windows tidak paham path /tmp
echo "$REF" | grep -qE '^uploads/tmp_[a-f0-9]{16}\.jpg$' && ok "slide referensi tersedia untuk diuji ($REF)" || no "slide referensi kosong: $REF"
code=$(postc "$M" ai_thumb '{"prompt":"x","faceSrc":"ref","ref":"'"$REF"'"}')
[ "$code" = "403" ] && ok "member biasa ditolak di ai_thumb (403)" || no "member seharusnya 403, dapat $code"
r=$(postb "$A" ai_thumb '{"prompt":"","faceSrc":"ref","ref":"'"$REF"'"}')
echo "$r" | grep -q "Isi prompt resep dulu" && ok "tanpa prompt ditolak" || no "tanpa prompt lolos: $r"
r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"own","ref":"'"$REF"'"}')
echo "$r" | grep -q "Tempel atau pilih foto wajah dulu" && ok "wajah sendiri tanpa foto ditolak" || no "tanpa foto wajah lolos: $r"
r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"ref","ref":"../config.php"}')
echo "$r" | grep -q "Pilih slide referensi dulu" && ok "path referensi aneh (../config.php) ditolak" || no "path aneh lolos: $r"
r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"own","ref":"'"$REF"'","face_path":"uploads/../config.php"}')
echo "$r" | grep -q "Tempel atau pilih foto wajah dulu" && ok "path wajah aneh ditolak" || no "path wajah aneh lolos: $r"

: > "$T/mock.log"
r=$(postf "$A" ai_thumb -F "prompt=PROMPT UJI subway" -F faceSrc=own -F "ref=$REF" -F "face=@$FACEF;type=image/jpeg")
TH=$(jv "$r" image); FACE=$(jv "$r" face)
echo "$TH" | grep -qE '^uploads/tmp_[a-f0-9]{16}\.jpg$' && [ -f "$T/$TH" ] && ok "wajah sendiri + slide acuan → thumbnail baru tersimpan sementara" || no "thumbnail gagal: $r"
echo "$FACE" | grep -qE '^uploads/f_[a-f0-9]{16}\.jpg$' && [ -f "$T/$FACE" ] && ok "foto wajah baru masuk pustaka ($FACE)" || no "foto wajah tidak tersimpan: $FACE"
[ "$(facecount)" = "1" ] && ok "tabel faces berisi 1 wajah" || no "jumlah wajah: $(facecount)"
echo "$r" | grep -q "\"image\":\"$FACE\"" && ok "daftar wajah ikut dikirim balik" || no "daftar wajah tidak memuat wajah baru"
printf '%s' "$(last /v1beta/)" | php -r '
$l=json_decode(stream_get_contents(STDIN),true); $b=json_decode($l["body"],true); $bad=[];
if(strpos($l["path"],"gemini-3.1-flash-image")===false) $bad[]="model ".$l["path"];
if(($b["generationConfig"]["imageConfig"]["aspectRatio"]??"")!=="4:5") $bad[]="rasio";
if(!in_array("IMAGE",$b["generationConfig"]["responseModalities"]??[],true)) $bad[]="modality";
$p=$b["contents"][0]["parts"]; $n=0; $t=""; foreach($p as $x){ if(isset($x["inline_data"])) $n++; $t.=$x["text"]??""; }
if($n!==2) $bad[]="gambar=$n";
foreach(["IMAGE 1 (identity)","style reference ONLY","do NOT copy the person","no text","PROMPT UJI subway"] as $s) if(strpos($t,$s)===false) $bad[]="tanpa \"$s\"";
echo $bad?implode(", ",$bad):"ok";' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "permintaan Gemini: model gambar, rasio 4:5, wajah + slide acuan, larangan salin orang & teks" || no "bentuk permintaan thumbnail: $(cat "$T/cek")"

r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"own","ref":"'"$REF"'","face_path":"'"$FACE"'"}')
[ -n "$(jv "$r" image)" ] && [ "$(facecount)" = "1" ] && ok "wajah dari pustaka bisa dipakai ulang tanpa upload (tidak dobel)" || no "pakai ulang wajah gagal: $r"

: > "$T/mock.log"
r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"ref","ref":"'"$REF"'"}')
[ -n "$(jv "$r" image)" ] && ok "wajah dari slide: thumbnail jadi" || no "wajah dari slide gagal: $r"
printf '%s' "$(last /v1beta/)" | php -r '$l=json_decode(stream_get_contents(STDIN),true); $b=json_decode($l["body"],true); $n=0; $t=""; foreach($b["contents"][0]["parts"] as $x){ if(isset($x["inline_data"])) $n++; $t.=$x["text"]??""; } echo ($n===1 && strpos($t,"keep the same person")!==false && strpos($t,"remove every piece of text")!==false)?"ok":"n=$n";' > "$T/cek"
[ "$(cat "$T/cek")" = "ok" ] && ok "wajah dari slide: hanya slide yang dikirim, teks/watermark diminta dibuang" || no "permintaan wajah-dari-slide: $(cat "$T/cek")"
[ "$(facecount)" = "1" ] && ok "wajah dari slide tidak masuk pustaka wajah" || no "pustaka wajah bertambah padahal pakai slide"

echo noaspect > "$T/mock.gimg"; : > "$T/mock.log"
r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"ref","ref":"'"$REF"'"}')
[ -n "$(jv "$r" image)" ] && [ "$(count /v1beta/)" = "2" ] && ! last /v1beta/ | grep -q imageConfig && ok "model yang menolak imageConfig → diulang tanpa rasio" || no "ulang tanpa imageConfig gagal: $r"
echo noimg > "$T/mock.gimg"
r=$(postf "$A" ai_thumb -F "prompt=PROMPT UJI" -F faceSrc=own -F "ref=$REF" -F "face=@$FACEF;type=image/jpeg")
echo "$r" | grep -q "Model tidak mengembalikan gambar" && ok "model tanpa gambar → pesan jelas" || no "noimg: $r"
[ "$(facecount)" = "1" ] && [ "$(ls "$T"/uploads/f_* | wc -l)" = "1" ] && ok "gagal generate: foto wajah yang baru diupload tidak menumpuk" || no "sisa foto wajah: $(ls "$T"/uploads/f_* | wc -l)"
echo ok > "$T/mock.gimg"

(cd "$T" && php -r 'require "config.php"; require "lib.php"; copy("wajah.jpg","uploads/in_00000000000000aa.jpg");
db()->prepare("INSERT INTO prompt_tests (prompt_id, image, input_image, source, created_at) VALUES (?,?,?,?,?)")->execute(["x","","uploads/in_00000000000000aa.jpg","gemini",gmdate("c")]);')
r=$(curl -s -b "$A" "$BASE/api.php?a=faces")
echo "$r" | grep -q '"image":"uploads/in_00000000000000aa.jpg"' && ok "foto input Tes generate lama ikut muncul di pustaka wajah" || no "faces tanpa input tes: $r"
FID=$(printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["faces"] as $f) if($f["id"]) { echo $f["id"]; break; }')
r=$(postb "$A" face_delete "{\"id\":$FID}")
[ "$(facecount)" = "0" ] && [ ! -f "$T/$FACE" ] && ok "hapus wajah: baris & file hilang" || no "face_delete gagal: $r"
code=$(postc "$A" face_delete '{"id":0}')
[ "$code" = "404" ] && [ -f "$T/uploads/in_00000000000000aa.jpg" ] && ok "foto input Tes generate tidak bisa dihapus dari sini" || no "hapus id 0 seharusnya 404, dapat $code"

r=$(postf "$A" face_add)
echo "$r" | grep -q "Tempel atau pilih foto wajah dulu" && ok "face_add tanpa file ditolak" || no "face_add kosong lolos: $r"
r=$(postf "$A" face_add -F "face=@$FACEF;type=image/jpeg")
ADDED=$(jv "$r" face)
echo "$ADDED" | grep -qE '^uploads/f_[a-f0-9]{16}\.jpg$' && [ "$(facecount)" = "1" ] && ok "face_add: foto tim masuk pustaka tanpa generate" || no "face_add gagal: $r"
code=$(postc "$M" face_add '{}')
[ "$code" = "403" ] && ok "member biasa ditolak di face_add (403)" || no "face_add member seharusnya 403, dapat $code"
if [ -f "$T/img/models/models.json" ]; then
  r=$(curl -s -b "$A" "$BASE/api.php?a=faces")
  printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $b=array_values(array_filter($d["faces"],fn($f)=>!empty($f["builtin"]))); echo count($b)>0 && strpos($b[0]["label"],"AI")===0 && strpos($b[0]["image"],"img/models/")===0 && $b[0]["id"]===0 ? "ok" : "salah";' > "$T/cek"
  [ "$(cat "$T/cek")" = "ok" ] && ok "model AI bawaan (img/models/) ikut di pustaka wajah, berlabel AI, tidak bisa dihapus" || no "model bawaan tidak muncul: $r"
  MODEL=$(printf '%s' "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["faces"] as $f) if(!empty($f["builtin"])) { echo $f["image"]; break; }')
  r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"own","ref":"'"$REF"'","face_path":"'"$MODEL"'"}')
  [ -n "$(jv "$r" image)" ] && ok "generate thumbnail dengan wajah model AI bawaan" || no "generate dengan model bawaan gagal: $r"
else
  no "img/models/models.json belum ada"
fi
r=$(postb "$A" ai_thumb '{"prompt":"PROMPT UJI","faceSrc":"own","ref":"'"$REF"'","face_path":"img/p21.jpg"}')
echo "$r" | grep -q "Tempel atau pilih foto wajah dulu" && ok "gambar img/ lain (bukan img/models/) tidak bisa jadi wajah" || no "img/p21.jpg lolos jadi wajah: $r"

r=$(postb "$A" prompt_save "{\"title\":\"Thumbnail Sendiri\",\"cat\":\"Tren Viral\",\"prompt\":\"PROMPT UJI\",\"image_temp\":\"$TH\",\"tools\":\"[\\\"Gemini\\\"]\"}")
saved=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; echo db()->query("SELECT image FROM prompts WHERE title = '\''Thumbnail Sendiri'\''")->fetchColumn();')
dim=$(cd "$T" && php -r '$s=getimagesize($argv[1]); echo $s[0]."x".$s[1];' "$saved")
[ "$(jv "$r" ok)" = "true" ] && [ "$dim" = "960x1200" ] && ok "thumbnail kotak dari model disimpan jadi resep 4:5 ($dim)" || no "simpan thumbnail: $dim $r"

echo "== mesin utama Gemini =="
postb "$S" ai_settings_save '{"engine":"gemini"}' >/dev/null
echo ok > "$T/mock.ds"; : > "$T/mock.log"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
[ "$(jv "$r" recipe.engine)" = "Gemini" ] && [ "$(count /chat/completions)" = "0" ] && ok "engine=gemini: Gemini dipakai, DeepSeek tidak disentuh" || no "urutan mesin salah: $r"
postb "$S" ai_settings_save '{"engine":"deepseek"}' >/dev/null

echo "== dua-duanya gagal =="
echo 401 > "$T/mock.ds"; echo 500 > "$T/mock.gm"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
echo "$r" | grep -q "DeepSeek: API key DeepSeek tidak valid" && echo "$r" | grep -q "Gemini:" && ok "error menyebut kegagalan kedua mesin" || no "error gabungan salah: $r"
echo ok > "$T/mock.gm"

echo "== jawaban AI aneh =="
(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("gemini_api_key","");')
echo sampah > "$T/mock.ds"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
echo "$r" | grep -q "DeepSeek: Jawaban AI tidak bisa dibaca" && ok "jawaban bukan JSON dilaporkan (tanpa cadangan)" || no "jawaban sampah: $r"
echo kosong > "$T/mock.ds"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
echo "$r" | grep -q "Prompt tidak ditemukan di caption maupun slide" && ok "prompt tidak ketemu → saran tempel komentar" || no "pesan tidak ketemu salah: $r"
echo luar > "$T/mock.ds"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
[ "$(jv "$r" recipe.exampleIndex)" = "1" ] && [ "$(jv "$r" recipe.promptSlide)" = "1" ] && ok "exampleSlide di luar jangkauan → slide pertama yang bukan slide prompt" || no "exampleIndex cadangan salah: $(jv "$r" recipe.exampleIndex)"
echo ok > "$T/mock.ds"

echo "== Instagram menolak / berubah =="
echo login > "$T/mock.ig"; : > "$T/mock.log"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
echo "$r" | grep -q "Instagram tidak mengizinkan server membaca" && ok "halaman login → pesan jelas + saran mode gambar" || no "pesan login salah: $r"
[ "$(count /p/UjiKode123/)" = "2" ] && ok "dicoba dua kali (Googlebot lalu facebookexternalhit)" || no "jumlah percobaan: $(count /p/UjiKode123/)"
[ "$(count /chat/completions)" = "0" ] && ok "AI tidak dipanggil kalau postingan tidak terbaca" || no "AI seharusnya tidak dipanggil"
echo 404 > "$T/mock.ig"
r=$(postb "$A" ai_reference "{\"url\":\"$LINK\"}")
echo "$r" | grep -q "Postingan tidak ditemukan" && ok "postingan hilang → 404 dijelaskan" || no "pesan 404 salah: $r"
echo meta > "$T/mock.ig"; : > "$T/mock.log"
r=$(postb "$A" ai_reference '{"url":"https://instagram.com/akun_meta/reel/MetaKode9/"}')
[ "$(jv "$r" ok)" = "true" ] && ok "cadangan tag og: tetap bisa dipakai (link /akun/reel/)" || no "mode meta gagal: $r"
[ "$(jv "$r" recipe.author)" = "akun_meta" ] && ok "akun terbaca dari og:description" || no "author meta salah: $(jv "$r" recipe.author)"
n=$(printf '%s' "$(jv "$r" recipe.images)" | php -r 'echo count(json_decode(stream_get_contents(STDIN),true) ?: []);')
[ "$n" = "1" ] && ok "cadangan og: hanya dapat slide pertama" || no "jumlah gambar meta: $n"
ds=$(last /chat/completions)
printf '%s' "$ds" | grep -q 'Prompt: make me a cinematic portrait' && ! printf '%s' "$ds" | grep -q '12 likes' && ok "caption og: dibersihkan dari jumlah like" || no "caption og: tidak dibersihkan"
echo data > "$T/mock.ig"

echo
echo "Hasil: $pass lolos, $fail gagal"
[ "$fail" -eq 0 ]
