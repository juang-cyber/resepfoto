#!/bin/bash
# Uji jalur voucher yang MEMANGGIL API Mayar: voucher_create + voucher_sync.
#
# Kenapa ada file terpisah: test/uji-pesan-voucher.sh hanya menguji voucher_save/
# voucher_delete (pencatatan lokal). Dua endpoint yang benar-benar bicara ke Mayar
# tidak pernah teruji karena butuh API key sungguhan. Di sini Mayar diganti server
# tiruan lewat env RF_MAYAR_BASE, jadi bentuk permintaan, penyimpanan mayar_id,
# jalur baca balik, dan penanganan errornya bisa dibuktikan tanpa menyentuh Mayar.
#
# TIDAK ada kupon sungguhan yang dibuat dan TIDAK ada API key asli yang dipakai.
# Jalankan:  bash test/uji-voucher-mayar.sh
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/rf-vm.XXXXXX")"
freeport(){ php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s){echo 0; exit;} $n=stream_socket_get_name($s,false); fclose($s); echo (int)substr(strrchr($n,":"),1);'; }
PORT="${PORT:-$(freeport)}"
MPORT="${MPORT:-$(freeport)}"
# Port kosong pernah lolos diam-diam: php -S 127.0.0.1: lalu semua panggilan Mayar
# mendarat di port 80 dan gagal dengan pesan yang menyesatkan. Berhenti di sini saja.
case "$PORT$MPORT" in *[!0-9]*|"") echo "freeport gagal (PORT='$PORT' MPORT='$MPORT')"; exit 1 ;; esac
[ "$PORT" -gt 0 ] && [ "$MPORT" -gt 0 ] || { echo "freeport mengembalikan 0"; exit 1; }
BASE="http://127.0.0.1:$PORT"
MOCK="http://127.0.0.1:$MPORT"
pass=0; fail=0
ok(){ printf '  OK    %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  GAGAL %s\n' "$1"; fail=$((fail+1)); }
cleanup(){ [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; [ -n "${MSRV:-}" ] && kill "$MSRV" 2>/dev/null; rm -rf "$T"; }
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

# ---------- server Mayar tiruan ----------
# Mencatat tiap permintaan ke mock.log supaya bentuknya bisa diperiksa, dan
# mengikuti mock.mode untuk mensimulasi penolakan.
cat > "$T/mock-mayar.php" <<'PHP'
<?php
$dir  = __DIR__;
$mode = trim(@file_get_contents($dir . '/mock.mode') ?: 'ok');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents($dir . '/mock.log', json_encode([
  'method' => $_SERVER['REQUEST_METHOD'] ?? '',
  'path'   => $path,
  'auth'   => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
  'ctype'  => $_SERVER['CONTENT_TYPE'] ?? '',
  'body'   => $body,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ($mode === '401') { http_response_code(401); echo json_encode(['statusCode' => 401, 'messages' => '']); exit; }
if ($mode === 'sampah') { echo '<html>bukan json</html>'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/coupon/create') {
  // Mayar asli menolak bentuk payload yang salah dengan "Validation Error" dan TIDAK
  // membuat apa pun. Mock ini menirunya: hanya satu bentuk yang diakui, dibaca dari
  // mock.bentuk, supaya pencarian bentuk di mayarCreateCoupon() benar-benar teruji.
  $j = json_decode($body, true);
  $terima = trim(@file_get_contents($dir . '/mock.bentuk') ?: '') ?: 'coupon-objek';
  $d = isset($j['discount']) ? $j['discount'] : null;
  $c = isset($j['coupon']) ? $j['coupon'] : null;
  $bentuk = 'lain'; $kode = [];
  if (isset($d['discountType']) && isset($c['code']))          { $bentuk = 'coupon-objek'; $kode[] = $c['code']; }
  elseif (isset($d['discountType']) && isset($c[0]['code']))   { $bentuk = 'coupon-array'; foreach ($c as $x) $kode[] = $x['code']; }
  elseif (isset($d[0]['discountType']) && isset($c['code']))   { $bentuk = 'diskon-array'; $kode[] = $c['code']; }
  elseif (isset($d[0]['coupon'][0]['code']))                   { $bentuk = 'bersarang';    foreach ($d[0]['coupon'] as $x) $kode[] = $x['code']; }
  if ($bentuk !== $terima) {
    http_response_code(400);
    echo json_encode(['statusCode' => 400, 'messages' => 'Validation Error',
      'error' => ['field' => 'coupon', 'diterima' => $bentuk]]); exit;
  }
  // Kode yang "sudah diambil merchant lain": Mayar membalas 400 Already used.
  $dipakai = array_filter(array_map('trim', explode(',', (string)@file_get_contents($dir . '/mock.dipakai'))));
  foreach ($kode as $k) {
    if (in_array($k, $dipakai, true)) {
      http_response_code(400);
      echo json_encode(['statusCode' => 400, 'messages' => 'Already used', 'data' => null]); exit;
    }
  }
  if ($mode === 'tanpakode') $kode = [];   // 2xx tapi tanpa kode = diskon sampah
  $cs = [];
  foreach ($kode as $k) $cs[] = ['code' => $k, 'type' => 'reusable', 'isActive' => true];
  $id = 'disc-' . substr(md5(implode(',', $kode)), 0, 8);
  // Simpan supaya GET /coupons bisa menemukannya lagi — itu yang dipakai panel untuk
  // mengangkat diskon yatim.
  $simpan = json_decode((string)@file_get_contents($dir . '/mock.db.json'), true) ?: [];
  $simpan[] = ['id' => $id, 'name' => (string)($j['name'] ?? ''), 'totalUsage' => 0];
  file_put_contents($dir . '/mock.db.json', json_encode($simpan));
  echo json_encode(['statusCode' => 200, 'data' => ['id' => $id, 'coupons' => $cs]]); exit;
}
// Daftar kampanye (v2). Panel memakainya untuk mencari diskon yang sudah ada.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/coupons') {
  $cari = (string)($_GET['search'] ?? '');
  $simpan = json_decode((string)@file_get_contents($dir . '/mock.db.json'), true) ?: [];
  $hasil = [];
  foreach ($simpan as $r) {
    if ($cari === '' || stripos((string)$r['name'], $cari) !== false) {
      $hasil[] = ['id' => $r['id'], 'name' => $r['name'], 'status' => 'active',
        'totalUsage' => (int)$r['totalUsage'], 'products' => []];
    }
  }
  echo json_encode(['statusCode' => 200, 'data' => ['coupons' => $hasil], 'hasMore' => false]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($path, '/coupon/') === 0) {
  // kuota & status sengaja BEDA dari yang dikirim saat membuat, supaya terbukti
  // angka yang tampil di panel benar-benar datang dari Mayar, bukan dari catatan lokal.
  echo json_encode(['statusCode' => 200, 'data' => [
    'id' => substr($path, 8), 'totalCoupons' => 7, 'coupons' => [['isActive' => false]],
  ]]); exit;
}
http_response_code(404); echo json_encode(['statusCode' => 404, 'messages' => 'tidak ada']);
PHP
echo 'ok' > "$T/mock.mode"; : > "$T/mock.log"; : > "$T/mock.dipakai"; echo '[]' > "$T/mock.db.json"

echo "== basis API =="
# Yang paling penting dari RF_MAYAR_BASE: tanpa env itu, produksi WAJIB tetap ke
# api.mayar.id. Diperiksa di proses PHP terpisah yang env-nya sengaja dibersihkan.
b=$(cd "$T" && env -u RF_MAYAR_BASE php -r 'require "config.php"; require "lib.php"; echo mayarBase();')
[ "$b" = "https://api.mayar.id/hl/v1" ] && ok "tanpa env, basis tetap Mayar produksi" || no "basis produksi berubah: $b"
b=$(cd "$T" && RF_MAYAR_BASE="http://127.0.0.1:1" php -r 'require "config.php"; require "lib.php"; echo mayarBase();')
[ "$b" = "http://127.0.0.1:1" ] && ok "env hanya berlaku kalau memang diisi" || no "override tidak bekerja: $b"

echo "== sintaks =="
for f in api.php lib.php; do
  if php -l "$T/$f" >/dev/null 2>&1; then ok "sintaks $f"; else no "sintaks $f"; php -l "$T/$f"; fi
done

echo "== server =="
(cd "$T" && php -S "127.0.0.1:$MPORT" mock-mayar.php >/dev/null 2>&1) & MSRV=$!
(cd "$T" && RF_MAYAR_BASE="$MOCK" php -S "127.0.0.1:$PORT" >/dev/null 2>&1) & SRV=$!
for i in $(seq 1 40); do curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && break; sleep 0.3; done
for i in $(seq 1 40); do curl -s "$MOCK/coupon/apa" >/dev/null 2>&1 && break; sleep 0.3; done
: > "$T/mock.log"
curl -s "$BASE/api.php?a=me" >/dev/null 2>&1 && ok "server aplikasi hidup" || no "server aplikasi tidak hidup"

csrf(){ curl -s -c "$1" -b "$1" "$BASE/api.php?a=me" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"] ?? "";'; }
postb(){ local c; c=$(csrf "$1"); curl -s -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
postc(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
login(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=login" -d "{\"username\":\"$2\",\"code\":\"$3\"}"; }
jq1(){ php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; echo is_array($d)&&isset($d[$k])?(is_bool($d[$k])?($d[$k]?"true":"false"):(string)$d[$k]):"";' "$1"; }
EXP="$(php -r 'echo gmdate("Y-m-d", time()+7*86400);')"
KEMARIN="$(php -r 'echo gmdate("Y-m-d", time()-86400);')"

(cd "$T" && php -r 'require "config.php"; require "lib.php";
saveMember(["username"=>"bos","name"=>"Bos","code_hash"=>password_hash("RF-BOSS-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"bos@contoh.com","phone"=>"","role"=>"super_admin","avatar"=>""]);
saveMember(["username"=>"adminbiasa","name"=>"Admin Biasa","code_hash"=>password_hash("RF-BIASA-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"biasa@contoh.com","phone"=>"","role"=>"admin","avatar"=>""]);')
S="$T/jarS"; A="$T/jarA"
login "$S" "bos" "RF-BOSS-0001"
login "$A" "adminbiasa" "RF-BIASA-0001"

echo "== hak akses =="
for ep in voucher_create voucher_sync; do
  code=$(postc "$A" "$ep" "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"$EXP\"}")
  [ "$code" = "403" ] && ok "admin biasa ditolak di $ep (403)" || no "$ep untuk admin biasa seharusnya 403, dapat $code"
done

echo "== tanpa API key =="
r=$(postb "$S" voucher_create "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"$EXP\"}")
echo "$r" | grep -qi "API key Mayar belum diisi" && ok "tanpa API key ditolak dengan pesan jelas" || no "pesan tanpa key salah: $r"
[ ! -s "$T/mock.log" ] && ok "tanpa API key tidak ada panggilan ke Mayar" || no "seharusnya tidak memanggil Mayar"
r=$(curl -s -b "$S" "$BASE/api.php?a=vouchers")
echo "$r" | grep -q '"hasMayarKey":false' && ok "panel tahu key belum ada" || no "hasMayarKey seharusnya false: $r"

(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("mayar_api_key","KEY-UJI-PALSU");')
r=$(curl -s -b "$S" "$BASE/api.php?a=vouchers")
echo "$r" | grep -q '"hasMayarKey":true' && ok "panel tahu key sudah ada" || no "hasMayarKey seharusnya true"

echo "== validasi masukan (tidak boleh menyentuh Mayar) =="
: > "$T/mock.log"
tolak(){ local d="$1" nama="$2"; local c; c=$(postc "$S" voucher_create "$d");
  [ "$c" != "200" ] && ok "ditolak: $nama" || no "seharusnya ditolak: $nama"; }
tolak "{\"pct\":99,\"code\":\"UJICOBA99\",\"quota\":1,\"expires\":\"$EXP\"}"      "tingkat di luar 10-90"
tolak "{\"pct\":10,\"code\":\"ab\",\"quota\":1,\"expires\":\"$EXP\"}"             "kode terlalu pendek"
tolak "{\"pct\":10,\"code\":\"KODE<SCRIPT>\",\"quota\":1,\"expires\":\"$EXP\"}"   "kode berkarakter aneh"
tolak "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":0,\"expires\":\"$EXP\"}"      "kuota 0"
tolak "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":100001,\"expires\":\"$EXP\"}" "kuota di atas batas"
tolak "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"$KEMARIN\"}"  "kedaluwarsa sudah lewat"
tolak "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"besok\"}"     "format tanggal salah"
tolak "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":1}"                           "tanggal kosong"
[ ! -s "$T/mock.log" ] && ok "semua penolakan terjadi sebelum Mayar dipanggil" || no "ada panggilan Mayar saat masukan tidak sah: $(cat "$T/mock.log")"

echo "== buat kupon (jalur utama) =="
: > "$T/mock.log"
r=$(postb "$S" voucher_create "{\"pct\":10,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"$EXP\",\"note\":\"uji\",\"onetime\":true}")
echo "$r" | grep -q '"ok":true' && ok "voucher_create berhasil" || no "voucher_create gagal: $r"
echo "$r" | grep -q '"diMayar":true' && ok "panel menandai kupon benar-benar dibuat di Mayar" || no "diMayar seharusnya true: $r"
echo "$r" | grep -q '"code":"UJICOBA10"' && ok "kode tersimpan" || no "kode tidak tersimpan: $r"
mid=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT mayar_id FROM vouchers WHERE pct=10"); $s->execute(); echo (string)$s->fetchColumn();')
case "$mid" in disc-*) ok "id diskon dari Mayar tersimpan ($mid)" ;; *) no "mayar_id salah: '$mid'" ;; esac
shape=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; echo setting("mayar_coupon_shape");')
[ "$shape" = "coupon-objek" ] && ok "bentuk payload yang diterima diingat ($shape)" || no "bentuk tidak tersimpan: '$shape'"

echo "== bentuk permintaan ke Mayar =="
L="$(cat "$T/mock.log")"
echo "$L" | grep -q '"method":"POST"' && ok "memakai POST" || no "metode salah"
echo "$L" | grep -q '"path":"/coupon/create"' && ok "menuju /coupon/create" || no "path salah: $L"
echo "$L" | grep -q 'Bearer KEY-UJI-PALSU' && ok "membawa Authorization: Bearer" || no "header auth salah"
echo "$L" | grep -q 'application/json' && ok "Content-Type JSON" || no "content-type salah"
B="$(php -r '$b=""; foreach(file($argv[1]) as $l){$j=json_decode($l,true); if(($j["path"]??"")==="/coupon/create") $b=$j["body"]??"";} echo $b;' "$T/mock.log")"
echo "$B" | grep -q '"discountType":"percentage"' && ok "tipe diskon percentage" || no "discountType salah: $B"
echo "$B" | grep -q '"value":10'          && ok "persentase 10 terkirim"          || no "value salah: $B"
echo "$B" | grep -q '"totalCoupons":1'    && ok "kuota terkirim sebagai totalCoupons" || no "totalCoupons salah: $B"
echo "$B" | grep -q '"code":"UJICOBA10"'  && ok "kode terkirim"                    || no "code salah: $B"
echo "$B" | grep -q '"type":"onetime"'    && ok "onetime diteruskan"               || no "type salah: $B"
echo "$B" | grep -q "\"expiredAt\":\"${EXP}T23:59:59.000Z\"" && ok "kedaluwarsa dikirim lengkap dengan jam" || no "expiredAt salah: $B"
# Bentuk ini mengikuti contoh curl di docs.mayar.id/api-reference/discount/create:
# discount sebuah OBJEK, sementara coupon dan products sejajar dengannya di tingkat atas.
echo "$B" | grep -q '"discount":{"discountType"' && ok "discount dikirim sebagai objek, bukan array" || no "bentuk discount salah: $B"
echo "$B" | grep -q '"coupon":{"code"' && ok "coupon sejajar discount dan berbentuk objek" || no "bentuk coupon salah: $B"
echo "$B" | grep -q '"products":\[\]' && ok "products kosong = berlaku semua produk" || no "products salah: $B"

echo "== pengaman kupon kembar =="
code=$(postc "$S" voucher_create "{\"pct\":10,\"code\":\"UJILAIN10\",\"quota\":1,\"expires\":\"$EXP\"}")
[ "$code" != "200" ] && ok "tingkat yang sudah punya kupon tidak bisa dibuat ulang" || no "seharusnya ditolak, dapat $code"
code=$(postc "$S" voucher_create "{\"pct\":20,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"$EXP\"}")
[ "$code" != "200" ] && ok "kode sama di tingkat lain ditolak" || no "kode kembar seharusnya ditolak"

echo "== mencari bentuk payload yang diterima =="
# Mayar hanya mengakui bentuk 'bersarang' kali ini. Panel harus mencobanya berurutan,
# dan percobaan yang ditolak tidak boleh membuat apa pun.
(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("mayar_coupon_shape", "");')
echo 'bersarang' > "$T/mock.bentuk"
: > "$T/mock.log"
r=$(postb "$S" voucher_create "{\"codes\":\"COBA80\",\"quota\":3,\"expires\":\"$EXP\"}")
echo "$r" | grep -q '"ok":true' && ok "bentuk ketemu setelah beberapa percobaan" || no "gagal menemukan bentuk: $r"
n=$(grep -c '"path":"/coupon/create"' "$T/mock.log")
[ "$n" -gt 1 ] && ok "beberapa bentuk dicoba berurutan ($n percobaan)" || no "seharusnya lebih dari satu percobaan, dapat $n"
shape=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; echo setting("mayar_coupon_shape");')
[ "$shape" = "bersarang" ] && ok "bentuk yang berhasil diingat ($shape)" || no "bentuk tidak diingat: '$shape'"

echo "== semua bentuk ditolak =="
(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("mayar_coupon_shape", "");')
echo 'tidak-ada-yang-cocok' > "$T/mock.bentuk"
r=$(postb "$S" voucher_create "{\"codes\":\"COBA50\",\"quota\":3,\"expires\":\"$EXP\"}")
echo "$r" | grep -q 'Semua bentuk payload ditolak' && ok "ditolak dengan pesan yang menyebutkan sebabnya" || no "pesan tidak jelas: $r"
echo "$r" | grep -q 'Validation Error' && ok "rincian penolakan Mayar ikut diteruskan" || no "rincian Mayar hilang: $r"
kosong=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT code FROM vouchers WHERE pct=50"); $s->execute(); echo (string)$s->fetchColumn();')
[ -z "$kosong" ] && ok "tingkat tetap kosong setelah semua bentuk ditolak" || no "tingkat malah terisi: '$kosong'"

echo "== Mayar menjawab 2xx tapi tanpa kode =="
echo 'coupon-objek' > "$T/mock.bentuk"
(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("mayar_coupon_shape", "coupon-objek");')
echo 'tanpakode' > "$T/mock.mode"
r=$(postb "$S" voucher_create "{\"codes\":\"COBA20\",\"quota\":3,\"expires\":\"$EXP\"}")
echo "$r" | grep -q 'tidak mengembalikan kode' && ok "diskon tanpa kode dilaporkan, bukan disimpan diam-diam" || no "pesan salah: $r"
kosong=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT code FROM vouchers WHERE pct=20"); $s->execute(); echo (string)$s->fetchColumn();')
[ -z "$kosong" ] && ok "tingkat tidak terisi oleh diskon tanpa kode" || no "tingkat malah terisi: '$kosong'"
echo 'ok' > "$T/mock.mode"

echo "== tingkat dibaca dari dua angka terakhir kode =="
tolak "{\"codes\":\"HEMAT45\",\"quota\":1,\"expires\":\"$EXP\"}"          "akhiran bukan tingkat (45)"
tolak "{\"codes\":\"HEMAT100\",\"quota\":1,\"expires\":\"$EXP\"}"         "akhiran 00 bukan tingkat"
tolak "{\"codes\":\"RFKODE\",\"quota\":1,\"expires\":\"$EXP\"}"           "kode tanpa angka di belakang"
tolak "{\"codes\":\"HEMAT30, DISKON40\",\"quota\":1,\"expires\":\"$EXP\"}" "dua kode beda tingkat"
tolak "{\"pct\":20,\"codes\":\"HEMAT30\",\"quota\":1,\"expires\":\"$EXP\"}" "kode 30 dipasang di tingkat 20"

echo "== satu tingkat, banyak alias = satu diskon per alias =="
: > "$T/mock.log"
r=$(postb "$S" voucher_create "{\"codes\":\"hemat60, diskon60 promo60\",\"quota\":100,\"expires\":\"$EXP\"}")
echo "$r" | grep -q '"ok":true' && ok "tiga alias dibuat sekaligus" || no "gagal: $r"
echo "$r" | grep -q '"pct":60' && ok "tingkat 60% disimpulkan dari kodenya sendiri" || no "tingkat salah: $r"
n=$(grep -c '"path":"/coupon/create"' "$T/mock.log")
[ "$n" = "3" ] && ok "tiga permintaan terpisah, satu per alias" || no "seharusnya 3 permintaan, dapat $n"
ALL="$(php -r '$o=""; foreach(file($argv[1]) as $l){$j=json_decode($l,true); if(($j["path"]??"")==="/coupon/create") $o.=($j["body"]??"")."
";} echo $o;' "$T/mock.log")"
echo "$ALL" | grep -q '"code":"HEMAT60"'  && ok "alias 1 terkirim huruf besar"  || no "HEMAT60 tidak terkirim"
echo "$ALL" | grep -q '"code":"DISKON60"' && ok "alias 2 terkirim"              || no "DISKON60 tidak terkirim"
echo "$ALL" | grep -q '"code":"PROMO60"'  && ok "alias 3 terkirim"              || no "PROMO60 tidak terkirim"
echo "$r" | grep -q '"codes":\["HEMAT60","DISKON60","PROMO60"\]' && ok "ketiganya tersimpan di panel" || no "daftar kode salah: $r"
ids=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT mayar_ids FROM vouchers WHERE pct=60"); $s->execute(); echo (string)$s->fetchColumn();')
[ "$(echo "$ids" | grep -o 'disc-' | wc -l | tr -d " ")" = "3" ] && ok "tiga id diskon tersimpan ($ids)" || no "mayar_ids salah: $ids"
code=$(postc "$S" voucher_create "{\"codes\":\"PROMO60\",\"quota\":1,\"expires\":\"$EXP\"}")
[ "$code" != "200" ] && ok "alias yang sudah dipakai tingkat lain ditolak" || no "seharusnya ditolak, dapat $code"

echo "== kode yang sudah diambil merchant lain =="
# Mayar membalas "Already used" walau kodenya belum pernah kita buat: kode kupon di
# sana unik lintas merchant. Pesannya harus menjelaskan itu, bukan meneruskan apa adanya.
echo 'RFDIPAKAI30' > "$T/mock.dipakai"
r=$(postb "$S" voucher_create "{\"codes\":\"RFDIPAKAI30\",\"quota\":5,\"expires\":\"$EXP\"}")
echo "$r" | grep -q 'unik untuk SEMUA merchant' && ok "Already used dijelaskan sebagai kode bentrok lintas merchant" || no "pesan tidak membantu: $r"
: > "$T/mock.dipakai"

echo "== sebagian alias gagal, sisanya tetap tersimpan =="
# Ini kesalahan yang paling mahal pada API tanpa endpoint hapus: alias pertama sudah
# terbentuk di Mayar, alias kedua ditolak, lalu panel membuang id yang pertama.
echo 'RFDUA70' > "$T/mock.dipakai"
r=$(postb "$S" voucher_create "{\"codes\":\"RFSATU70, RFDUA70\",\"quota\":5,\"expires\":\"$EXP\"}")
echo "$r" | grep -q '"ok":true' && ok "yang berhasil tetap dilaporkan sukses" || no "seharusnya sukses sebagian: $r"
echo "$r" | grep -q '"codes":\["RFSATU70"\]' && ok "alias yang berhasil tersimpan" || no "alias hilang: $r"
echo "$r" | grep -q '"warning":"' && ok "kegagalan sebagian ikut dilaporkan" || no "peringatan hilang: $r"
mid70=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT mayar_id FROM vouchers WHERE pct=70"); $s->execute(); echo (string)$s->fetchColumn();')
case "$mid70" in disc-*) ok "id diskon yang terlanjur dibuat TIDAK hilang ($mid70)" ;; *) no "id hilang: '$mid70'" ;; esac
: > "$T/mock.dipakai"

echo "== diskon yatim diangkat, bukan dibuat kembar =="
# Diskon sudah ada di Mayar tapi panel tidak mencatatnya. Menekan buat sekali lagi
# harus mengambil id-nya, bukan membuat diskon kedua dengan kode yang sama.
(cd "$T" && php -r 'require "config.php"; require "lib.php";
db()->prepare("UPDATE vouchers SET code=\x27\x27, codes=\x27\x27, mayar_id=\x27\x27, mayar_ids=\x27\x27, active=0 WHERE pct=40")->execute();')
php -r '$f=$argv[1]; $d=json_decode(file_get_contents($f),true)?:[];
$d[]=["id"=>"disc-yatim-40","name"=>"ResepFoto 40% - RFYATIM40","totalUsage"=>12];
file_put_contents($f, json_encode($d));' "$T/mock.db.json"
: > "$T/mock.log"
r=$(postb "$S" voucher_create "{\"codes\":\"RFYATIM40\",\"quota\":5,\"expires\":\"$EXP\"}")
echo "$r" | grep -q '"ok":true' && ok "diskon yatim berhasil diangkat" || no "gagal: $r"
n=$(grep -c '"path":"/coupon/create"' "$T/mock.log" || true)
[ "$n" = "0" ] && ok "tidak ada permintaan buat baru — id lama yang dipakai" || no "malah membuat baru ($n kali)"
mid40=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT mayar_id FROM vouchers WHERE pct=40"); $s->execute(); echo (string)$s->fetchColumn();')
[ "$mid40" = "disc-yatim-40" ] && ok "id dari Mayar tersimpan ($mid40)" || no "id salah: '$mid40'"
r=$(postb "$S" voucher_sync '{"pct":40}')
echo "$r" | grep -q '"used":12' && ok "jumlah pemakaian dibaca dari daftar Mayar" || no "used tidak terbaca: $r"

echo "== baca balik status dari Mayar =="
: > "$T/mock.log"
r=$(postb "$S" voucher_sync '{"pct":10}')
echo "$r" | grep -q '"ok":true' && ok "voucher_sync berhasil" || no "voucher_sync gagal: $r"
L="$(cat "$T/mock.log")"
echo "$L" | grep -q '"method":"GET"' && ok "sync memakai GET" || no "metode sync salah: $L"
echo "$L" | grep -q '"path":"/coupon/disc-' && ok "sync memakai id, bukan kode" || no "path sync salah: $L"
echo "$r" | grep -q '"quota":7' && ok "kuota diperbarui dari jawaban Mayar (1 -> 7)" || no "kuota tidak ikut jawaban Mayar: $r"
echo "$r" | grep -q '"active":false' && ok "status aktif diambil dari Mayar" || no "isActive tidak terbaca: $r"
echo "$r" | grep -q '"syncedAt":""' && no "syncedAt tidak terisi" || ok "syncedAt terisi"
code=$(postc "$S" voucher_sync '{"pct":30}')
[ "$code" != "200" ] && ok "sync tingkat tanpa kupon ditolak" || no "seharusnya ditolak, dapat $code"

echo "== Mayar menolak / menjawab aneh =="
echo '401' > "$T/mock.mode"
r=$(postb "$S" voucher_sync '{"pct":10}')
echo "$r" | grep -qi "Read & Write" && ok "401 dijelaskan sebagai key kurang hak" || no "pesan 401 kurang jelas: $r"
echo 'sampah' > "$T/mock.mode"
r=$(postb "$S" voucher_sync '{"pct":10}')
echo "$r" | grep -qi "tidak dikenali" && ok "jawaban bukan JSON ditangani" || no "jawaban sampah tidak ditangani: $r"
echo 'ok' > "$T/mock.mode"
r=$(curl -s -b "$S" "$BASE/api.php?a=vouchers")
echo "$r" | grep -q '"code":"UJICOBA10"' && ok "kupon tetap utuh setelah dua error" || no "catatan rusak setelah error: $r"

echo "== kosongkan catatan =="
: > "$T/mock.log"
code=$(postc "$S" voucher_delete '{"pct":10}')
[ "$code" = "200" ] && ok "voucher_delete berhasil" || no "voucher_delete gagal, dapat $code"
[ ! -s "$T/mock.log" ] && ok "delete tidak memanggil Mayar (API Mayar memang tak punya hapus)" || no "delete seharusnya lokal saja"
mid=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT mayar_id FROM vouchers WHERE pct=10"); $s->execute(); echo (string)$s->fetchColumn();')
[ -z "$mid" ] && ok "mayar_id ikut dikosongkan" || no "mayar_id masih ada: $mid"
code=$(postc "$S" voucher_create "{\"pct\":10,\"code\":\"UJIBARU10\",\"quota\":2,\"expires\":\"$EXP\"}")
[ "$code" = "200" ] && ok "tingkat bisa dipakai lagi setelah dikosongkan" || no "tingkat terkunci setelah delete, dapat $code"

echo
echo "HASIL: $pass lulus, $fail gagal"
[ "$fail" -eq 0 ]
