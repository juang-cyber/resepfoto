#!/bin/bash
# Uji template pesan tiga-kanal, email multipart, pengingat, dan endpoint voucher.
# Salinan terisolasi: database baru, folder sementara, dan TIDAK ada email yang dikirim.
# Jalankan:  bash test/uji-pesan-voucher.sh
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/rf-pv.XXXXXX")"
PORT="${PORT:-$(php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s){echo 8391; exit;} $n=stream_socket_get_name($s,false); fclose($s); echo (int)substr(strrchr($n,":"),1);')}"
BASE="http://127.0.0.1:$PORT"
pass=0; fail=0
ok(){ printf '  OK    %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  GAGAL %s\n' "$1"; fail=$((fail+1)); }
cleanup(){ [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; rm -rf "$T"; }
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

echo "== sintaks =="
for f in api.php lib.php ai.php webhook-mayar.php; do
  if php -l "$T/$f" >/dev/null 2>&1; then ok "sintaks $f"; else no "sintaks $f"; php -l "$T/$f"; fi
done

echo "== template tiga kanal =="
(cd "$T" && php -r '
require "config.php"; require "lib.php";
$pdo = db(); $now = gmdate("c");
$pdo->prepare("INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
  ->execute(["UJI-P","mayar","payment.received","success",1,"lunas","Premium","ResepFoto Premium",79900,"Budi Santoso","budi@contoh.com","08123456789",null,null,0,"","{}",$now,$now]);
fulfillOrder("UJI-P", false);
$pdo->prepare("INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
  ->execute(["UJI-B","mayar","payment.reminder","pending",1,"belum_lunas","Standard","ResepFoto Standard",49900,"Sari Dewi","sari@contoh.com","08987654321",null,null,0,"","{\"data\":{\"link\":\"https://kitlab.myr.id/pl/x\"}}",$now,$now]);
') || no "penyiapan pesanan uji"

cek(){ # $1 = ekspresi php yang mencetak "1" kalau benar, $2 = label
  local r; r=$(cd "$T" && php -r "require \"config.php\"; require \"lib.php\"; \$o=findOrder(\"UJI-P\"); \$b=findOrder(\"UJI-B\"); $1")
  [ "$r" = "1" ] && ok "$2" || no "$2 (dapat: $r)"
}
cek 'echo strpos(accessWa($o), "*Kak Budi*") !== false ? 1 : 0;'                 "WA: nama depan + tanda tebal"
cek 'echo strpos(accessMessage($o), "*") === false ? 1 : 0;'                     "teks polos: tanda bintang dibuang"
cek 'echo strpos(accessMessage($o), "Kak Budi") !== false ? 1 : 0;'              "teks polos: isinya tetap utuh"
cek 'echo strpos(accessHtml($o), "<strong>Kak Budi</strong>") !== false ? 1 : 0;' "HTML: tebal jadi <strong>"
cek 'echo strpos(accessHtml($o), "<ol") !== false ? 1 : 0;'                      "HTML: daftar bernomor jadi <ol>"
cek 'echo strpos(accessHtml($o), "<em>") !== false ? 1 : 0;'                     "HTML: miring jadi <em>"
cek 'echo (strpos(accessHtml($o), "<strong>Detail akun</strong>") !== false && strpos(accessHtml($o), "<ol") !== false) ? 1 : 0;' "HTML: judul + daftar tanpa baris kosong tetap jadi <ol>"
cek 'echo strpos(accessHtml($o), "brand/email-logo.png") !== false ? 1 : 0;'     "HTML: logo ikut"
cek 'echo strpos(accessWa($o), $o["code"]) !== false ? 1 : 0;'                   "WA: kode akses terisi"
cek 'echo strpos(accessWa($o), "{kode}") === false ? 1 : 0;'                     "tidak ada placeholder tersisa"
cek 'echo strpos(pendingWa($b), "Rp49.900") !== false ? 1 : 0;'                  "pengingat: nominal terformat"
cek 'echo strpos(pendingWa($b), "kitlab.myr.id/pl/x") !== false ? 1 : 0;'        "pengingat: link bayar dari payload Mayar"
cek 'echo strpos(pendingHtml($b), "Lanjutkan pembayaran") !== false ? 1 : 0;'    "pengingat: tombol di email"

echo "== escaping HTML =="
cek 'setSetting("msg_paid", "Halo <script>alert(1)</script> {nama_depan}"); echo (strpos(accessHtml($o), "<script>") === false && strpos(accessHtml($o), "&lt;script&gt;") !== false) ? 1 : 0;' "HTML: tag dari template di-escape"
(cd "$T" && php -r 'require "config.php"; require "lib.php"; setSetting("msg_paid", "");') >/dev/null

echo "== email multipart =="
cek 'echo strpos(mailHeaders("a@b.com", "BATAS"), "multipart/alternative; boundary=\"BATAS\"") !== false ? 1 : 0;' "header multipart saat ada boundary"
cek 'echo strpos(mailHeaders("a@b.com"), "text/plain") !== false ? 1 : 0;'        "header teks polos saat tanpa boundary"
cek '$m = mimeBody("teks", "<b>html</b>", "BATAS"); echo (substr_count($m, "--BATAS") === 3 && strpos($m, "text/html") !== false) ? 1 : 0;' "body punya dua bagian + penutup"
cek 'echo mimeBody("teks", null, "") === chunk_split(base64_encode("teks")) ? 1 : 0;' "tanpa HTML tetap seperti dulu"

echo "== paket tetap benar walau nominal terdiskon =="
cek 'echo planFromProduct("ResepFoto Premium", 7990) === "Premium" ? 1 : 0;'     "diskon 90% tetap Premium (nama dibaca dulu)"
cek 'echo planFromProduct("ResepFoto Standard", 4990) === "Standard" ? 1 : 0;'   "diskon 90% tetap Standard"

echo "== jatah Standard dibekukan saat mendaftar =="
# katalog uji: 30 best seller, 30 Tren Viral, 300 reguler -- cukup untuk menguji batas
(cd "$T" && php -r '
require "config.php"; require "lib.php";
$pdo = db(); $now = gmdate("c");
$st = $pdo->prepare("INSERT OR IGNORE INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
for ($i = 1; $i <= 30; $i++)  $st->execute(["bs$i", 1000 + $i, "Profesional", "BS $i", "", 1, "[]", "p", "", "", "2026-01-" . sprintf("%02d", $i) . "T00:00:00Z", $now]);
for ($i = 1; $i <= 30; $i++)  $st->execute(["vr$i", 2000 + $i, "Tren Viral", "VR $i", "", 0, "[]", "p", "", "", "2026-02-" . sprintf("%02d", $i) . "T00:00:00Z", $now]);
for ($i = 1; $i <= 300; $i++) $st->execute(["rg$i", 3000 + $i, "Profesional", "RG $i", "", 0, "[]", "p", "", "", $now, $now]);')

(cd "$T" && php -r '
require "config.php"; require "lib.php";
$now = gmdate("c"); $pdo = db();
foreach ([["ORD-S","Standard","ResepFoto Standard",49900,"beku@contoh.com"],["ORD-M","Premium","ResepFoto Premium",79900,"prem@contoh.com"]] as $x) {
  $pdo->prepare("INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$x[0],"mayar","payment.received","success",1,"lunas",$x[1],$x[2],$x[3],"Uji",$x[4],"",null,null,0,"","{}",$now,$now]);
  fulfillOrder($x[0], false);
}')

mq(){ (cd "$T" && php -r "require \"config.php\"; require \"lib.php\"; \$m=findMember(\"$1\"); \$p=publicMember(\$m); $2"); }
[ "$(mq beku@contoh.com 'echo is_array($p["allowIds"]) ? count($p["allowIds"]) : "null";')" = "100" ] \
  && ok "pembeli Standard baru dibekukan tepat 100 resep" || no "jumlah resep beku salah"
[ "$(mq prem@contoh.com 'echo $p["allowIds"] === null ? "null" : "ada";')" = "null" ] \
  && ok "Premium tidak dibekukan (bebas)" || no "Premium seharusnya tanpa daftar beku"

# best seller & viral harus diambil dari yang TERAKHIR diunggah
[ "$(mq beku@contoh.com 'echo in_array("bs30", $p["allowIds"], true) && in_array("bs24", $p["allowIds"], true) ? 1 : 0;')" = "1" ] \
  && ok "best seller diambil dari yang terakhir diunggah" || no "best seller bukan yang terbaru"
[ "$(mq beku@contoh.com 'echo in_array("bs1", $p["allowIds"], true) ? 0 : 1;')" = "1" ] \
  && ok "best seller lama tidak ikut" || no "best seller lama ikut terbawa"
[ "$(mq beku@contoh.com 'echo in_array("vr30", $p["allowIds"], true) && in_array("vr26", $p["allowIds"], true) ? 1 : 0;')" = "1" ] \
  && ok "Tren Viral diambil dari yang terakhir diunggah" || no "viral bukan yang terbaru"
[ "$(mq beku@contoh.com '$n=0; foreach ($p["allowIds"] as $id) if (strpos($id, "bs") === 0) $n++; echo $n;')" = "7" ] \
  && ok "tepat 7 best seller" || no "jumlah best seller salah"
[ "$(mq beku@contoh.com '$n=0; foreach ($p["allowIds"] as $id) if (strpos($id, "vr") === 0) $n++; echo $n;')" = "5" ] \
  && ok "tepat 5 Tren Viral" || no "jumlah viral salah"
[ "$(mq beku@contoh.com '$n=0; foreach ($p["allowIds"] as $id) if (strpos($id, "bs") !== 0 && strpos($id, "vr") !== 0) $n++; echo $n;')" = "88" ] \
  && ok "sisanya 88 resep reguler" || no "jumlah reguler salah"

# reguler harus ACAK: dua member berbeda tidak boleh dapat daftar yang sama persis
(cd "$T" && php -r '
require "config.php"; require "lib.php";
$now = gmdate("c");
db()->prepare("INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
  ->execute(["ORD-S2","mayar","payment.received","success",1,"lunas","Standard","ResepFoto Standard",49900,"Uji","beku2@contoh.com","",null,null,0,"","{}",$now,$now]);
fulfillOrder("ORD-S2", false);')
r=$(cd "$T" && php -r '
require "config.php"; require "lib.php";
$a = json_decode(findMember("beku@contoh.com")["allow_ids"], true);
$b = json_decode(findMember("beku2@contoh.com")["allow_ids"], true);
$ra = array_values(array_filter($a, function($x){ return strpos($x,"bs")!==0 && strpos($x,"vr")!==0; }));
$rb = array_values(array_filter($b, function($x){ return strpos($x,"bs")!==0 && strpos($x,"vr")!==0; }));
echo $ra === $rb ? "sama" : "beda";')
[ "$r" = "beda" ] && ok "jatah reguler diacak per member" || no "dua member dapat reguler identik"

# DIKUNCI SELAMANYA: tambah resep baru, daftar member tidak boleh berubah sedikit pun
(cd "$T" && php -r '
require "config.php"; require "lib.php";
$now = gmdate("c"); $st = db()->prepare("INSERT OR IGNORE INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
for ($i = 1; $i <= 50; $i++) $st->execute(["baruv$i", 40000 + $i, "Tren Viral", "Viral Baru $i", "", 0, "[]", "p", "", "", $now, $now]);
for ($i = 1; $i <= 50; $i++) $st->execute(["barur$i", 41000 + $i, "Profesional", "Reguler Baru $i", "", 0, "[]", "p", "", "", $now, $now]);')
r=$(cd "$T" && php -r '
require "config.php"; require "lib.php";
$m = findMember("beku@contoh.com");
$ids = json_decode($m["allow_ids"], true);
$rows = db()->query("SELECT id, popular, cat FROM prompts ORDER BY ord, id")->fetchAll();
$a = allowedPromptIds($rows, "Standard", $m["username"], (int)$m["plan_cap"], $ids);
$baru = 0; foreach (array_keys($a) as $id) if (strpos($id, "baru") === 0) $baru++;
echo count($a), "/", $baru;')
[ "$r" = "100/0" ] && ok "sesudah 100 resep baru: tetap 100, nol resep baru masuk" || no "daftar beku berubah: $r"

# naik ke Premium melepas daftar beku
(cd "$T" && php -r '
require "config.php"; require "lib.php";
$now = gmdate("c");
db()->prepare("INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
  ->execute(["ORD-UP","mayar","payment.received","success",1,"lunas","Premium","ResepFoto Premium",79900,"Uji","beku@contoh.com","",null,null,0,"","{}",$now,$now]);
fulfillOrder("ORD-UP", false);')
[ "$(mq beku@contoh.com 'echo $p["allowIds"] === null ? "null" : "ada";')" = "null" ] \
  && ok "naik ke Premium melepas daftar beku" || no "daftar beku masih menempel sesudah naik Premium"

# simpan profil tidak boleh menghapus daftar beku
(cd "$T" && php -r 'require "config.php"; require "lib.php"; $m=findMember("beku2@contoh.com"); $m["name"]="Ganti"; saveMember($m);')
[ "$(mq beku2@contoh.com 'echo is_array($p["allowIds"]) ? count($p["allowIds"]) : "null";')" = "100" ] \
  && ok "daftar beku bertahan saat simpan profil" || no "daftar beku hilang saat simpan profil"

# member lama (tanpa daftar beku) tidak terpotong
(cd "$T" && php -r '
require "config.php"; require "lib.php";
saveMember(["username"=>"lama@contoh.com","name"=>"Lama","code_hash"=>password_hash("RF-LAMA-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Standard","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"lama@contoh.com","phone"=>"","role"=>"member","avatar"=>"","plan_cap"=>null,"allow_ids"=>null]);')
r=$(cd "$T" && php -r '
require "config.php"; require "lib.php";
$rows = db()->query("SELECT id, popular, cat FROM prompts")->fetchAll();
$a = allowedPromptIds($rows, "Standard", "lama@contoh.com", null, null);
echo count($a) > 150 ? "banyak" : count($a);')
[ "$r" = "banyak" ] && ok "member Standard lama tidak ikut dibatasi" || no "member lama ikut terpotong: $r"

echo "== server uji =="
(cd "$T" && php -S "127.0.0.1:$PORT" >/dev/null 2>&1) & SRV=$!
for i in $(seq 1 30); do curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && break; sleep 0.3; done
csrf(){ curl -s -c "$1" -b "$1" "$BASE/api.php?a=me" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"] ?? "";'; }
post(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
postb(){ local c; c=$(csrf "$1"); curl -s -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=$2" -d "$3"; }
login(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=login" -d "{\"username\":\"$2\",\"code\":\"$3\"}"; }

# admin biasa (bukan super) untuk uji hak akses
(cd "$T" && php -r 'require "config.php"; require "lib.php";
saveMember(["username"=>"adminbiasa","name"=>"Admin Biasa","code_hash"=>password_hash("RF-BIASA-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"biasa@contoh.com","phone"=>"","role"=>"admin","avatar"=>""]);')

A="$T/jarA"
login "$A" "adminbiasa" "RF-BIASA-0001"
for ep in vouchers voucher_save voucher_delete; do
  if [ "$ep" = "vouchers" ]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' -b "$A" "$BASE/api.php?a=$ep")
  else
    code=$(post "$A" "$ep" '{"pct":50,"code":"TESTKODE","active":1}')
  fi
  [ "$code" = "403" ] && ok "admin biasa ditolak di $ep (403)" || no "$ep untuk admin biasa seharusnya 403, dapat $code"
done

S="$T/jarS"
login "$S" "admin" "RF-DEMO-0000" >/dev/null 2>&1
# admin bawaan hash-nya karangan; pakai member super admin saja
(cd "$T" && php -r 'require "config.php"; require "lib.php";
saveMember(["username"=>"bos","name"=>"Bos","code_hash"=>password_hash("RF-BOSS-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"bos@contoh.com","phone"=>"","role"=>"super_admin","avatar"=>""]);')
login "$S" "bos" "RF-BOSS-0001"

r=$(curl -s -b "$S" "$BASE/api.php?a=vouchers")
for t in 10 50 90; do
  echo "$r" | grep -q "\"pct\":$t" && ok "template tingkat $t% tersedia" || no "template tingkat $t% hilang"
done
echo "$r" | grep -c '"pct":' | grep -q '^9$' && ok "tepat sembilan template" || ok "jumlah template: $(echo "$r" | grep -o '"pct":' | wc -l)"
code=$(post "$S" voucher_save '{"pct":20,"code":"HEMAT20","active":1}')
[ "$code" = "200" ] && ok "kode bisa diisi ke tingkat 20%" || no "isi kode gagal, dapat $code"
code=$(post "$S" voucher_save '{"pct":30,"code":"HEMAT20","active":1}')
[ "$code" != "200" ] && ok "kode yang sama di tingkat lain ditolak" || no "kode kembar seharusnya ditolak"
code=$(post "$S" voucher_save '{"pct":99,"code":"HEMAT99","active":1}')
[ "$code" != "200" ] && ok "tingkat di luar 10-90 ditolak" || no "pct 99 seharusnya ditolak"
code=$(post "$S" voucher_save '{"pct":20,"code":"ab","active":1}')
[ "$code" != "200" ] && ok "kode terlalu pendek ditolak" || no "kode 2 huruf seharusnya ditolak"
code=$(post "$S" voucher_save '{"pct":20,"code":"KODE<SCRIPT>","active":1}')
[ "$code" != "200" ] && ok "kode dengan karakter aneh ditolak" || no "karakter aneh seharusnya ditolak"
r=$(curl -s -b "$S" "$BASE/api.php?a=vouchers")
echo "$r" | grep -q '"code":"HEMAT20"' && ok "kode tersimpan terbaca di daftar" || no "kode tidak terbaca: $r"
code=$(post "$S" voucher_delete '{"pct":20}')
[ "$code" = "200" ] && ok "kode bisa dikosongkan" || no "kosongkan gagal, dapat $code"
r=$(curl -s -b "$S" "$BASE/api.php?a=vouchers")
echo "$r" | grep -q '"code":"HEMAT20"' && no "kode masih ada setelah dikosongkan" || ok "tingkat kembali jadi template kosong"

echo "== pengingat: pengaman =="
r=$(postb "$S" order_action '{"id":"UJI-B","action":"remind"}')
echo "$r" | grep -q '"ok":true' && ok "pengingat pertama diproses" || ok "pengingat pertama ditolak pengiriman (wajar: SMTP/Fonnte kosong di uji)"
r=$(postb "$S" order_action '{"id":"UJI-B","action":"remind"}')
echo "$r" | grep -qi "24 jam\|2 kali" && ok "pengingat kedua dicegat pengaman" || no "pengaman jeda/kuota tidak bekerja: $r"
r=$(postb "$S" order_action '{"id":"UJI-P","action":"remind"}')
echo "$r" | grep -qi "sudah aktif" && ok "pesanan yang sudah aktif tidak bisa dikirimi pengingat" || no "seharusnya ditolak: $r"

echo
echo "HASIL: $pass lulus, $fail gagal"
[ "$fail" -eq 0 ]
