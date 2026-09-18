#!/bin/bash
# Uji "satu sesi aktif per akun" + login pakai email, di salinan terisolasi.
# Tidak menyentuh data asli: DB baru, folder sementara, dan email TIDAK dikirim.
# Jalankan dari mana saja:  bash test/uji-satu-sesi.sh
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/rf-uji.XXXXXX")"
# Cari port kosong sendiri supaya tidak tabrakan dengan sisa proses uji sebelumnya.
PORT="${PORT:-$(php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s){echo 8390; exit;} $n=stream_socket_get_name($s,false); fclose($s); echo (int)substr(strrchr($n,":"),1);')}"
BASE="http://127.0.0.1:$PORT"
pass=0; fail=0
ok(){ printf '  OK    %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  GAGAL %s\n' "$1"; fail=$((fail+1)); }
cleanup(){ [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT

echo "== siapkan salinan terisolasi =="
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

echo "== cek sintaks =="
for f in api.php lib.php ai.php webhook-mayar.php; do
  if php -l "$T/$f" >/dev/null 2>&1; then ok "sintaks $f"; else no "sintaks $f"; php -l "$T/$f"; fi
done

echo "== buat member lewat fulfillOrder (jalur asli pembayaran) =="
(cd "$T" && php -r '
require "config.php"; require "lib.php";
$pdo = db();
$now = gmdate("c");
$pdo->prepare("INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
  ->execute(["UJI-1","mayar","payment.received","success",1,"lunas","Premium","ResepFoto Premium",79900,"Budi Uji","Budi.Uji@Gmail.com","08123456789",null,null,0,"","{}",$now,$now]);
fulfillOrder("UJI-1", false);   // false = jangan kirim email/WA
') || no "fulfillOrder gagal"
OUT=$(cd "$T" && php -r '
require "config.php"; require "lib.php";
$st = db()->prepare("SELECT username, email FROM members WHERE length(email) > 0 LIMIT 1"); $st->execute();
$m = $st->fetch(); echo $m ? $m["username"] : "(kosong)";
')
if [ "$OUT" = "budi.uji@gmail.com" ]; then ok "username = email lengkap (huruf kecil): $OUT"; else no "username seharusnya budi.uji@gmail.com, dapat: $OUT"; fi

CODE=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; $o=findOrder("UJI-1"); echo $o["code"];')
[ -n "$CODE" ] && ok "kode akses terbit" || no "kode akses kosong"

echo "== jalankan server uji =="
(cd "$T" && php -S "127.0.0.1:$PORT" >/dev/null 2>&1) & SRV=$!
for i in $(seq 1 30); do curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && break; sleep 0.3; done

csrf(){ curl -s -c "$1" -b "$1" "$BASE/api.php?a=me" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"] ?? "";'; }
login(){ # $1=jar $2=user $3=code
  local c; c=$(csrf "$1")
  curl -s -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' \
       -X POST "$BASE/api.php?a=login" -d "{\"username\":\"$2\",\"code\":\"$3\"}"
}

A="$T/jarA"; B="$T/jarB"

echo "== skenario login =="
r=$(login "$A" "budi.uji@gmail.com" "$CODE")
echo "$r" | grep -q '"ok":true' && ok "login pakai EMAIL berhasil" || no "login pakai email gagal: $r"

r=$(login "$A" "demo" "RF-DEMO-0000")
echo "$r" | grep -q '"ok":true' && ok "login pakai USERNAME lama tetap berhasil" || no "login username gagal: $r"

echo "== skenario satu sesi =="
login "$A" "budi.uji@gmail.com" "$CODE" >/dev/null           # perangkat A
r=$(curl -s -b "$A" "$BASE/api.php?a=me")
echo "$r" | grep -q '"username":"budi.uji@gmail.com"' && ok "perangkat A masuk" || no "perangkat A tidak masuk: $r"

login "$B" "budi.uji@gmail.com" "$CODE" >/dev/null           # perangkat B
r=$(curl -s -b "$B" "$BASE/api.php?a=me")
echo "$r" | grep -q '"username":"budi.uji@gmail.com"' && ok "perangkat B masuk" || no "perangkat B tidak masuk: $r"

r=$(curl -s -b "$A" "$BASE/api.php?a=me")
echo "$r" | grep -q '"sessionTaken":true' && ok "perangkat A ditendang dan diberi tahu sebabnya" || no "perangkat A masih aktif: $r"

code=$(curl -s -o /dev/null -w '%{http_code}' -b "$A" "$BASE/api.php?a=prompts")
[ "$code" = "401" ] && ok "endpoint terlindungi menolak perangkat A (401)" || no "prompts untuk A seharusnya 401, dapat $code"

code=$(curl -s -o /dev/null -w '%{http_code}' -b "$B" "$BASE/api.php?a=prompts")
[ "$code" = "200" ] && ok "perangkat B tetap bisa memakai app (200)" || no "prompts untuk B seharusnya 200, dapat $code"

echo "== simpan profil tidak boleh menendang sesi =="
BEFORE=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; $m=findMember("budi.uji@gmail.com"); echo $m["session_token"];')
(cd "$T" && php -r 'require "config.php"; require "lib.php"; $m=findMember("budi.uji@gmail.com"); $m["name"]="Budi Ganti Nama"; saveMember($m);')
AFTER=$(cd "$T" && php -r 'require "config.php"; require "lib.php"; $m=findMember("budi.uji@gmail.com"); echo $m["session_token"];')
[ -n "$BEFORE" ] && [ "$BEFORE" = "$AFTER" ] && ok "token sesi bertahan saat simpan profil" || no "token sesi hilang saat simpan profil"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$B" "$BASE/api.php?a=prompts")
[ "$code" = "200" ] && ok "perangkat B masih masuk sesudah profil disimpan" || no "B ikut ter-logout, dapat $code"

echo "== ganti kode akses harus memutus semua sesi =="
(cd "$T" && php -r 'require "config.php"; require "lib.php"; $m=findMember("budi.uji@gmail.com"); $m["code_hash"]=password_hash("RF-BARU-9999", PASSWORD_DEFAULT); $m["session_token"]=""; saveMember($m);')
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$B" "$BASE/api.php?a=prompts")
[ "$code" = "401" ] && ok "sesi lama putus setelah kode akses diganti" || no "sesi lama masih hidup, dapat $code"

echo
echo "HASIL: $pass lulus, $fail gagal"
[ "$fail" -eq 0 ]
