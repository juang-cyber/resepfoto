#!/bin/bash
# Uji tab Konten: ekspor resep ke Excel dan impor balik.
#
# Yang dijaga tes ini, semuanya aturan yang gampang hilang saat kode disunting:
#   - impor TIDAK PERNAH menghapus resep yang tidak ada di file
#   - pratinjau (dryRun) benar-benar tidak mengubah apa pun
#   - id yang tidak dikenal ditolak, bukan diam-diam jadi resep baru
#   - tanggal_unggah & penulis resep lama diabaikan (jatah katalog member)
#   - gambar kosong mempertahankan gambar lama
#   - hanya super admin yang boleh
#
# Salinan terisolasi, database baru, tidak menyentuh data asli.
# Jalankan:  bash test/uji-konten-excel.sh
set -u
R="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/rf-ke.XXXXXX")"
PORT="${PORT:-$(php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s){echo 8393; exit;} $n=stream_socket_get_name($s,false); fclose($s); echo (int)substr(strrchr($n,":"),1);')}"
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

# Pengubah lembar: baca .xlsx, terapkan perintah sederhana, tulis ulang.
# set BARIS,KOLOM,NILAI | add NILAI,NILAI,... (baris baru) | del BARIS
cat > "$T/ubah.php" <<'PHP'
<?php
require __DIR__ . '/xlsx.php';
$src = $argv[1]; $dst = $argv[2];
$g = xlsxRead($src);
$head = $g[0]; $body = array_slice($g, 1);
$lebar = count($head);
$rapikan = function (array $r) use ($lebar) { while (count($r) < $lebar) $r[] = ''; return array_slice($r, 0, $lebar); };
$body = array_map($rapikan, $body);
for ($i = 3; $i < $argc; $i++) {
  $p = explode(' ', $argv[$i], 2);
  $cmd = $p[0]; $arg = $p[1] ?? '';
  if ($cmd === 'set') { list($b, $k, $v) = explode(',', $arg, 3); $body[(int)$b][(int)$k] = $v; }
  elseif ($cmd === 'del') { unset($body[(int)$arg]); $body = array_values($body); }
  elseif ($cmd === 'addkv') {
    // baris baru ditulis sebagai kolom=nilai;kolom=nilai — menghitung pipa kosong
    // sempat bikin judul mendarat di kolom judul_en tanpa ketahuan.
    $r = array_fill(0, $lebar, '');
    foreach (explode(';', $arg) as $pas) {
      if ($pas === '') continue;
      list($k, $v) = array_pad(explode('=', $pas, 2), 2, '');
      // JANGAN pakai $i di sini: itu penghitung loop luar, dan menimpanya
      // membuat sisa perintah tidak pernah dijalankan.
      $kol = array_search($k, $head, true);
      if ($kol === false) { fwrite(STDERR, "kolom tidak dikenal: $k\n"); exit(2); }
      $r[$kol] = $v;
    }
    $body[] = $r;
  }
  else { fwrite(STDERR, "perintah tidak dikenal: $cmd\n"); exit(2); }
}
xlsxWrite($dst, $head, $body, [1]);
PHP

echo "== sintaks =="
for f in api.php lib.php xlsx.php; do
  if php -l "$T/$f" >/dev/null 2>&1; then ok "sintaks $f"; else no "sintaks $f"; php -l "$T/$f"; fi
done
if node --check "$T/admin-konten.js" >/dev/null 2>&1; then ok "sintaks admin-konten.js"; else no "sintaks admin-konten.js"; fi

