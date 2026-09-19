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
  echo json_encode(['statusCode' => 200, 'data' => ['id' => 'disc-uji-abc123']]); exit;
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
echo 'ok' > "$T/mock.mode"; : > "$T/mock.log"

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
[ "$mid" = "disc-uji-abc123" ] && ok "id diskon dari Mayar tersimpan ($mid)" || no "mayar_id salah: '$mid'"

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
echo "$B" | grep -q "\"expiredAt\":\"${EXP}T23:59:59Z\"" && ok "kedaluwarsa dikirim lengkap dengan jam" || no "expiredAt salah: $B"

echo "== pengaman kupon kembar =="
code=$(postc "$S" voucher_create "{\"pct\":10,\"code\":\"UJILAIN10\",\"quota\":1,\"expires\":\"$EXP\"}")
[ "$code" != "200" ] && ok "tingkat yang sudah punya kupon tidak bisa dibuat ulang" || no "seharusnya ditolak, dapat $code"
code=$(postc "$S" voucher_create "{\"pct\":20,\"code\":\"UJICOBA10\",\"quota\":1,\"expires\":\"$EXP\"}")
[ "$code" != "200" ] && ok "kode sama di tingkat lain ditolak" || no "kode kembar seharusnya ditolak"

echo "== baca balik status dari Mayar =="
: > "$T/mock.log"
r=$(postb "$S" voucher_sync '{"pct":10}')
echo "$r" | grep -q '"ok":true' && ok "voucher_sync berhasil" || no "voucher_sync gagal: $r"
L="$(cat "$T/mock.log")"
echo "$L" | grep -q '"method":"GET"' && ok "sync memakai GET" || no "metode sync salah: $L"
echo "$L" | grep -q '"path":"/coupon/disc-uji-abc123"' && ok "sync memakai id, bukan kode" || no "path sync salah: $L"
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