echo "== pulang-pergi modul xlsx =="
r=$(cd "$T" && php -r 'require "xlsx.php";
$h=["a","b"]; $rows=[["baris\nganti baris","<&>\"kutip\""],["📸 emoji",""]];
xlsxWrite("/tmp/rf-rt.xlsx",$h,$rows);
$b=xlsxRead("/tmp/rf-rt.xlsx"); @unlink("/tmp/rf-rt.xlsx");
$g=$b[1]; while(count($g)<2) $g[]="";
$g2=$b[2]; while(count($g2)<2) $g2[]="";
echo ($b[0]===$h && $g===$rows[0] && $g2===$rows[1]) ? "sama" : "beda";')
[ "$r" = "sama" ] && ok "teks berganti baris, XML khusus, dan emoji pulang-pergi utuh" || no "pulang-pergi rusak: $r"

echo "== server uji =="
(cd "$T" && php -S "127.0.0.1:$PORT" >/dev/null 2>&1) & SRV=$!
for i in $(seq 1 40); do curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && break; sleep 0.3; done
curl -sf "$BASE/api.php?a=me" >/dev/null 2>&1 && ok "server hidup" || no "server tidak hidup"

(cd "$T" && php -r 'require "config.php"; require "lib.php";
saveMember(["username"=>"bos","name"=>"Bos","code_hash"=>password_hash("RF-BOSS-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"bos@contoh.com","phone"=>"","role"=>"super_admin","avatar"=>""]);
saveMember(["username"=>"adminbiasa","name"=>"Admin Biasa","code_hash"=>password_hash("RF-BIASA-0001", PASSWORD_DEFAULT),
"code_hint"=>"0001","plan"=>"Admin","expires"=>"","active"=>1,"created_at"=>gmdate("c"),"last_login"=>null,
"email"=>"biasa@contoh.com","phone"=>"","role"=>"admin","avatar"=>""]);')

csrf(){ curl -s -c "$1" -b "$1" "$BASE/api.php?a=me" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"] ?? "";'; }
login(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -b "$1" -c "$1" -H "X-CSRF: $c" -H 'Content-Type: application/json' -X POST "$BASE/api.php?a=login" -d "{\"username\":\"$2\",\"code\":\"$3\"}"; }
imp(){ local c; c=$(csrf "$1"); curl -s -b "$1" -c "$1" -H "X-CSRF: $c" -F "file=@$2" -F "dryRun=$3" -X POST "$BASE/api.php?a=prompts_import"; }
impc(){ local c; c=$(csrf "$1"); curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" -H "X-CSRF: $c" -F "file=@$2" -F "dryRun=$3" -X POST "$BASE/api.php?a=prompts_import"; }
jum(){ (cd "$T" && php -r 'require "config.php"; require "lib.php"; echo db()->query("SELECT COUNT(*) FROM prompts")->fetchColumn();'); }
kol(){ (cd "$T" && php -r 'require "config.php"; require "lib.php";
$s=db()->prepare("SELECT " . $argv[1] . " FROM prompts WHERE id = ?"); $s->execute([$argv[2]]); echo (string)$s->fetchColumn();' "$1" "$2"); }
ambil(){ echo "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; echo isset($d[$k])?(is_bool($d[$k])?($d[$k]?"true":"false"):(string)$d[$k]):"";' "$2"; }

S="$T/jarS"; A="$T/jarA"
login "$S" "bos" "RF-BOSS-0001"
login "$A" "adminbiasa" "RF-BIASA-0001"
AWAL=$(jum)

echo "== hak akses: khusus super admin =="
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$A" "$BASE/api.php?a=prompts_export")
[ "$code" = "403" ] && ok "admin biasa ditolak di prompts_export (403)" || no "prompts_export seharusnya 403, dapat $code"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api.php?a=prompts_export")
[ "$code" != "200" ] && ok "tamu tanpa sesi ditolak di prompts_export" || no "tamu seharusnya ditolak"

echo "== ekspor =="
curl -s -b "$S" -D "$T/h.txt" -o "$T/asli.xlsx" "$BASE/api.php?a=prompts_export"
grep -qi 'spreadsheetml.sheet' "$T/h.txt" && ok "Content-Type file Excel" || no "Content-Type salah: $(grep -i content-type "$T/h.txt")"
grep -qi 'attachment; filename=' "$T/h.txt" && ok "dikirim sebagai unduhan" || no "Content-Disposition hilang"
[ "$(head -c2 "$T/asli.xlsx")" = "PK" ] && ok "file benar-benar arsip zip (xlsx)" || no "bukan file xlsx"
r=$(cd "$T" && php -r 'require "xlsx.php"; $g=xlsxRead("asli.xlsx");
$h=$g[0]; $perlu=["id","urutan","judul","prompt","gambar","tanggal_unggah","tanggal_ubah"];
$kurang=array_diff($perlu,$h);
echo count($h),"|",count($g)-1,"|",(count($kurang)?"kurang:".implode(",",$kurang):"lengkap"),"|",($g[1][18]!==""?"terisi":"kosong");')
IFS='|' read -r NKOL NBARIS LENGKAP TGL <<< "$r"
[ "$NKOL" = "20" ] && ok "20 kolom di lembar" || no "jumlah kolom $NKOL"
[ "$NBARIS" = "$AWAL" ] && ok "semua $AWAL resep ikut terekspor" || no "baris $NBARIS, resep $AWAL"
[ "$LENGKAP" = "lengkap" ] && ok "kolom wajib lengkap" || no "$LENGKAP"
[ "$TGL" = "terisi" ] && ok "kolom tanggal_unggah terisi" || no "tanggal_unggah kosong"

echo "== pratinjau tidak mengubah apa pun =="
JUDUL_LAMA=$(kol title p01)
r=$(imp "$S" "$T/asli.xlsx" 1)
[ "$(ambil "$r" dryRun)" = "true" ] && ok "server menandai ini pratinjau" || no "dryRun tidak ditandai: $r"
[ "$(ambil "$r" perbarui)" = "$AWAL" ] && ok "pratinjau melaporkan $AWAL baris akan diperbarui" || no "perbarui=$(ambil "$r" perbarui)"
[ "$(ambil "$r" tambah)" = "0" ] && ok "pratinjau tidak menambah apa pun" || no "tambah=$(ambil "$r" tambah)"
[ "$(jum)" = "$AWAL" ] && ok "jumlah resep tidak berubah sesudah pratinjau" || no "jumlah berubah jadi $(jum)"
[ "$(kol title p01)" = "$JUDUL_LAMA" ] && ok "isi resep tidak tersentuh oleh pratinjau" || no "judul berubah saat pratinjau"

echo "== impor menolak baris tidak sah =="
php "$T/ubah.php" "$T/asli.xlsx" "$T/salah.xlsx" \
  'addkv id=id-ngawur;kategori=Kat;judul=Judul;prompt=p;gambar=img/p01.jpg' \
  'addkv kategori=Kat;prompt=tanpa judul;gambar=img/p01.jpg' \
  'addkv judul=Tanpa kategori;prompt=p;gambar=img/p01.jpg' \
  'addkv kategori=Kat;judul=Tanpa gambar;prompt=p' \
  'addkv kategori=Kat;judul=Tanpa prompt;gambar=img/p01.jpg' || { echo "  GAGAL pembuat file uji error"; exit 1; }
r=$(imp "$S" "$T/salah.xlsx" 1)
g=$(echo "$r" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["galat"]??[]) as $x) echo $x["pesan"],"\n";')
echo "$g" | grep -qi 'tidak ada di katalog' && ok "id tidak dikenal ditolak (bukan dibuat resep baru)" || no "id ngawur tidak ditolak: $g"
echo "$g" | grep -qi 'judul kosong' && ok "judul kosong ditolak" || no "judul kosong lolos"
echo "$g" | grep -qi 'kategori kosong' && ok "kategori kosong ditolak" || no "kategori kosong lolos"
echo "$g" | grep -qi 'wajib punya gambar' && ok "resep baru tanpa gambar ditolak" || no "resep tanpa gambar lolos"
echo "$g" | grep -qi 'prompt kosong' && ok "prompt kosong ditolak" || no "prompt kosong lolos"
[ "$(ambil "$r" tambah)" = "0" ] && ok "tidak ada baris salah yang lolos jadi resep baru" || no "tambah=$(ambil "$r" tambah)"

php "$T/ubah.php" "$T/asli.xlsx" "$T/qc.xlsx" 'set 0,15,ngawur' >/dev/null 2>&1
r=$(imp "$S" "$T/qc.xlsx" 1)
echo "$r" | grep -qi 'status_qc harus' && ok "status_qc tidak sah ditolak" || no "status_qc ngawur lolos: $r"
php "$T/ubah.php" "$T/asli.xlsx" "$T/img.xlsx" 'set 0,14,uploads/tidak-ada.jpg' >/dev/null 2>&1
r=$(imp "$S" "$T/img.xlsx" 1)
echo "$r" | grep -qi 'tidak ada di server' && ok "gambar yang tidak ada di server ditolak" || no "gambar hantu lolos: $r"
php "$T/ubah.php" "$T/asli.xlsx" "$T/panjang.xlsx" "set 0,4,$(php -r 'echo str_repeat("a",90);')" >/dev/null 2>&1
r=$(imp "$S" "$T/panjang.xlsx" 1)
echo "$r" | grep -qi 'terlalu panjang' && ok "judul melewati batas panjang ditolak" || no "judul 90 karakter lolos: $r"

echo "== kolom wajib hilang =="
(cd "$T" && php -r 'require "xlsx.php"; xlsxWrite("tanpa.xlsx", ["id","urutan"], [["p01","1"]]);')
code=$(impc "$S" "$T/tanpa.xlsx" 1)
[ "$code" != "200" ] && ok "file tanpa kolom wajib ditolak" || no "file tanpa kolom judul/prompt seharusnya ditolak"

echo "== impor sungguhan: perbarui + tambah =="
php "$T/ubah.php" "$T/asli.xlsx" "$T/terap.xlsx" \
  'set 0,4,JUDUL BARU DARI EXCEL' \
  'set 0,12,ya' \
  'addkv urutan=999;kategori=Foto Jadul;judul=Resep Tambahan;prompt=prompt tambahan;alat=Gemini, ChatGPT;best_seller=ya;gambar=img/p01.jpg' \
  || { echo "  GAGAL pembuat file uji error"; exit 1; }
r=$(imp "$S" "$T/terap.xlsx" 0)
[ "$(ambil "$r" ok)" = "true" ] && ok "impor dijalankan" || no "impor gagal: $r"
[ "$(ambil "$r" tambah)" = "1" ] && ok "satu resep baru ditambahkan" || no "tambah=$(ambil "$r" tambah)"
[ "$(jum)" = "$((AWAL+1))" ] && ok "jumlah resep naik satu ($AWAL -> $(jum))" || no "jumlah jadi $(jum), harusnya $((AWAL+1))"
[ "$(kol title p01)" = "JUDUL BARU DARI EXCEL" ] && ok "judul resep lama ikut berubah" || no "judul tidak berubah: $(kol title p01)"
[ "$(kol popular p01)" = "1" ] && ok "kolom best_seller 'ya' terbaca" || no "popular=$(kol popular p01)"
r2=$(cd "$T" && php -r 'require "config.php"; require "lib.php";
$x=db()->query("SELECT created_by, tools, image FROM prompts WHERE title = \"Resep Tambahan\"")->fetch();
echo $x ? $x["created_by"]."|".$x["tools"]."|".$x["image"] : "TIDAK ADA";')
echo "$r2" | grep -q '^bos|' && ok "penulis resep baru = admin yang mengimpor" || no "created_by salah: $r2"
echo "$r2" | grep -q 'Gemini' && ok "kolom alat terbaca jadi tools" || no "tools salah: $r2"

echo "== impor TIDAK PERNAH menghapus =="
SEBELUM=$(jum)
php "$T/ubah.php" "$T/asli.xlsx" "$T/kurang.xlsx" 'del 0' 'del 0' 'del 0' >/dev/null 2>&1
r=$(imp "$S" "$T/kurang.xlsx" 0)
[ "$(jum)" = "$SEBELUM" ] && ok "3 resep dibuang dari file, katalog tetap $SEBELUM" || no "katalog jadi $(jum) — impor menghapus!"
[ -n "$(kol title p01)" ] && ok "resep yang hilang dari file tetap ada di katalog" || no "p01 ikut terhapus"

echo "== tanggal_unggah & penulis resep lama diabaikan =="
TGL_LAMA=$(kol created_at p01)
PEN_LAMA=$(kol created_by p01)
php "$T/ubah.php" "$T/asli.xlsx" "$T/tgl.xlsx" 'set 0,18,2020-01-01T00:00:00+00:00' 'set 0,17,penyusup' >/dev/null 2>&1
r=$(imp "$S" "$T/tgl.xlsx" 0)
[ "$(kol created_at p01)" = "$TGL_LAMA" ] && ok "tanggal_unggah resep lama tidak ikut berubah" || no "created_at berubah jadi $(kol created_at p01)"
[ "$(kol created_by p01)" = "$PEN_LAMA" ] && ok "penulis resep lama dipertahankan" || no "created_by berubah jadi $(kol created_by p01)"
[ "$(ambil "$r" abaiTanggal)" -ge 1 ] && ok "server melaporkan tanggal yang diabaikan" || no "abaiTanggal=$(ambil "$r" abaiTanggal)"
[ "$(ambil "$r" abaiPenulis)" -ge 1 ] && ok "server melaporkan penulis yang diabaikan" || no "abaiPenulis=$(ambil "$r" abaiPenulis)"

echo "== gambar kosong mempertahankan gambar lama =="
IMG_LAMA=$(kol image p01)
php "$T/ubah.php" "$T/asli.xlsx" "$T/nogambar.xlsx" 'set 0,14,' >/dev/null 2>&1
imp "$S" "$T/nogambar.xlsx" 0 >/dev/null
[ "$(kol image p01)" = "$IMG_LAMA" ] && ok "gambar lama dipertahankan saat kolomnya dikosongkan" || no "gambar hilang: '$(kol image p01)'"

echo "== impor CSV =="
(cd "$T" && php -r 'require "xlsx.php"; $g=xlsxRead("asli.xlsx");
$fh=fopen("uji.csv","w"); foreach($g as $r) fputcsv($fh,$r); fclose($fh);')
r=$(imp "$S" "$T/uji.csv" 1)
[ "$(ambil "$r" ok)" = "true" ] && ok "file CSV ikut bisa dibaca" || no "CSV ditolak: $r"
[ "$(ambil "$r" perbarui)" -ge 1 ] && ok "CSV menghasilkan baris yang bisa diperbarui" || no "CSV tidak menghasilkan baris"

echo "== file rusak ditolak dengan sopan =="
echo "ini bukan excel sama sekali" > "$T/rusak.xlsx"
r=$(imp "$S" "$T/rusak.xlsx" 1)
[ "$(ambil "$r" ok)" != "true" ] && ok "file rusak ditolak" || no "file rusak malah diterima"
echo "$r" | grep -qi 'excel' && ok "pesan errornya menjelaskan soal file Excel" || no "pesan error tidak jelas: $r"
[ "$(jum)" -ge "$SEBELUM" ] && ok "katalog utuh sesudah semua percobaan gagal" || no "katalog menyusut"

echo
echo "HASIL: $pass lulus, $fail gagal"
[ "$fail" -eq 0 ]
