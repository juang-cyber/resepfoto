<?php
/**
 * ResepFoto API — PHP + SQLite
 * Semua data disimpan di SQLite dalam folder data/ (diblokir dari web). Fungsi bersama ada di lib.php.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
define('RF_API', true);
require __DIR__ . '/ai.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

session_name('rf_sess');
session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 30,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
session_start();

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function isEn(): bool { return strtolower(substr((string)($_SERVER['HTTP_X_LANG'] ?? ''), 0, 2)) === 'en'; }
function tr(string $msg): string {
  if (!isEn()) return $msg;
  static $map = [
    'Akses akun ini sedang nonaktif. Hubungi admin untuk mengaktifkan lagi.' => 'This account is currently inactive. Contact the admin to reactivate it.',
    'Akses kamu sudah berakhir. Perpanjang paket untuk lanjut.' => 'Your access has ended. Renew your plan to continue.',
    'Halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.' => 'This page has expired. Reload the page and try again.',
    'Isi username dan kode akses dulu.' => 'Enter your username and access code first.',
    'Kode akses admin salah.' => 'Wrong admin access code.',
    'Sesi berakhir. Silakan masuk lagi.' => 'Your session has ended. Please sign in again.',
    'Pesanan ini sudah aktif, tidak perlu pengingat.' => 'This order is already active; no reminder needed.',
    'Pesanan ini sudah ditolak.' => 'This order was already rejected.',
    'Pengingat untuk pesanan ini sudah dikirim 2 kali.' => 'A reminder for this order has already been sent twice.',
    'Pengingat terakhir belum 24 jam. Tunggu dulu ya.' => 'The last reminder was less than 24 hours ago. Please wait.',
    'Pengingat gagal dikirim lewat email maupun WhatsApp. Cek setelan SMTP dan Fonnte.' => 'The reminder could not be sent by email or WhatsApp. Check the SMTP and Fonnte settings.',
    'Kode voucher harus 5-24 karakter, hanya huruf, angka, dan tanda minus.' => 'A voucher code must be 5-24 characters: letters, digits and hyphens only.',
    'Persentase harus salah satu dari 10 sampai 90.' => 'The percentage must be one of 10 through 90.',
    'Kode itu sudah dipakai untuk tingkat diskon lain.' => 'That code is already used for another discount tier.',
    'Kuota harus antara 1 dan 100000.' => 'The quota must be between 1 and 100000.',
    'Tanggal kedaluwarsa wajib diisi, format YYYY-MM-DD.' => 'An expiry date is required, in YYYY-MM-DD format.',
    'Tanggal kedaluwarsa harus setelah hari ini.' => 'The expiry date must be after today.',
    'Tingkat ini sudah punya kupon di Mayar. Kosongkan dulu sebelum membuat yang baru.' => 'This tier already has a coupon at Mayar. Clear it before creating a new one.',
    'Tingkat ini belum punya kupon yang dibuat lewat panel.' => 'This tier has no coupon created through the panel yet.',
    'API key Mayar belum diisi di tab Voucher.' => 'The Mayar API key has not been set in the Voucher tab.',
    'Akun ini baru saja dipakai masuk di perangkat lain. Satu akun hanya bisa aktif di satu perangkat.' => 'This account was just signed in on another device. One account can only be active on one device.',
    'Terjadi kesalahan di server. Coba lagi sebentar lagi.' => 'Something went wrong on the server. Please try again shortly.',
    'Terlalu banyak percobaan. Coba lagi 15 menit lagi.' => 'Too many attempts. Try again in 15 minutes.',
    'Username atau kode akses tidak cocok. Cek lagi pesan konfirmasi pembelianmu.' => 'Username or access code doesn\'t match. Check your purchase confirmation again.',
    'Khusus admin.' => 'Admins only.',
    'Khusus super admin.' => 'Super admins only.',
    'Username sudah dipakai.' => 'That username is already taken.',
    'Akun admin tidak ditemukan.' => 'Admin account not found.',
    'Tidak bisa menghapus akun ini.' => 'This account can\'t be deleted.',
    'Tidak bisa mengubah super admin utama.' => 'The main super admin can\'t be changed.',
    'Pilih atau tempel gambar yang berisi prompt.' => 'Choose or paste an image that contains the prompt.',
    'Tidak menemukan teks prompt di gambar. Pastikan tulisannya jelas terbaca.' => 'No prompt text found in the image. Make sure the text is clearly legible.',
    // link Instagram & DeepSeek
    'Tempel link postingan Instagram dulu.' => 'Paste an Instagram post link first.',
    'Link harus link postingan Instagram, contoh: https://www.instagram.com/p/XXXX/' => 'The link must be an Instagram post link, e.g. https://www.instagram.com/p/XXXX/',
    'Postingan tidak ditemukan. Cek lagi link-nya, mungkin sudah dihapus.' => 'Post not found. Check the link; it may have been deleted.',
    'Instagram tidak mengizinkan server membaca postingan ini (diminta login, atau akunnya privat). Pakai mode "Prompt dari gambar" dengan screenshot slide-nya.' => 'Instagram did not let the server read this post (login required, or the account is private). Use "Prompt from image" mode with screenshots of the slides.',
    'Postingan terbaca, tapi gambar dan caption-nya kosong.' => 'The post was read, but its images and caption are empty.',
    'Prompt tidak ditemukan di caption maupun slide. Kalau prompt-nya ada di komentar, salin komentarnya ke kolom "Teks tambahan" lalu coba lagi.' => 'No prompt found in the caption or slides. If the prompt is in a comment, copy it into "Extra text" and try again.',
    'Isi dulu API key DeepSeek atau Gemini di Admin → AI.' => 'Set a DeepSeek or Gemini API key in Admin → AI first.',
    'Format API key DeepSeek tidak valid.' => 'Invalid DeepSeek API key format.',
    'Isi prompt resep dulu sebelum generate thumbnail.' => 'Fill in the recipe prompt before generating a thumbnail.',
    'Pilih slide referensi dulu.' => 'Choose a reference slide first.',
    'Tempel atau pilih foto wajah dulu.' => 'Paste or choose a face photo first.',
    'Gambar hasil generate tidak bisa dibaca.' => 'The generated image could not be read.',
    'Foto wajah tidak ditemukan.' => 'Face photo not found.',
    'Peran tidak valid.' => 'Invalid role.',
    'Akun ini dikelola di tab Admin.' => 'This account is managed in the Admin tab.',
    'Pilih atau tempel foto dulu.' => 'Choose or paste a photo first.',
    'Hanya untuk akun admin utama.' => 'Only for the main admin account.',
    'Kode akses saat ini salah.' => 'Current access code is wrong.',
    'Kode baru minimal 8 karakter.' => 'New code must be at least 8 characters.',
    'Gambar tidak bisa dibaca.' => 'The image could not be read.',
    'Metode salah.' => 'Wrong method.',
    'Endpoint tidak dikenal.' => 'Unknown endpoint.',
    'Aksi tidak dikenal.' => 'Unknown action.',
    'Upload gambar gagal. Coba file lain.' => 'Image upload failed. Try another file.',
    'Gambar terlalu besar. Maksimal 8 MB.' => 'Image is too large. Max 8 MB.',
    'File harus gambar JPG, PNG, atau WEBP.' => 'File must be a JPG, PNG or WEBP image.',
    'Gambar tidak bisa disimpan di server.' => 'The image couldn\'t be saved on the server.',
    'Member tidak ditemukan.' => 'Member not found.',
    'Nama dan username wajib diisi.' => 'Name and username are required.',
    'Username sudah dipakai member lain.' => 'That username is already taken.',
    'Username itu dipakai sistem. Pilih yang lain.' => 'That username is reserved. Pick another one.',
    'Username 3–30 karakter: huruf kecil, angka, titik, garis bawah, atau strip.' => 'Username must be 3–30 characters: lowercase letters, numbers, dots, underscores or hyphens.',
    'Format tanggal berlaku tidak valid.' => 'Invalid expiry date format.',
    'Format email tidak valid.' => 'Invalid email format.',
    'Email pengirim tidak valid.' => 'Invalid sender email.',
    'Nomor WhatsApp admin tidak valid.' => 'Invalid admin WhatsApp number.',
    'Pixel ID Meta tidak valid — isinya 15-16 angka.' => 'Invalid Meta Pixel ID — it is 15-16 digits.',
    'Isi nomor WhatsApp admin dulu.' => 'Set the admin WhatsApp number first.',
    'Isi dan simpan email admin dulu.' => 'Set and save the admin email first.',
    // ekspor/impor Excel
    'Pilih dulu file Excel yang mau diimpor.' => 'Choose the Excel file to import first.',
    'File terlalu besar. Maksimal 8 MB.' => 'That file is too large. The maximum is 8 MB.',
    'File itu kosong.' => 'That file is empty.',
    'Terlalu banyak baris. Maksimal 5000 baris data per impor.' => 'Too many rows. The maximum is 5000 data rows per import.',
    'Impor dibatalkan, tidak ada satu pun yang berubah. Coba periksa lagi isi filenya.' => 'The import was rolled back; nothing changed. Please check the file contents again.',
    'File itu bukan file Excel yang bisa dibuka.' => 'That file could not be opened as an Excel file.',
    'Lembar pertama tidak ditemukan di file Excel itu.' => 'No first sheet was found in that Excel file.',
    'Isi file Excel tidak bisa dibaca.' => 'The contents of that Excel file could not be read.',
    'Isi file Excel terlalu besar.' => 'The contents of that Excel file are too large.',
    'File CSV tidak bisa dibuka.' => 'That CSV file could not be opened.',
    'Server ini tidak punya ekstensi ZipArchive, file Excel tidak bisa dibuat.' => 'This server has no ZipArchive extension, so Excel files cannot be created.',
    'Server ini tidak punya ekstensi ZipArchive, file Excel tidak bisa dibaca.' => 'This server has no ZipArchive extension, so Excel files cannot be read.',
  ];
  if (isset($map[$msg])) return $map[$msg];
  if (strpos($msg, 'Lengkapi dulu: ') === 0) {
    $f = ['judul' => 'title', 'kategori' => 'category', 'prompt' => 'prompt', 'gambar contoh' => 'sample image'];
    return 'Please fill in: ' . strtr(rtrim(substr($msg, strlen('Lengkapi dulu: ')), '.'), $f) . '.';
  }
  if (strpos($msg, 'Isi tanggal berlaku untuk paket ') === 0) return 'Set an expiry date for plan ' . substr($msg, strlen('Isi tanggal berlaku untuk paket '));
  return $msg;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => tr($msg)], $code); }

function currentUser(): ?array {
  $s = $_SESSION['user'] ?? null;
  if (!$s) return null;
  if ($s['role'] === 'admin') return ['role' => 'admin', 'adminRole' => 'super_admin', 'username' => ADMIN_USER, 'name' => ADMIN_DISPLAY_NAME, 'plan' => 'Admin', 'expires' => '', 'avatar' => setting('admin_avatar')];
  $st = db()->prepare('SELECT * FROM members WHERE username = ?');
  $st->execute([$s['username']]);
  $m = $st->fetch();
  if (!$m || memberStatus($m) !== 'ok') { unset($_SESSION['user']); return null; }
  // Satu sesi aktif per akun. Token tidak cocok = akun ini baru dipakai masuk di
  // perangkat lain, jadi sesi ini diputus.
  // Bandingkan SELALU. Kalau token di database dikosongkan/diganti, sesi lama otomatis
  // tidak cocok lagi -> terputus. Member lama yang belum pernah login sejak fitur ini
  // terpasang punya token kosong DAN sesi tanpa stok, jadi keduanya kosong dan tetap masuk.
  $tok = (string)($m['session_token'] ?? '');
  if (!hash_equals($tok, (string)($s['stok'] ?? ''))) {
    unset($_SESSION['user']);
    $GLOBALS['rf_session_taken'] = true;
    return null;
  }
  $mrole = $m['role'] ?? 'member';
  $pub = publicMember($m);
  if ($mrole === 'admin' || $mrole === 'super_admin') return ['role' => 'admin', 'adminRole' => $mrole] + $pub;
  return ['role' => 'member', 'adminRole' => ''] + $pub;
}
function requireUser(): array {
  $u = currentUser();
  if (!$u) fail(!empty($GLOBALS['rf_session_taken'])
    ? 'Akun ini baru saja dipakai masuk di perangkat lain. Satu akun hanya bisa aktif di satu perangkat.'
    : 'Sesi berakhir. Silakan masuk lagi.', 401);
  return $u;
}
function requireAdmin(): array { $u = requireUser(); if ($u['role'] !== 'admin') fail('Khusus admin.', 403); return $u; }
function requireSuperAdmin(): array { $u = requireUser(); if (($u['adminRole'] ?? '') !== 'super_admin') fail('Khusus super admin.', 403); return $u; }
function coverConfig(): array {
  return [
    'title' => setting('cover_title'), 'sub' => setting('cover_sub'),
    'titleEn' => setting('cover_title_en'), 'subEn' => setting('cover_sub_en'),
    'chip' => setting('cover_chip'),
    'img' => [setting('cover_img1'), setting('cover_img2'), setting('cover_img3')],
  ];
}
function rowToPrompt(array $r, bool $forAdmin = false, bool $locked = false): array {
  $p = ['id' => $r['id'], 'order' => (int)$r['ord'], 'cat' => $r['cat'], 'title' => $r['title'], 'desc' => $r['descr'],
    'popular' => (bool)$r['popular'], 'tools' => json_decode($r['tools'] ?: '[]', true), 'prompt' => $r['prompt'],
    'tips' => $r['tips'], 'image' => $r['image'], 'createdAt' => $r['created_at'] ?? '',
    'catEn' => (string)($r['cat_en'] ?? ''), 'titleEn' => (string)($r['title_en'] ?? ''),
    'descEn' => (string)($r['descr_en'] ?? ''), 'tipsEn' => (string)($r['tips_en'] ?? ''),
    'promptEn' => (string)($r['prompt_en'] ?? ''), 'promptId' => looksIndonesian((string)$r['prompt']),
    'catTh' => (string)($r['cat_th'] ?? ''), 'titleTh' => (string)($r['title_th'] ?? ''),
    'descTh' => (string)($r['descr_th'] ?? ''), 'tipsTh' => (string)($r['tips_th'] ?? ''),
    'enOnly' => (bool)($r['en_only'] ?? 0), 'locked' => $locked];
  // Resep terkunci tetap tampil (judul + thumbnail), tapi isinya TIDAK pernah dikirim ke klien.
  // Kalau hanya disembunyikan di CSS, siapa pun bisa membacanya lewat devtools.
  if ($locked) { $p['prompt'] = ''; $p['promptEn'] = ''; $p['tips'] = ''; $p['tipsEn'] = ''; $p['tipsTh'] = ''; }
  if ($forAdmin) {
    $p['createdBy'] = (string)($r['created_by'] ?? '');
    $p['qc'] = (string)($r['qc_status'] ?? '');
    $p['result'] = (string)($r['result_status'] ?? '');
    $p['updatedAt'] = (string)($r['updated_at'] ?? '');
  }
  return $p;
}
/**
 * Skema kolom lembar Excel untuk resep: nama kolom => lebar kolom.
 * Ekspor dan impor SAMA-SAMA membaca daftar ini, supaya file hasil ekspor
 * selalu bisa diimpor balik tanpa penyesuaian. Menambah kolom cukup di sini.
 */
function promptSheetCols(): array {
  return ['id' => 14, 'urutan' => 8, 'kategori' => 16, 'kategori_en' => 16,
    'judul' => 30, 'judul_en' => 30, 'deskripsi' => 36, 'deskripsi_en' => 36,
    'prompt' => 60, 'tips' => 36, 'tips_en' => 36, 'alat' => 16,
    'best_seller' => 11, 'english_saja' => 12, 'gambar' => 22,
    'status_qc' => 12, 'hasil' => 10, 'penulis' => 14,
    'tanggal_unggah' => 24, 'tanggal_ubah' => 24];
}

/** Satu baris prompts menjadi satu baris lembar, urutannya mengikuti promptSheetCols(). */
function promptToSheetRow(array $r): array {
  $tools = json_decode((string)($r['tools'] ?: '[]'), true);
  if (!is_array($tools)) $tools = [];
  $ya = function ($v) { return !empty($v) ? 'ya' : 'tidak'; };
  return [
    (string)$r['id'], (string)(int)$r['ord'], (string)$r['cat'], (string)($r['cat_en'] ?? ''),
    (string)$r['title'], (string)($r['title_en'] ?? ''), (string)$r['descr'], (string)($r['descr_en'] ?? ''),
    (string)$r['prompt'], (string)$r['tips'], (string)($r['tips_en'] ?? ''), implode(', ', $tools),
    $ya($r['popular']), $ya($r['en_only'] ?? 0), (string)$r['image'],
    (string)($r['qc_status'] ?? ''), (string)($r['result_status'] ?? ''), (string)($r['created_by'] ?? ''),
    (string)($r['created_at'] ?? ''), (string)($r['updated_at'] ?? ''),
  ];
}

function input(): array {
  if (!empty($_POST)) return $_POST;
  $j = json_decode((string)file_get_contents('php://input'), true);
  return is_array($j) ? $j : [];
}
function str(array $in, string $k, int $max = 5000): string { return mb_substr(trim((string)($in[$k] ?? '')), 0, $max); }
function clientIp(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0'); }

function saveImage(array $f): string {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Upload gambar gagal. Coba file lain.');
  if ($f['size'] > 8 * 1024 * 1024) fail('Gambar terlalu besar. Maksimal 8 MB.');
  $info = @getimagesize($f['tmp_name']);
  $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
  if (!$info || !isset($types[$info[2]])) fail('File harus gambar JPG, PNG, atau WEBP.');
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = bin2hex(random_bytes(8));
  // kalau GD tersedia, potong 4:5 dan kompres jadi JPG 960x1200
  if (function_exists('imagecreatetruecolor')) {
    $src = false;
    if ($info[2] === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($f['tmp_name']);
    elseif ($info[2] === IMAGETYPE_PNG) $src = @imagecreatefrompng($f['tmp_name']);
    elseif ($info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
    if ($src) {
      [$w, $h] = [$info[0], $info[1]]; $W = 960; $H = 1200;
      $scale = max($W / $w, $H / $h); $cw = (int)round($W / $scale); $ch = (int)round($H / $scale);
      $dst = imagecreatetruecolor($W, $H);
      imagecopyresampled($dst, $src, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), $W, $H, $cw, $ch);
      imagejpeg($dst, "$dir/$name.jpg", 80);
      return "uploads/$name.jpg";
    }
  }
  $ext = $types[$info[2]];
  if (!move_uploaded_file($f['tmp_name'], "$dir/$name.$ext")) fail('Gambar tidak bisa disimpan di server.', 500);
  return "uploads/$name.$ext";
}

function delUpload(?string $p): void { if ($p && strpos($p, 'uploads/') === 0) @unlink(__DIR__ . '/' . $p); }
/** Simpan foto profil: potong 1:1 jadi 400x400 JPG. */
function saveAvatar(array $f): string {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Upload gambar gagal. Coba file lain.');
  if ($f['size'] > 8 * 1024 * 1024) fail('Gambar terlalu besar. Maksimal 8 MB.');
  $info = @getimagesize($f['tmp_name']);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('File harus gambar JPG, PNG, atau WEBP.');
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = 'uploads/av_' . bin2hex(random_bytes(8)) . '.jpg';
  $im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
  if (!$im) fail('Gambar tidak bisa dibaca.');
  $w = imagesx($im); $h = imagesy($im); $S = 400; $side = min($w, $h);
  $out = imagecreatetruecolor($S, $S);
  imagecopyresampled($out, $im, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $S, $S, $side, $side);
  imagejpeg($out, __DIR__ . '/' . $name, 85);
  imagedestroy($im); imagedestroy($out);
  return $name;
}
function cropTo45(string $src, string $dst): bool {
  $info = @getimagesize($src);
  if (!$info || !function_exists('imagecreatetruecolor')) return false;
  $im = @imagecreatefromstring((string)file_get_contents($src));
  if (!$im) return false;
  [$w, $h] = [$info[0], $info[1]]; $W = 960; $H = 1200;
  $scale = max($W / $w, $H / $h); $cw = (int)round($W / $scale); $ch = (int)round($H / $scale);
  $out = imagecreatetruecolor($W, $H);
  imagecopyresampled($out, $im, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), $W, $H, $cw, $ch);
  $ok = imagejpeg($out, $dst, 82);
  imagedestroy($im); imagedestroy($out);
  return $ok;
}
function finalizeTempImage(string $temp): string {
  $name = 'uploads/' . bin2hex(random_bytes(8)) . '.jpg';
  if (!cropTo45(__DIR__ . '/' . $temp, __DIR__ . '/' . $name)) {
    if (!@rename(__DIR__ . '/' . $temp, __DIR__ . '/' . $name)) fail('Gambar tidak bisa disimpan di server.', 500);
  }
  @unlink(__DIR__ . '/' . $temp);
  return $name;
}
/** simpan file upload apa adanya (dikecilkan maks 1600px) untuk hasil tes internal */
function saveTestImage(array $f, string $prefix = 't_'): string {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Upload gambar gagal. Coba file lain.');
  if ($f['size'] > 12 * 1024 * 1024) fail('Gambar terlalu besar. Maksimal 12 MB.');
  $info = @getimagesize($f['tmp_name']);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('File harus gambar JPG, PNG, atau WEBP.');
  return saveBytesImage((string)file_get_contents($f['tmp_name']), $prefix);
}
function saveBytesImage(string $bytes, string $prefix = 't_'): string {
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = 'uploads/' . $prefix . bin2hex(random_bytes(8)) . '.jpg';
  $im = @imagecreatefromstring($bytes);
  if (!$im) fail('Gambar tidak bisa dibaca.');
  $w = imagesx($im); $h = imagesy($im); $max = 1600;
  if ($w > $max || $h > $max) {
    $s = $max / max($w, $h); $nw = (int)round($w * $s); $nh = (int)round($h * $s);
    $o = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($o, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im); $im = $o;
  }
  imagejpeg($im, __DIR__ . '/' . $name, 85);
  imagedestroy($im);
  return $name;
}
/** Path gambar yang boleh dipakai ulang sebagai masukan AI: uploads/ atau model bawaan img/models/ (tanpa ../, harus ada). */
function uploadPath(string $p): string {
  $ok = preg_match('#^uploads/[A-Za-z0-9_]{8,40}\.jpg$#', $p) || preg_match('#^img/models/[a-z0-9_-]{2,40}\.jpg$#', $p);
  return $ok && is_file(__DIR__ . '/' . $p) ? $p : '';
}
/**
 * Model bawaan: karakter AI FIKTIF (bukan orang sungguhan) di img/models/, daftarnya di models.json.
 * Sengaja hanya orang fiktif — repo ini publik, jadi foto orang asli (tim/model) masuk lewat upload, bukan repo.
 */
function builtinModels(): array {
  $out = [];
  foreach ((array)json_decode((string)@file_get_contents(__DIR__ . '/img/models/models.json'), true) as $m) {
    $f = 'img/models/' . basename((string)($m['file'] ?? ''));
    if (uploadPath($f) !== '') $out[] = ['id' => 0, 'image' => $f, 'label' => (string)($m['label'] ?? ''), 'builtin' => true];
  }
  return $out;
}
/** Pustaka wajah: upload admin (bisa dihapus) → model AI bawaan → foto input Tes generate lama. */
function facesList(): array {
  $out = []; $seen = [];
  foreach (db()->query('SELECT id, image FROM faces ORDER BY id DESC LIMIT 40')->fetchAll() as $r) {
    if (!is_file(__DIR__ . '/' . $r['image'])) continue;
    $out[] = ['id' => (int)$r['id'], 'image' => $r['image']]; $seen[$r['image']] = 1;
  }
  $out = array_merge($out, builtinModels());
  $tests = db()->query("SELECT input_image, MAX(id) AS m FROM prompt_tests WHERE input_image LIKE 'uploads/%' GROUP BY input_image ORDER BY m DESC LIMIT 20")->fetchAll();
  foreach ($tests as $r) {
    if (isset($seen[$r['input_image']]) || !is_file(__DIR__ . '/' . $r['input_image'])) continue;
    $out[] = ['id' => 0, 'image' => $r['input_image']];
  }
  return $out;
}
function publicTest(array $t): array {
  return ['id' => (int)$t['id'], 'promptId' => $t['prompt_id'], 'image' => $t['image'], 'input' => (string)$t['input_image'],
    'source' => $t['source'], 'model' => (string)$t['model'], 'tool' => (string)$t['tool'], 'status' => (string)$t['status'],
    'note' => (string)$t['note'], 'createdAt' => $t['created_at']];
}
function jktDay(string $iso): string {
  try { return (new DateTime($iso))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d'); } catch (Throwable $e) { return substr($iso, 0, 10); }
}
function dayRange(int $days): array {
  $out = []; $d = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
  for ($i = $days - 1; $i >= 0; $i--) { $x = clone $d; $x->modify("-$i day"); $out[] = $x->format('Y-m-d'); }
  return $out;
}
function deviceOf(string $ua): string {
  if (preg_match('/iPad|Tablet/i', $ua)) return 'tablet';
  if (preg_match('/Mobi|Android|iPhone/i', $ua)) return 'mobile';
  return 'desktop';
}
function cleanTag($v, int $max = 80): string { return mb_substr(trim(preg_replace('/[\x00-\x1F<>]/u', '', (string)$v)), 0, $max); }

/* ---------------- routes ---------------- */
$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if ($method === 'POST' && !in_array($a, ['lt'], true) && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.', 419);

try {
  switch ($a) {
    case 'me': {
      $who = currentUser();
      $res = ['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => $who, 'cover' => coverConfig(), 'v' => 'admin-41'];
      if (!$who && !empty($GLOBALS['rf_session_taken'])) $res['sessionTaken'] = true;
      out($res);
    }

    case 'cover_save': {
      requireAdmin();
      @set_time_limit(60);
      $in = input();
      foreach (['title' => 'cover_title', 'sub' => 'cover_sub', 'titleEn' => 'cover_title_en', 'subEn' => 'cover_sub_en', 'chip' => 'cover_chip'] as $field => $key) {
        if (array_key_exists($field, $in)) setSetting($key, mb_substr(trim((string)$in[$field]), 0, 300));
      }
      for ($i = 1; $i <= 3; $i++) {
        $fk = 'img' . $i; $sk = 'cover_img' . $i;
        if (isset($_FILES[$fk]) && ($_FILES[$fk]['error'] ?? 4) === UPLOAD_ERR_OK) { $new = saveImage($_FILES[$fk]); delUpload(setting($sk)); setSetting($sk, $new); }
        elseif (!empty($in['clear' . $i])) { delUpload(setting($sk)); setSetting($sk, ''); }
      }
      out(['ok' => true, 'cover' => coverConfig()]);
    }

    case 'login': {
      if ($method !== 'POST') fail('Metode salah.', 405);
      $in = input(); $user = strtolower(str($in, 'username', 60)); $code = str($in, 'code', 60);
      $pdo = db(); $ip = clientIp(); $since = time() - 15 * 60;
      $pdo->prepare('DELETE FROM attempts WHERE ts < ?')->execute([$since]);
      $st = $pdo->prepare('SELECT COUNT(*) FROM attempts WHERE ip = ?'); $st->execute([$ip]);
      if ((int)$st->fetchColumn() >= 10) fail('Terlalu banyak percobaan. Coba lagi 15 menit lagi.', 429);
      if ($user === '' || $code === '') fail('Isi username dan kode akses dulu.');
      $ok = false; $role = 'member'; $stok = '';
      if ($user === strtolower(ADMIN_USER)) {
        $ok = password_verify($code, setting('admin_hash') ?: ADMIN_HASH); $role = 'admin';
        if (!$ok) { $pdo->prepare('INSERT INTO attempts VALUES (?,?)')->execute([$ip, time()]); fail('Kode akses admin salah.'); }
      } else {
        // Boleh masuk pakai alamat email atau username. Member baru usernamenya memang email,
        // member lama tetap bisa memakai username lamanya.
        $st = $pdo->prepare("SELECT * FROM members WHERE username = ? OR (email IS NOT NULL AND lower(email) = ?) LIMIT 1");
        $st->execute([$user, $user]);
        $m = $st->fetch();
        if (!$m || !password_verify(strtoupper($code), $m['code_hash'])) {
          $pdo->prepare('INSERT INTO attempts VALUES (?,?)')->execute([$ip, time()]);
          fail('Username atau kode akses tidak cocok. Cek lagi pesan konfirmasi pembelianmu.');
        }
        $user = (string)$m['username'];   // sesi selalu menyimpan username asli, bukan yang diketik
        $s = memberStatus($m);
        if ($s === 'off') fail('Akses akun ini sedang nonaktif. Hubungi admin untuk mengaktifkan lagi.');
        if ($s === 'expired') fail('Akses kamu sudah berakhir. Perpanjang paket untuk lanjut.');
        // Satu sesi aktif per akun: token baru membuat perangkat lain otomatis keluar.
        $stok = bin2hex(random_bytes(16));
        $pdo->prepare('UPDATE members SET last_login = ?, session_token = ? WHERE username = ?')->execute([gmdate('c'), $stok, $user]);
        $pdo->prepare('INSERT INTO events (ts, username, type, prompt_id) VALUES (?,?,?,?)')->execute([gmdate('c'), $user, 'login', null]);
      }
      session_regenerate_id(true);
      $_SESSION['user'] = ['role' => $role, 'username' => $user, 'stok' => $stok];
      out(['ok' => true, 'user' => currentUser()]);
    }

    case 'logout':
      unset($_SESSION['user']); session_regenerate_id(true);
      out(['ok' => true]);

    case 'prompts': {
      $u = requireUser();
      // Semua resep selalu dikirim supaya yang terkunci tetap tampil sebagai thumbnail bertanda gembok.
      // Yang membedakan paket adalah isi resepnya, bukan ada/tidaknya kartu di katalog.
      $rows = db()->query('SELECT * FROM prompts ORDER BY ord, id')->fetchAll();
      $isAdmin = $u['role'] === 'admin';
      $cap = isset($u['planCap']) && $u['planCap'] !== null ? (int)$u['planCap'] : null;
      $beku = isset($u['allowIds']) && is_array($u['allowIds']) ? $u['allowIds'] : null;
      $allow = $isAdmin ? null : allowedPromptIds($rows, (string)$u['plan'], (string)$u['username'], $cap, $beku);
      $res = ['ok' => true, 'prompts' => array_map(function (array $r) use ($isAdmin, $allow) {
        return rowToPrompt($r, $isAdmin, $allow !== null && !isset($allow[$r['id']]));
      }, $rows)];
      $res['quota'] = $isAdmin ? null : planQuota((string)$u['plan'], $cap);
      if ($isAdmin) $res['authors'] = promptAuthors(db());
      out($res);
    }

    case 'prompt_save': {
      $me = requireAdmin();
      $in = input(); $pdo = db();
      $id = preg_replace('/[^a-z0-9_-]/i', '', str($in, 'id', 40));
      $title = str($in, 'title', 80); $cat = str($in, 'cat', 40); $prompt = str($in, 'prompt', 6000);
      $old = null;
      if ($id !== '') { $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]); $old = $st->fetch() ?: null; }
      $hasFile = isset($_FILES['image']) && ($_FILES['image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
      $tempImg = (string)($in['image_temp'] ?? '');
      if (!preg_match('#^uploads/tmp_[a-f0-9]{16}\.jpg$#', $tempImg) || !is_file(__DIR__ . '/' . $tempImg)) $tempImg = '';
      $missing = array_filter([$title === '' ? 'judul' : '', $cat === '' ? 'kategori' : '', $prompt === '' ? 'prompt' : '', (!$old && !$hasFile && $tempImg === '') ? 'gambar contoh' : '']);
      if ($missing) fail('Lengkapi dulu: ' . implode(', ', $missing) . '.');
      $image = $old['image'] ?? '';
      if (!$hasFile && $tempImg !== '') {
        $new = finalizeTempImage($tempImg);
        if ($old && strpos((string)$old['image'], 'uploads/') === 0) @unlink(__DIR__ . '/' . $old['image']);
        $image = $new;
      }
      if ($hasFile) {
        $new = saveImage($_FILES['image']);
        if ($old && strpos((string)$old['image'], 'uploads/') === 0) @unlink(__DIR__ . '/' . $old['image']);
        $image = $new;
      }
      $tools = array_values(array_intersect(['Gemini', 'ChatGPT'], (array)json_decode((string)($in['tools'] ?? '[]'), true)));
      if (!$old) {
        $id = 'r' . base_convert((string)time(), 10, 36) . bin2hex(random_bytes(2));
        $ord = (int)$pdo->query('SELECT COALESCE(MAX(ord),0)+1 FROM prompts')->fetchColumn();
      } else $ord = (int)$old['ord'];
      $flag = function ($k) use ($in) { return !empty($in[$k]) && $in[$k] !== '0' ? 1 : 0; };
      $pick = function ($k, array $ok) use ($in) { $v = str($in, $k, 20); return in_array($v, $ok, true) ? $v : ''; };
      $qc = $pick('qc', ['lolos', 'review', 'gagal']);
      $result = $pick('result', ['cocok', 'kurang']);
      // prompt_en: kalau klien lama tidak mengirimnya, nilai lama dipertahankan (INSERT OR REPLACE menulis ulang baris).
      $promptEn = array_key_exists('prompt_en', $in) ? str($in, 'prompt_en', 6000) : (string)($old['prompt_en'] ?? '');
      // Kolom Thai: sama, dipertahankan kalau tidak dikirim.
      $keep = fn($k, $col, $max) => array_key_exists($k, $in) ? str($in, $k, $max) : (string)($old[$col] ?? '');
      $th = [$keep('cat_th', 'cat_th', 60), $keep('title_th', 'title_th', 120), $keep('desc_th', 'descr_th', 240), $keep('tips_th', 'tips_th', 600)];
      $pdo->prepare('INSERT OR REPLACE INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, updated_at, created_at, cat_en, title_en, descr_en, tips_en, created_by, qc_status, result_status, en_only, prompt_en, cat_th, title_th, descr_th, tips_th) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $id, $ord, $cat, $title, str($in, 'desc', 160), $flag('popular'),
        json_encode($tools), $prompt, str($in, 'tips', 400), $image, gmdate('c'), $old['created_at'] ?? gmdate('c'),
        str($in, 'cat_en', 40), str($in, 'title_en', 80), str($in, 'desc_en', 160), str($in, 'tips_en', 400),
        $old ? (string)($old['created_by'] ?? '') : (string)$me['username'], $qc, $result, $flag('en_only'), $promptEn, ...$th]);
      $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]);
      out(['ok' => true, 'prompt' => rowToPrompt($st->fetch(), true)]);
    }

    case 'prompt_cover_from_test': {
      // Jadikan hasil generate/tes sebagai gambar contoh resep.
      requireAdmin();
      $in = input(); $pdo = db();
      $tid = (int)($in['test_id'] ?? 0);
      $st = $pdo->prepare('SELECT prompt_id, image FROM prompt_tests WHERE id = ?'); $st->execute([$tid]);
      $t = $st->fetch();
      if (!$t) fail('Hasil tes tidak ditemukan.', 404);
      $rel = (string)$t['image'];
      $src = __DIR__ . '/' . $rel;
      if (strpos($rel, 'uploads/') !== 0 || !is_file($src)) fail('Gambar hasil tes sudah tidak ada di server.', 404);
      $name = 'uploads/' . bin2hex(random_bytes(8)) . '.jpg';
      // Disalin, bukan dipindah: galeri tes harus tetap utuh sesudah gambarnya dipakai jadi cover.
      if (!cropTo45($src, __DIR__ . '/' . $name) && !@copy($src, __DIR__ . '/' . $name)) fail('Gambar tidak bisa disimpan di server.', 500);
      $ps = $pdo->prepare('SELECT image FROM prompts WHERE id = ?'); $ps->execute([$t['prompt_id']]);
      $old = (string)$ps->fetchColumn();
      $pdo->prepare('UPDATE prompts SET image = ?, updated_at = ? WHERE id = ?')->execute([$name, gmdate('c'), $t['prompt_id']]);
      if ($old !== $name) delUpload($old);          // cover bawaan img/ tidak tersentuh
      $ps = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $ps->execute([$t['prompt_id']]);
      out(['ok' => true, 'prompt' => rowToPrompt($ps->fetch(), true)]);
    }

    case 'prompt_review': {
      // Ubah status QC / penilaian hasil langsung dari daftar, tanpa membuka form penuh.
      // Sengaja terpisah dari prompt_save supaya tidak perlu mengirim ulang gambar & prompt.
      requireAdmin();
      $in = input(); $pdo = db();
      $id = str($in, 'id', 40);
      $st = $pdo->prepare('SELECT id FROM prompts WHERE id = ?'); $st->execute([$id]);
      if (!$st->fetchColumn()) fail('Resep tidak ditemukan.', 404);
      if (array_key_exists('qc', $in)) {
        $v = str($in, 'qc', 20);
        $pdo->prepare('UPDATE prompts SET qc_status = ? WHERE id = ?')
          ->execute([in_array($v, ['lolos', 'review', 'gagal'], true) ? $v : '', $id]);
      }
      if (array_key_exists('result', $in)) {
        $v = str($in, 'result', 20);
        $pdo->prepare('UPDATE prompts SET result_status = ? WHERE id = ?')
          ->execute([in_array($v, ['cocok', 'kurang'], true) ? $v : '', $id]);
      }
      $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]);
      out(['ok' => true, 'prompt' => rowToPrompt($st->fetch(), true)]);
    }

    case 'prompt_delete': {
      $me = requireAdmin();
      $id = str(input(), 'id', 40); $pdo = db();
      $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]);
      $row = $st->fetch();
      if (!$row) fail('Resep tidak ditemukan.', 404);
      // Pindah ke tempat sampah, bukan dihapus. Gambar dan galeri tesnya sengaja
      // dibiarkan utuh supaya pemulihan mengembalikan resep persis seperti semula.
      $pdo->prepare('INSERT OR REPLACE INTO prompts_trash (id, data, deleted_at, deleted_by) VALUES (?,?,?,?)')
        ->execute([$id, json_encode($row, JSON_UNESCAPED_UNICODE), gmdate('c'), (string)$me['username']]);
      $pdo->prepare('DELETE FROM prompts WHERE id = ?')->execute([$id]);
      out(['ok' => true, 'trashed' => true]);
    }

    case 'trash': {
      requireAdmin();
      $rows = db()->query('SELECT * FROM prompts_trash ORDER BY deleted_at DESC LIMIT 200')->fetchAll();
      $list = [];
      foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true);
        if (!is_array($d)) continue;
        $list[] = ['id' => $r['id'], 'title' => (string)($d['title'] ?? $r['id']), 'cat' => (string)($d['cat'] ?? ''),
          'image' => (string)($d['image'] ?? ''), 'deletedAt' => (string)$r['deleted_at'], 'deletedBy' => (string)$r['deleted_by']];
      }
      out(['ok' => true, 'trash' => $list]);
    }

    case 'trash_restore': {
      requireAdmin();
      $id = str(input(), 'id', 40); $pdo = db();
      $st = $pdo->prepare('SELECT data FROM prompts_trash WHERE id = ?'); $st->execute([$id]);
      $d = json_decode((string)$st->fetchColumn(), true);
      if (!is_array($d) || empty($d['id'])) fail('Isi tempat sampah tidak bisa dibaca.', 404);
      $cols = array_keys($d);
      $sql = 'INSERT OR REPLACE INTO prompts (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
      $pdo->prepare($sql)->execute(array_values($d));
      $pdo->prepare('DELETE FROM prompts_trash WHERE id = ?')->execute([$id]);
      $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]);
      out(['ok' => true, 'prompt' => rowToPrompt($st->fetch(), true)]);
    }

    case 'trash_purge': {
      requireAdmin();
      $in = input(); $pdo = db();
      $id = str($in, 'id', 40);
      $rows = $id !== '' ? [['id' => $id]] : $pdo->query('SELECT id FROM prompts_trash')->fetchAll();
      foreach ($rows as $r) {
        $rid = (string)$r['id'];
        $st = $pdo->prepare('SELECT data FROM prompts_trash WHERE id = ?'); $st->execute([$rid]);
        $d = json_decode((string)$st->fetchColumn(), true);
        // baru di sinilah file benar-benar dibuang
        if (is_array($d)) delUpload((string)($d['image'] ?? ''));
        $ts = $pdo->prepare('SELECT image, input_image FROM prompt_tests WHERE prompt_id = ?'); $ts->execute([$rid]);
        foreach ($ts->fetchAll() as $t) foreach ([$t['image'], $t['input_image']] as $f) delUpload((string)$f);
        $pdo->prepare('DELETE FROM prompt_tests WHERE prompt_id = ?')->execute([$rid]);
        $pdo->prepare('DELETE FROM prompts_trash WHERE id = ?')->execute([$rid]);
      }
      out(['ok' => true, 'purged' => count($rows)]);
    }
    /* ---------- ekspor & impor resep lewat Excel ----------
     * Dua endpoint ini memegang seluruh katalog sekaligus, jadi aturannya ketat:
     *
     * 1. IMPOR TIDAK PERNAH MENGHAPUS. Resep yang tidak ada di file dibiarkan apa
     *    adanya. Satu-satunya jalan menghapus tetap lewat tombol hapus per resep
     *    (yang memindahkannya ke tempat sampah, bukan membuangnya).
     * 2. Baris dicocokkan lewat kolom id. id yang tidak dikenal DITOLAK, bukan
     *    dibuatkan resep baru -- supaya satu typo tidak diam-diam melahirkan
     *    resep sampah ber-id aneh. Resep baru dibuat dengan mengosongkan id.
     * 3. tanggal_unggah resep lama DIABAIKAN. Kolom itu ikut jatah Trial yang
     *    dihitung dengan ORDER BY created_at DESC, jadi mengubahnya bisa
     *    menggeser katalog member yang sudah jalan. Lihat catatan jatah di
     *    CLAUDE.md. Untuk resep baru, tanggalnya dipakai kalau diisi.
     * 4. penulis ikut aturan lama: created_by dipertahankan saat resep disunting
     *    admin lain, jadi kolomnya diabaikan untuk resep yang sudah ada.
     * 5. gambar kosong = pertahankan gambar lama. File gambar tidak bisa dibuat
     *    dari spreadsheet, dan mengosongkannya akan merusak kartu resep.
     * 6. Selain pengecualian di atas, kolom yang ADA di file bersifat menentukan:
     *    sel kosong berarti nilainya memang dikosongkan. Itu yang bikin
     *    ekspor -> sunting -> impor jadi pulang-pergi yang bisa ditebak.
     *
     * Selalu ada mode pratinjau (dryRun) supaya admin melihat dampaknya dulu.
     */
    case 'prompts_export': {
      requireSuperAdmin();
      require_once __DIR__ . '/xlsx.php';
      $cols = promptSheetCols();
      $rows = [];
      foreach (db()->query('SELECT * FROM prompts ORDER BY ord') as $r) $rows[] = promptToSheetRow($r);
      $tmp = (string)tempnam(sys_get_temp_dir(), 'rfx');
      try {
        // kolom "urutan" ditulis sebagai angka supaya bisa diurutkan benar di Excel
        xlsxWrite($tmp, array_keys($cols), $rows, [1], array_values($cols));
      } catch (Throwable $e) { @unlink($tmp); fail($e->getMessage(), 500); }
      $nama = 'resepfoto-resep-' . gmdate('Y-m-d') . '.xlsx';
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="' . $nama . '"');
      header('Content-Length: ' . (string)filesize($tmp));
      header('Cache-Control: no-store');
      readfile($tmp);
      @unlink($tmp);
      exit;
    }

    case 'prompts_import': {
      $me = requireSuperAdmin();
      require_once __DIR__ . '/xlsx.php';
      $f = $_FILES['file'] ?? null;
      if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('Pilih dulu file Excel yang mau diimpor.');
      if ((int)($f['size'] ?? 0) > 8 * 1024 * 1024) fail('File terlalu besar. Maksimal 8 MB.');
      $dry = !empty($_POST['dryRun']) && $_POST['dryRun'] !== '0';
      $ext = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
      try {
        $grid = $ext === 'csv' ? csvRead((string)$f['tmp_name']) : xlsxRead((string)$f['tmp_name']);
      } catch (Throwable $e) { fail($e->getMessage(), 422); }
      if (!$grid) fail('File itu kosong.');

      $known = promptSheetCols();
      $head = array_shift($grid);
      $map = [];
      foreach ($head as $i => $h) {
        $k = strtolower(trim((string)$h));
        $k = (string)preg_replace('/\s+/', '_', $k);
        if (isset($known[$k]) && !isset($map[$k])) $map[$k] = $i;
      }
      foreach (['judul', 'kategori', 'prompt'] as $wajib) {
        if (!isset($map[$wajib])) fail('Kolom wajib "' . $wajib . '" tidak ada di file itu. Unduh dulu contohnya lewat tombol Ekspor.');
      }
      if (count($grid) > 5000) fail('Terlalu banyak baris. Maksimal 5000 baris data per impor.');

      $sel = function (array $row, string $key) use ($map) {
        if (!isset($map[$key])) return null;                 // kolom tidak ada = jangan ubah
        return trim((string)($row[$map[$key]] ?? ''));
      };
      $ya = function ($v) { return in_array(strtolower(trim((string)$v)), ['ya', 'y', 'yes', '1', 'true', 'x', 'v'], true) ? 1 : 0; };
      $batas = ['judul' => 80, 'kategori' => 40, 'deskripsi' => 160, 'prompt' => 6000, 'tips' => 400,
        'judul_en' => 80, 'kategori_en' => 40, 'deskripsi_en' => 160, 'tips_en' => 400];

      $pdo = db();
      $adaId = [];
      foreach ($pdo->query('SELECT id FROM prompts') as $r) $adaId[(string)$r['id']] = true;

      $tambah = []; $ubah = []; $galat = []; $ringkas = [];
      $abaiTanggal = 0; $abaiPenulis = 0; $terlihat = [];
      foreach ($grid as $n => $row) {
        $baris = $n + 2;                                     // +1 header, +1 karena Excel mulai dari 1
        $isi = implode('', array_map('strval', $row));
        if (trim($isi) === '') continue;                     // baris kosong dilewati diam-diam
        $id = (string)preg_replace('/[^a-z0-9_-]/i', '', (string)$sel($row, 'id'));
        $judul = (string)$sel($row, 'judul');
        $kat = (string)$sel($row, 'kategori');
        $prompt = (string)$sel($row, 'prompt');
        $err = null;
        if ($id !== '' && isset($terlihat[$id])) $err = 'id "' . $id . '" muncul dua kali di file ini (baris ' . $terlihat[$id] . ').';
        elseif ($id !== '' && !isset($adaId[$id])) $err = 'id "' . $id . '" tidak ada di katalog. Kosongkan kolom id kalau ini resep baru.';
        elseif ($judul === '') $err = 'judul kosong.';
        elseif ($kat === '') $err = 'kategori kosong.';
        elseif ($prompt === '') $err = 'prompt kosong.';
        if (!$err) foreach ($batas as $k => $maks) {
          $v = $sel($row, $k);
          if ($v !== null && mb_strlen($v) > $maks) { $err = 'kolom ' . $k . ' terlalu panjang (' . mb_strlen($v) . ' karakter, maksimal ' . $maks . ').'; break; }
        }
        $qc = $sel($row, 'status_qc'); $hasil = $sel($row, 'hasil');
        if (!$err && $qc !== null && $qc !== '' && !in_array($qc, ['lolos', 'review', 'gagal'], true)) $err = 'status_qc harus kosong, lolos, review, atau gagal.';
        if (!$err && $hasil !== null && $hasil !== '' && !in_array($hasil, ['cocok', 'kurang'], true)) $err = 'hasil harus kosong, cocok, atau kurang.';
        $gambar = $sel($row, 'gambar');
        if (!$err && $gambar !== null && $gambar !== '') {
          if (!preg_match('#^(img|uploads)/[\w.-]+$#', $gambar) || !is_file(__DIR__ . '/' . $gambar)) {
            $err = 'gambar "' . $gambar . '" tidak ada di server. Isi apa adanya dari hasil ekspor, atau kosongkan untuk mempertahankan gambar lama.';
          }
        }
        if (!$err && $id === '' && ($gambar === null || $gambar === '')) $err = 'resep baru wajib punya gambar yang sudah ada di server (kolom gambar).';
        if ($err) { $galat[] = ['baris' => $baris, 'pesan' => $err]; continue; }
        if ($id !== '') $terlihat[$id] = $baris;

        $lama = null;
        if ($id !== '') { $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]); $lama = $st->fetch() ?: null; }
        $alat = $sel($row, 'alat');
        $alatArr = $alat === null
          ? (array)json_decode((string)($lama['tools'] ?? '[]'), true)
          : array_values(array_intersect(['Gemini', 'ChatGPT'], array_map('trim', explode(',', $alat))));
        $ambil = function (string $key, string $kolomLama) use ($sel, $row, $lama) {
          $v = $sel($row, $key);
          return $v === null ? (string)($lama[$kolomLama] ?? '') : $v;
        };
        $urut = $sel($row, 'urutan');
        $bs = $sel($row, 'best_seller'); $eo = $sel($row, 'english_saja');
        $rec = [
          'id' => $id,
          'ord' => ($urut === null || $urut === '') ? ($lama ? (int)$lama['ord'] : 0) : (int)round((float)$urut),
          'cat' => $kat, 'title' => $judul, 'descr' => $ambil('deskripsi', 'descr'), 'prompt' => $prompt,
          'tips' => $ambil('tips', 'tips'), 'cat_en' => $ambil('kategori_en', 'cat_en'),
          'title_en' => $ambil('judul_en', 'title_en'), 'descr_en' => $ambil('deskripsi_en', 'descr_en'),
          'tips_en' => $ambil('tips_en', 'tips_en'),
          'tools' => json_encode(array_values($alatArr)),
          'popular' => $bs === null ? (int)($lama['popular'] ?? 0) : $ya($bs),
          'en_only' => $eo === null ? (int)($lama['en_only'] ?? 0) : $ya($eo),
          'image' => ($gambar === null || $gambar === '') ? (string)($lama['image'] ?? '') : $gambar,
          'qc_status' => $qc === null ? (string)($lama['qc_status'] ?? '') : $qc,
          'result_status' => $hasil === null ? (string)($lama['result_status'] ?? '') : $hasil,
        ];
        if ($lama) {
          // pengecualian yang disengaja: tanggal unggah & penulis resep lama tidak ikut berubah
          $tgl = $sel($row, 'tanggal_unggah');
          if ($tgl !== null && $tgl !== '' && $tgl !== (string)($lama['created_at'] ?? '')) $abaiTanggal++;
          $pen = $sel($row, 'penulis');
          if ($pen !== null && $pen !== '' && $pen !== (string)($lama['created_by'] ?? '')) $abaiPenulis++;
          $rec['created_at'] = (string)($lama['created_at'] ?? gmdate('c'));
          $rec['created_by'] = (string)($lama['created_by'] ?? '');
          $ubah[] = $rec;
        } else {
          $tgl = (string)$sel($row, 'tanggal_unggah');
          $rec['created_at'] = preg_match('/^\d{4}-\d{2}-\d{2}([T ]|$)/', $tgl) ? $tgl : gmdate('c');
          $rec['created_by'] = (string)$me['username'];
          $tambah[] = $rec;
        }
        if (count($ringkas) < 20) $ringkas[] = ['baris' => $baris, 'aksi' => $lama ? 'perbarui' : 'tambah', 'judul' => $judul];
      }

      if (!$dry && ($tambah || $ubah)) {
        $pdo->beginTransaction();
        try {
          $maxOrd = (int)$pdo->query('SELECT COALESCE(MAX(ord),0) FROM prompts')->fetchColumn();
          $up = $pdo->prepare('UPDATE prompts SET ord=?, cat=?, title=?, descr=?, popular=?, tools=?, prompt=?, tips=?, image=?, updated_at=?, cat_en=?, title_en=?, descr_en=?, tips_en=?, qc_status=?, result_status=?, en_only=? WHERE id=?');
          foreach ($ubah as $r) {
            $up->execute([$r['ord'], $r['cat'], $r['title'], $r['descr'], $r['popular'], $r['tools'], $r['prompt'],
              $r['tips'], $r['image'], gmdate('c'), $r['cat_en'], $r['title_en'], $r['descr_en'], $r['tips_en'],
              $r['qc_status'], $r['result_status'], $r['en_only'], $r['id']]);
          }
          $ins = $pdo->prepare('INSERT INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, updated_at, created_at, cat_en, title_en, descr_en, tips_en, created_by, qc_status, result_status, en_only) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
          foreach ($tambah as $r) {
            $nid = 'r' . base_convert((string)time(), 10, 36) . bin2hex(random_bytes(2));
            $ord = $r['ord'] > 0 ? $r['ord'] : ++$maxOrd;
            $ins->execute([$nid, $ord, $r['cat'], $r['title'], $r['descr'], $r['popular'], $r['tools'], $r['prompt'],
              $r['tips'], $r['image'], gmdate('c'), $r['created_at'], $r['cat_en'], $r['title_en'], $r['descr_en'],
              $r['tips_en'], $r['created_by'], $r['qc_status'], $r['result_status'], $r['en_only']]);
          }
          $pdo->commit();
        } catch (Throwable $e) {
          $pdo->rollBack();
          error_log('[resepfoto] impor gagal: ' . $e->getMessage());
          fail('Impor dibatalkan, tidak ada satu pun yang berubah. Coba periksa lagi isi filenya.', 500);
        }
      }
      out(['ok' => true, 'dryRun' => $dry, 'tambah' => count($tambah), 'perbarui' => count($ubah),
        'lewat' => count($galat), 'abaiTanggal' => $abaiTanggal, 'abaiPenulis' => $abaiPenulis,
        'galat' => array_slice($galat, 0, 50), 'ringkas' => $ringkas]);
    }

    case 'recent_orders': {
      // publik: aktivitas ASLI untuk notifikasi di halaman iklan — tidak boleh dikarang.
      //   orders = pesanan Mayar 14 hari terakhir, nama disamarkan
      //   cart   = pengunjung yang benar-benar membuka checkout 24 jam terakhir, anonim (dari lt_events)
      $pdo = db();
      $st = $pdo->prepare("SELECT name, plan, state, created_at FROM orders WHERE created_at >= ? AND state IN ('aktif','lunas','perlu_cek','belum_lunas') ORDER BY created_at DESC LIMIT 20");
      $st->execute([gmdate('c', time() - 14 * 86400)]);
      $list = [];
      foreach ($st->fetchAll() as $r) {
        $first = trim(explode(' ', trim((string)$r['name']))[0] ?? '');
        if ($first === '') continue;
        $masked = mb_strtoupper(mb_substr($first, 0, 1)) . str_repeat('*', max(3, min(6, mb_strlen($first) - 1)));
        $list[] = ['name' => $masked, 'plan' => (string)$r['plan'], 'paid' => $r['state'] !== 'belum_lunas', 'at' => (string)$r['created_at']];
      }
      // keranjang: satu baris per pengunjung, peristiwa checkout/pay terakhir miliknya.
      // Kolom telanjang di query agregat SQLite mengambil baris milik MAX(id) — id dipakai, bukan ts,
      // karena ts hanya beresolusi detik sehingga dua peristiwa bisa seri dan barisnya jadi acak.
      $page = cleanTag($_GET['page'] ?? 'promo', 30);
      $cs = $pdo->prepare("SELECT vid, MAX(id) AS mid, ts, plan, type FROM lt_events WHERE type IN ('checkout','pay') AND ts >= ? AND page = ? GROUP BY vid ORDER BY mid DESC LIMIT 12");
      $cs->execute([gmdate('c', time() - 86400), $page]);
      $cart = [];
      foreach ($cs->fetchAll() as $r) {
        $plan = ucfirst(strtolower((string)$r['plan']));
        if ($plan !== 'Standard' && $plan !== 'Premium') continue;   // tanpa paket yang jelas, tidak ditampilkan
        $cart[] = ['plan' => $plan, 'pay' => $r['type'] === 'pay', 'at' => (string)$r['ts']];
      }
      header('Cache-Control: public, max-age=60');
      out(['ok' => true, 'orders' => $list, 'cart' => $cart]);
    }

    case 'members': {
      requireAdmin();
      $rows = db()->query("SELECT * FROM members WHERE COALESCE(role,'member') = 'member' ORDER BY name COLLATE NOCASE")->fetchAll();
      out(['ok' => true, 'members' => array_map('publicMember', $rows)]);
    }

    /* ---------- kelola admin (super admin) ---------- */
    case 'admins': {
      $me = requireSuperAdmin();
      $rows = db()->query("SELECT * FROM members WHERE role IN ('admin','super_admin') ORDER BY role DESC, name COLLATE NOCASE")->fetchAll();
      $list = [['username' => ADMIN_USER, 'name' => 'Admin Utama', 'role' => 'super_admin', 'active' => true, 'builtin' => true, 'codeHint' => '', 'lastLogin' => null, 'self' => $me['username'] === ADMIN_USER]];
      foreach ($rows as $m) { $p = publicMember($m); $p['builtin'] = false; $p['self'] = $m['username'] === $me['username']; $list[] = $p; }
      out(['ok' => true, 'admins' => $list, 'me' => ['username' => $me['username'], 'role' => $me['adminRole']]]);
    }

    case 'admin_save': {
      $me = requireSuperAdmin();
      $in = input(); $pdo = db();
      $isNew = empty($in['editing']);
      $username = strtolower(str($in, 'username', 30)); $name = str($in, 'name', 80);
      $role = in_array($in['role'] ?? '', ['admin', 'super_admin'], true) ? $in['role'] : 'admin';
      if ($name === '' || $username === '') fail('Nama dan username wajib diisi.');
      if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) fail('Username 3–30 karakter: huruf kecil, angka, titik, garis bawah, atau strip.');
      if ($username === strtolower(ADMIN_USER)) fail('Tidak bisa mengubah super admin utama.');
      $st = $pdo->prepare('SELECT * FROM members WHERE username = ?'); $st->execute([$username]);
      $old = $st->fetch();
      if ($isNew && $old) fail('Username sudah dipakai.');
      if (!$isNew && !$old) fail('Akun admin tidak ditemukan.', 404);
      if ($old && ($old['role'] ?? 'member') === 'member') fail('Username sudah dipakai.'); // jangan bajak akun member jadi admin diam-diam
      $newCode = null;
      if ($isNew || !empty($in['resetCode'])) $newCode = strtoupper(str($in, 'code', 40)) ?: genCode();
      $hash = $newCode ? password_hash($newCode, PASSWORD_DEFAULT) : $old['code_hash'];
      $hint = $newCode ? substr($newCode, -4) : $old['code_hint'];
      saveMember(['username' => $username, 'name' => $name, 'code_hash' => $hash, 'code_hint' => $hint,
        'plan' => 'Admin', 'expires' => '', 'active' => !empty($in['active']) ? 1 : 0,
        'created_at' => $old['created_at'] ?? gmdate('c'), 'last_login' => $old['last_login'] ?? null,
        'email' => str($in, 'email', 120) ?: ($old['email'] ?? ''), 'phone' => $old['phone'] ?? '', 'role' => $role,
        'avatar' => $old['avatar'] ?? ''], $pdo);
      if ($newCode) $pdo->prepare('UPDATE members SET session_token = ? WHERE username = ?')->execute([newSessionToken(), $username]);
      $st->execute([$username]);
      $p = publicMember($st->fetch()); $p['builtin'] = false; $p['self'] = false;
      out(['ok' => true, 'admin' => $p, 'code' => $newCode]);
    }

    case 'admin_change_code': {
      requireSuperAdmin();
      if (($_SESSION['user']['role'] ?? '') !== 'admin') fail('Hanya untuk akun admin utama.');
      $in = input(); $cur = str($in, 'current', 80); $new = str($in, 'code', 80);
      if (!password_verify($cur, setting('admin_hash') ?: ADMIN_HASH)) fail('Kode akses saat ini salah.');
      if (strlen($new) < 8) fail('Kode baru minimal 8 karakter.');
      setSetting('admin_hash', password_hash($new, PASSWORD_DEFAULT));
      out(['ok' => true]);
    }

    case 'admin_delete': {
      $me = requireSuperAdmin();
      $username = strtolower(str(input(), 'username', 30));
      if ($username === strtolower(ADMIN_USER) || $username === $me['username']) fail('Tidak bisa menghapus akun ini.');
      $st = db()->prepare("SELECT role, avatar FROM members WHERE username = ?"); $st->execute([$username]);
      $row = $st->fetch(); $role = (string)($row['role'] ?? '');
      if ($role !== 'admin' && $role !== 'super_admin') fail('Akun admin tidak ditemukan.', 404);
      db()->prepare('DELETE FROM members WHERE username = ?')->execute([$username]);
      delUpload($row['avatar'] ?? '');
      out(['ok' => true]);
    }

    case 'member_save': {
      requireAdmin();
      $in = input(); $pdo = db();
      $isNew = empty($in['editing']);
      $username = strtolower(str($in, 'username', 30)); $name = str($in, 'name', 80);
      $plan = in_array($in['plan'] ?? '', PLAN_ALL, true) ? $in['plan'] : 'Premium';
      $expires = in_array($plan, PLAN_LIFETIME, true) ? '' : str($in, 'expires', 10);
      if ($name === '' || $username === '') fail('Nama dan username wajib diisi.');
      if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) fail('Username 3–30 karakter: huruf kecil, angka, titik, garis bawah, atau strip.');
      if ($username === strtolower(ADMIN_USER)) fail('Username itu dipakai sistem. Pilih yang lain.');
      if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) fail('Format tanggal berlaku tidak valid.');
      if (!in_array($plan, PLAN_LIFETIME, true) && $expires === '') fail('Isi tanggal berlaku untuk paket ' . $plan . '.');
      $st = $pdo->prepare('SELECT * FROM members WHERE username = ?'); $st->execute([$username]);
      $old = $st->fetch();
      if ($isNew && $old) fail('Username sudah dipakai member lain.');
      if (!$isNew && !$old) fail('Member tidak ditemukan.', 404);
      if ($old && ($old['role'] ?? 'member') !== 'member') fail('Akun ini dikelola di tab Admin.');
      $newCode = null;
      if ($isNew || !empty($in['resetCode'])) $newCode = strtoupper(str($in, 'code', 40)) ?: genCode();
      $hash = $newCode ? password_hash($newCode, PASSWORD_DEFAULT) : $old['code_hash'];
      $hint = $newCode ? substr($newCode, -4) : $old['code_hint'];
      $email = str($in, 'email', 120);
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Format email tidak valid.');
      $avatar = $old['avatar'] ?? '';
      if (isset($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? 4) === UPLOAD_ERR_OK) { $new = saveAvatar($_FILES['avatar']); delUpload($avatar); $avatar = $new; }
      elseif (!empty($in['clearAvatar'])) { delUpload($avatar); $avatar = ''; }
      saveMember(['username' => $username, 'name' => $name, 'code_hash' => $hash, 'code_hint' => $hint, 'plan' => $plan,
        'expires' => $expires, 'active' => !empty($in['active']) ? 1 : 0, 'created_at' => $old['created_at'] ?? gmdate('c'),
        'last_login' => $old['last_login'] ?? null, 'email' => $email !== '' ? $email : ($old['email'] ?? ''),
        'phone' => str($in, 'phone', 30) ?: ($old['phone'] ?? ''), 'role' => 'member', 'avatar' => $avatar], $pdo);
      // kode akses diganti admin -> semua perangkat yang masih masuk harus keluar
      if ($newCode) $pdo->prepare('UPDATE members SET session_token = ? WHERE username = ?')->execute([newSessionToken(), $username]);
      $st->execute([$username]);
      out(['ok' => true, 'member' => publicMember($st->fetch()), 'code' => $newCode]);
    }

    case 'orders': {
      requireSuperAdmin();
      $rows = db()->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200')->fetchAll();
      $log = db()->query('SELECT ts, ip, event, verified, headers, note FROM webhook_log ORDER BY id DESC LIMIT 15')->fetchAll();
      // Teks siap salin dibentuk di server dari template yang sama dengan email & WA,
      // supaya tombol "Salin pesan WA" tidak pernah berbeda isi dengan yang dikirim.
      $daftar = array_map(function ($r) {
        $o = publicOrder($r);
        $o['message'] = $r['state'] === 'aktif' ? accessMessage($r) : pendingMessage($r);
        return $o;
      }, $rows);
      out(['ok' => true, 'orders' => $daftar, 'log' => $log, 'settings' => [
        'hasToken' => setting('mayar_webhook_token') !== '',
        'adminEmail' => setting('admin_email'), 'mailFrom' => setting('mail_from', 'no-reply@kitlab.id'),
        'msgPaidSubject' => msgTpl('msg_paid_subject', MSG_PAID_SUBJECT), 'msgPaid' => msgTpl('msg_paid', MSG_PAID),
        'msgPendingSubject' => msgTpl('msg_pending_subject', MSG_PENDING_SUBJECT), 'msgPending' => msgTpl('msg_pending', MSG_PENDING),
        'webhookUrl' => siteUrl() . 'webhook-mayar.php',
        'autoWithoutToken' => setting('auto_without_token') === '1',
        'smtpHost' => setting('smtp_host'), 'smtpPort' => (int)setting('smtp_port', '587'),
        'smtpSecure' => setting('smtp_secure', 'tls'), 'smtpUser' => setting('smtp_user'),
        'hasSmtpPass' => setting('smtp_pass') !== '',
        'hasFonnte' => setting('fonnte_token') !== '', 'adminWa' => setting('admin_wa'),
      ]]);
    }

    case 'order_action': {
      requireSuperAdmin();
      $in = input(); $id = str($in, 'id', 80); $act = str($in, 'action', 20);
      $o = findOrder($id);
      if (!$o) fail('Pesanan tidak ditemukan.', 404);
      if ($act === 'approve') {
        if ($o['state'] === 'aktif') fail('Pesanan ini sudah aktif.');
        $o = fulfillOrder($id, true);
      } elseif ($act === 'resend') {
        if ($o['state'] !== 'aktif') fail('Aktifkan pesanan dulu.');
        if (!sendAccessEmail($id)) fail('Email gagal dikirim server. Salin pesan lalu kirim lewat WhatsApp.');
      } elseif ($act === 'newcode') {
        if ($o['state'] !== 'aktif' || !$o['username']) fail('Aktifkan pesanan dulu.');
        $m = findMember($o['username']);
        if (!$m) fail('Member untuk pesanan ini sudah dihapus.');
        $code = genCode();
        $m['code_hash'] = password_hash($code, PASSWORD_DEFAULT); $m['code_hint'] = substr($code, -4);
        saveMember($m);
        updateOrder($id, ['code' => $code, 'note' => 'Kode akses dibuat ulang.']);
      } elseif ($act === 'remind') {
        // Pengingat untuk pesanan yang BELUM lunas. Pengaman: maksimal 2 kali per
        // pesanan dan jeda minimal 24 jam, supaya tidak jadi spam dan tidak membakar
        // kuota Fonnte. Penanda ditulis DULU baru dikirim, jadi klik ganda tidak
        // menghasilkan dua pesan.
        // Pengaman dan catatannya tinggal di autoRemind(), dipakai bersama webhook
        // payment.reminder supaya perilakunya tidak mungkin berbeda.
        $hasil = autoRemind($id);
        if (!$hasil['ok']) fail($hasil['alasan']);
      } elseif ($act === 'reject') {
        if ($o['state'] === 'aktif') fail('Pesanan aktif tidak bisa ditolak. Nonaktifkan membernya dari tab Member.');
        updateOrder($id, ['state' => 'ditolak', 'note' => 'Ditolak admin.']);
      } else fail('Aksi tidak dikenal.');
      $o = findOrder($id);
      out(['ok' => true, 'order' => publicOrder($o), 'message' => $o['state'] === 'aktif' ? accessMessage($o) : '']);
    }

    case 'settings_save': {
      requireSuperAdmin();
      $in = input();
      if (array_key_exists('token', $in) && trim((string)$in['token']) !== '') setSetting('mayar_webhook_token', trim((string)$in['token']));
      if (!empty($in['clearToken'])) setSetting('mayar_webhook_token', '');
      // hanya perbarui kunci yang benar-benar dikirim, supaya simpan sebagian
      // (mis. hanya SMTP) tidak mengosongkan setelan lain
      if (array_key_exists('adminEmail', $in)) {
        $ae = str($in, 'adminEmail', 120);
        if ($ae !== '' && !filter_var($ae, FILTER_VALIDATE_EMAIL)) fail('Email admin tidak valid.');
        setSetting('admin_email', $ae);
      }
      if (array_key_exists('mailFrom', $in)) {
        $mf = str($in, 'mailFrom', 120);
        if ($mf !== '' && !filter_var($mf, FILTER_VALIDATE_EMAIL)) fail('Email pengirim tidak valid.');
        setSetting('mail_from', $mf !== '' ? $mf : 'no-reply@kitlab.id');
      }
      if (array_key_exists('autoWithoutToken', $in)) setSetting('auto_without_token', !empty($in['autoWithoutToken']) ? '1' : '0');
      // SMTP opsional — kalau host dikosongkan, pengiriman kembali memakai mail()
      if (array_key_exists('smtpHost', $in)) {
        setSetting('smtp_host', str($in, 'smtpHost', 120));
        $sp = (int)($in['smtpPort'] ?? 0);
        setSetting('smtp_port', (string)($sp > 0 && $sp < 65536 ? $sp : 587));
        $sec = strtolower(str($in, 'smtpSecure', 8));
        setSetting('smtp_secure', in_array($sec, ['tls', 'ssl', 'none'], true) ? $sec : 'tls');
        setSetting('smtp_user', str($in, 'smtpUser', 120));
      }
      if (array_key_exists('smtpPass', $in) && trim((string)$in['smtpPass']) !== '') setSetting('smtp_pass', trim((string)$in['smtpPass']));
      if (!empty($in['clearSmtp'])) foreach (['smtp_host', 'smtp_user', 'smtp_pass'] as $k) setSetting($k, '');
      // Template pesan. Ditulis pemilik dengan format WhatsApp; email dibentuk dari
      // template yang sama. Kosongkan untuk kembali ke teks bawaan.
      foreach (['msgPaidSubject' => 'msg_paid_subject', 'msgPaid' => 'msg_paid',
                'msgPendingSubject' => 'msg_pending_subject', 'msgPending' => 'msg_pending'] as $in_k => $set_k) {
        if (array_key_exists($in_k, $in)) {
          $batas = strpos($set_k, 'subject') !== false ? 150 : 4000;
          setSetting($set_k, mb_substr(trim((string)$in[$in_k]), 0, $batas));
        }
      }
      // WhatsApp (Fonnte)
      if (array_key_exists('fonnteToken', $in) && trim((string)$in['fonnteToken']) !== '') setSetting('fonnte_token', trim((string)$in['fonnteToken']));
      if (!empty($in['clearFonnte'])) setSetting('fonnte_token', '');
      // API key Mayar — dipakai tab Voucher untuk membuat kupon lewat API
      if (array_key_exists('mayarApiKey', $in) && trim((string)$in['mayarApiKey']) !== '') setSetting('mayar_api_key', trim((string)$in['mayarApiKey']));
      if (!empty($in['clearMayarKey'])) setSetting('mayar_api_key', '');
      // Meta Pixel (halaman iklan)
      if (array_key_exists('metaPixelId', $in)) {
        $px = preg_replace('/[^0-9]/', '', str($in, 'metaPixelId', 32));
        if ($px !== '' && (strlen($px) < 10 || strlen($px) > 20)) fail('Pixel ID Meta tidak valid — isinya 15-16 angka.');
        setSetting('meta_pixel_id', $px);
      }
      if (array_key_exists('adminWa', $in)) {
        $aw = str($in, 'adminWa', 20);
        if ($aw !== '' && waNumber($aw) === '') fail('Nomor WhatsApp admin tidak valid.');
        setSetting('admin_wa', $aw === '' ? '' : waNumber($aw));
      }
      out(['ok' => true]);
    }

    case 'test_email': {
      requireSuperAdmin();
      $to = str(input(), 'to', 120);
      if ($to === '') $to = setting('admin_email');
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Isi dan simpan email admin dulu.');
      try {
        // Kirim memakai template & kerangka HTML yang SAMA dengan email pembeli, diisi
        // data contoh. Dengan begitu tombol ini sekaligus jadi pratinjau tampilan email.
        $contoh = ['name' => 'Budi Santoso', 'plan' => 'Premium', 'username' => 'budi@contoh.com',
          'code' => 'RF-CNTH-2026', 'amount' => 79900, 'raw' => '', 'state' => 'aktif'];
        sendMailOrFail($to, '[TES] ' . accessSubject($contoh), accessMessage($contoh), accessHtml($contoh));
      } catch (Throwable $e) { fail($e->getMessage()); }
      out(['ok' => true, 'to' => $to]);
    }

    case 'test_wa': {
      requireSuperAdmin();
      $to = str(input(), 'to', 20);
      if ($to === '') $to = setting('admin_wa');
      if ($to === '') fail('Isi nomor WhatsApp admin dulu.');
      try {
        waSendOrFail($to, "Tes WhatsApp ResepFoto\n\nKoneksi Fonnte berhasil. " . siteUrl());
      } catch (Throwable $e) { fail($e->getMessage()); }
      out(['ok' => true, 'to' => waNumber($to)]);
    }

    case 'member_delete': {
      requireAdmin();
      $username = str(input(), 'username', 30);
      $st = db()->prepare("SELECT role, avatar FROM members WHERE username = ?"); $st->execute([$username]);
      $row = $st->fetch();
      if ($row && in_array((string)$row['role'], ['admin', 'super_admin'], true)) fail('Akun ini dikelola di tab Admin.');
      db()->prepare("DELETE FROM members WHERE username = ? AND COALESCE(role,'member') = 'member'")->execute([$username]);
      if ($row) delUpload($row['avatar'] ?? '');
      out(['ok' => true]);
    }

    case 'avatar_save': {
      $me = requireUser();
      @set_time_limit(60);
      $in = input();
      $target = strtolower(str($in, 'username', 30));
      $isSelf = ($target === '' || $target === strtolower((string)$me['username']));
      if (!$isSelf) requireAdmin();
      $clear = !empty($in['clearAvatar']);
      $path = '';
      if (!$clear) {
        if (!isset($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? 4) !== UPLOAD_ERR_OK) fail('Pilih atau tempel foto dulu.');
        $path = saveAvatar($_FILES['avatar']);
      }
      // super admin bawaan (akun config) tidak punya baris member: simpan di settings
      if ($isSelf && ($_SESSION['user']['role'] ?? '') === 'admin') {
        delUpload(setting('admin_avatar')); setSetting('admin_avatar', $path);
        out(['ok' => true, 'avatar' => $path]);
      }
      $username = $isSelf ? (string)$me['username'] : $target;
      $m = findMember($username);
      if (!$m) fail('Member tidak ditemukan.', 404);
      delUpload($m['avatar'] ?? ''); $m['avatar'] = $path;
      saveMember($m);
      out(['ok' => true, 'avatar' => $path, 'username' => $username]);
    }

    /* ---------- AI (Gemini) ---------- */
    case 'ai_settings': {
      requireSuperAdmin();
      $key = aiKey();
      $since = gmdate('c', time() - 30 * 86400);
      $st = db()->prepare('SELECT COUNT(*) AS calls, SUM(ok) AS ok, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout, AVG(ms) AS ms FROM ai_log WHERE ts >= ?');
      $st->execute([$since]); $u = $st->fetch();
      $by = db()->prepare('SELECT action, COUNT(*) AS n FROM ai_log WHERE ts >= ? GROUP BY action'); $by->execute([$since]);
      $log = db()->query('SELECT ts, action, model, ok, tokens_in, tokens_out, ms, note FROM ai_log ORDER BY id DESC LIMIT 25')->fetchAll();
      $dk = dsKey();
      out(['ok' => true, 'settings' => [
        'hasKey' => $key !== '', 'keyHint' => $key !== '' ? substr($key, -4) : '',
        'model' => aiModel(), 'imageModel' => aiImageModel(),
        'defaultModel' => AI_DEFAULT_MODEL, 'defaultImageModel' => AI_DEFAULT_IMAGE_MODEL,
        'dsHasKey' => $dk !== '', 'dsKeyHint' => $dk !== '' ? substr($dk, -4) : '',
        'dsModel' => dsModel(), 'dsDefaultModel' => DS_DEFAULT_MODEL, 'engine' => refEngine(),
        'curl' => function_exists('curl_init'), 'gd' => function_exists('imagecreatefromstring'),
      ], 'usage' => ['calls' => (int)$u['calls'], 'ok' => (int)$u['ok'], 'tokensIn' => (int)$u['tin'], 'tokensOut' => (int)$u['tout'],
        'avgMs' => (int)$u['ms'], 'byAction' => array_column($by->fetchAll(), 'n', 'action')],
        'log' => $log, 'categories' => existingCategories()]);
    }

    case 'ai_settings_save': {
      requireSuperAdmin();
      $in = input();
      $k = trim((string)($in['apiKey'] ?? ''));
      if ($k !== '') {
        if (!preg_match('/^[A-Za-z0-9_.\-]{20,200}$/', $k)) fail('Format API key tidak valid.');
        setSetting('gemini_api_key', $k);
      }
      if (!empty($in['clearKey'])) setSetting('gemini_api_key', '');
      $dk = trim((string)($in['dsKey'] ?? ''));
      if ($dk !== '') {
        if (!preg_match('/^[A-Za-z0-9_.\-]{20,200}$/', $dk)) fail('Format API key DeepSeek tidak valid.');
        setSetting('deepseek_api_key', $dk);
      }
      if (!empty($in['clearDsKey'])) setSetting('deepseek_api_key', '');
      $m = str($in, 'model', 60); $im = str($in, 'imageModel', 60); $dm = str($in, 'dsModel', 60);
      foreach ([$m, $im, $dm] as $x) if ($x !== '' && !preg_match('/^[a-z0-9][a-z0-9.\-]{2,59}$/', $x)) fail('Nama model tidak valid.');
      setSetting('gemini_model', $m);
      setSetting('gemini_image_model', $im);
      setSetting('deepseek_model', $dm);
      if (isset($in['engine'])) setSetting('ai_ref_engine', $in['engine'] === 'gemini' ? 'gemini' : 'deepseek');
      out(['ok' => true]);
    }

    case 'ai_test': {
      requireSuperAdmin();
      $in = input();
      $ask = 'Balas persis dengan satu kata: SIAP';
      $r = ($in['engine'] ?? '') === 'deepseek'
        ? deepseekCall('test', [['type' => 'text', 'text' => $ask]], ['maxTokens' => 20, 'timeout' => 40])
        : geminiCall('test', [['text' => $ask]]);
      out(['ok' => true, 'reply' => mb_substr(trim($r['text']), 0, 60), 'ms' => $r['ms'], 'model' => $r['model']]);
    }

    case 'ai_reference': {
      requireAdmin();
      @set_time_limit(180);
      $in = input();
      $url = str($in, 'url', 500);
      if ($url === '') fail('Tempel link postingan Instagram dulu.');
      out(['ok' => true, 'recipe' => recipeFromInstagram($url, str($in, 'extra', 6000))]);
    }

    case 'ai_thumb': {
      // Thumbnail resep bikinan sendiri dari slide referensi yang dipilih admin.
      $me = requireAdmin();
      @set_time_limit(200);
      $in = input();
      $prompt = str($in, 'prompt', 6000);
      if ($prompt === '') fail('Isi prompt resep dulu sebelum generate thumbnail.');
      $ref = uploadPath((string)($in['ref'] ?? ''));
      $fromRef = ($in['faceSrc'] ?? '') === 'ref';
      if ($fromRef && $ref === '') fail('Pilih slide referensi dulu.');
      $face = ''; $newFace = false;
      if (!$fromRef) {
        if (isset($_FILES['face']) && ($_FILES['face']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
          $face = saveTestImage($_FILES['face'], 'f_'); $newFace = true;
        } else $face = uploadPath((string)($in['face_path'] ?? ''));
        if ($face === '') fail('Tempel atau pilih foto wajah dulu.');
      }
      try { $res = generateThumbnail($prompt, $ref !== '' ? __DIR__ . '/' . $ref : null, $face !== '' ? __DIR__ . '/' . $face : null, $fromRef); }
      catch (Throwable $e) { if ($newFace) @unlink(__DIR__ . '/' . $face); throw $e; }
      $img = saveTempImage(base64_decode($res['images'][0]['data']));
      if (!$img) fail('Gambar hasil generate tidak bisa dibaca.');
      if ($newFace) db()->prepare('INSERT INTO faces (image, created_by, created_at) VALUES (?,?,?)')->execute([$face, $me['username'] ?? '', gmdate('c')]);
      out(['ok' => true, 'image' => $img, 'face' => $face, 'faces' => facesList(), 'ms' => $res['ms'], 'model' => $res['model']]);
    }

    case 'faces': {
      requireAdmin();
      out(['ok' => true, 'faces' => facesList()]);
    }

    /* ---------- versi English (etalase imagine.kitlab.id) ---------- */
    case 'en_status': {
      requireAdmin();
      out(['ok' => true, 'status' => enStatus()]);
    }

    case 'en_fill': {
      // Satu putaran = beberapa resep. Panel memanggilnya berulang sampai tidak ada yang tersisa.
      requireAdmin();
      @set_time_limit(200);
      out(['ok' => true] + enFill(4));
    }

    case 'th_status': {
      requireAdmin();
      out(['ok' => true, 'status' => thStatus()]);
    }

    case 'th_fill': {
      // Sama dengan en_fill: panel memanggilnya berulang sampai semua teks Thai terisi.
      requireAdmin();
      @set_time_limit(200);
      out(['ok' => true] + thFill(6));
    }

    case 'face_add': {
      // Tambah foto wajah tim/model yang sudah setuju ke pustaka, tanpa harus generate dulu.
      $me = requireAdmin();
      if (!isset($_FILES['face']) || ($_FILES['face']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) fail('Tempel atau pilih foto wajah dulu.');
      $face = saveTestImage($_FILES['face'], 'f_');
      db()->prepare('INSERT INTO faces (image, created_by, created_at) VALUES (?,?,?)')->execute([$face, $me['username'] ?? '', gmdate('c')]);
      out(['ok' => true, 'face' => $face, 'faces' => facesList()]);
    }

    case 'face_delete': {
      requireAdmin();
      $id = (int)(input()['id'] ?? 0);
      $st = db()->prepare('SELECT image FROM faces WHERE id = ?'); $st->execute([$id]);
      $img = (string)$st->fetchColumn();
      if ($img === '') fail('Foto wajah tidak ditemukan.', 404);
      db()->prepare('DELETE FROM faces WHERE id = ?')->execute([$id]);
      delUpload($img);
      out(['ok' => true, 'faces' => facesList()]);
    }

    case 'ai_analyze': {
      requireAdmin();
      @set_time_limit(120);
      $in = input();
      $prompt = str($in, 'prompt', 6000);
      if ($prompt === '') fail('Tempel prompt dulu.');
      $path = null;
      if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? 4) === UPLOAD_ERR_OK) {
        if ($_FILES['image']['size'] > 12 * 1024 * 1024) fail('Gambar terlalu besar. Maksimal 12 MB.');
        if (!@getimagesize($_FILES['image']['tmp_name'])) fail('File harus gambar JPG, PNG, atau WEBP.');
        $path = $_FILES['image']['tmp_name'];
      } else {
        $t = (string)($in['image_temp'] ?? '');
        if (preg_match('#^uploads/(tmp_[a-f0-9]{16}\.jpg|[a-f0-9]{16}\.jpg)$#', $t) && is_file(__DIR__ . '/' . $t)) $path = __DIR__ . '/' . $t;
      }
      out(['ok' => true, 'recipe' => recipeFromUpload($prompt, $path)]);
    }

    case 'ai_ocr': {
      requireAdmin();
      @set_time_limit(120);
      $in = input();
      $path = null;
      if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? 4) === UPLOAD_ERR_OK) {
        if ($_FILES['image']['size'] > 12 * 1024 * 1024) fail('Gambar terlalu besar. Maksimal 12 MB.');
        if (!@getimagesize($_FILES['image']['tmp_name'])) fail('File harus gambar JPG, PNG, atau WEBP.');
        $path = $_FILES['image']['tmp_name'];
      } else {
        $t = (string)($in['image_temp'] ?? '');
        if (preg_match('#^uploads/(tmp_[a-f0-9]{16}\.jpg|[a-f0-9]{16}\.jpg)$#', $t) && is_file(__DIR__ . '/' . $t)) $path = __DIR__ . '/' . $t;
      }
      if (!$path) fail('Pilih atau tempel gambar yang berisi prompt.');
      out(['ok' => true, 'recipe' => recipeFromImagePrompt($path)]);
    }

    /* ---------- hasil tes internal ---------- */
    case 'prompt_tests': {
      requireAdmin();
      $st = db()->prepare('SELECT * FROM prompt_tests WHERE prompt_id = ? ORDER BY id DESC');
      $st->execute([str($_GET, 'id', 40)]);
      out(['ok' => true, 'tests' => array_map('publicTest', $st->fetchAll())]);
    }

    case 'prompt_test_add': {
      requireAdmin();
      $in = input(); $pid = str($in, 'prompt_id', 40);
      $chk = db()->prepare('SELECT COUNT(*) FROM prompts WHERE id = ?'); $chk->execute([$pid]);
      if (!(int)$chk->fetchColumn()) fail('Simpan resep dulu sebelum menambah hasil tes.');
      if (!isset($_FILES['image'])) fail('Pilih atau tempel gambar hasil tes.');
      $img = saveTestImage($_FILES['image']);
      $status = in_array($in['status'] ?? '', ['ok', 'fail', 'note'], true) ? $in['status'] : 'note';
      db()->prepare('INSERT INTO prompt_tests (prompt_id, image, input_image, source, model, tool, status, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$pid, $img, '', 'upload', str($in, 'model', 60), str($in, 'tool', 20), $status, str($in, 'note', 400), gmdate('c')]);
      $id = (int)db()->lastInsertId();
      $st = db()->prepare('SELECT * FROM prompt_tests WHERE id = ?'); $st->execute([$id]);
      out(['ok' => true, 'test' => publicTest($st->fetch())]);
    }

    case 'prompt_test_generate': {
      requireAdmin();
      @set_time_limit(200);
      $in = input(); $pid = str($in, 'prompt_id', 40);
      $st = db()->prepare('SELECT prompt FROM prompts WHERE id = ?'); $st->execute([$pid]);
      $prompt = (string)$st->fetchColumn();
      if ($prompt === '') fail('Simpan resep dulu sebelum tes generate.');
      if (!isset($_FILES['input'])) fail('Pilih atau tempel foto input untuk dites.');
      $input = saveTestImage($_FILES['input'], 'in_');
      try { $res = generateTestImage($prompt, __DIR__ . '/' . $input); }
      catch (Throwable $e) { @unlink(__DIR__ . '/' . $input); throw $e; }
      $img = saveBytesImage(base64_decode($res['images'][0]['data']));
      db()->prepare('INSERT INTO prompt_tests (prompt_id, image, input_image, source, model, tool, status, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$pid, $img, $input, 'gemini', $res['model'], 'Gemini API', 'note', mb_substr(trim($res['text']), 0, 300), gmdate('c')]);
      $id = (int)db()->lastInsertId();
      $st = db()->prepare('SELECT * FROM prompt_tests WHERE id = ?'); $st->execute([$id]);
      out(['ok' => true, 'test' => publicTest($st->fetch()), 'ms' => $res['ms']]);
    }

    case 'prompt_test_update': {
      requireAdmin();
      $in = input();
      $status = in_array($in['status'] ?? '', ['ok', 'fail', 'note'], true) ? $in['status'] : 'note';
      db()->prepare('UPDATE prompt_tests SET status = ?, note = ? WHERE id = ?')->execute([$status, str($in, 'note', 400), (int)($in['id'] ?? 0)]);
      out(['ok' => true]);
    }

    case 'prompt_test_delete': {
      requireAdmin();
      $id = (int)(input()['id'] ?? 0);
      $st = db()->prepare('SELECT image, input_image FROM prompt_tests WHERE id = ?'); $st->execute([$id]);
      if ($t = $st->fetch()) foreach ([$t['image'], $t['input_image']] as $f) if ($f && strpos((string)$f, 'uploads/') === 0) @unlink(__DIR__ . '/' . $f);
      db()->prepare('DELETE FROM prompt_tests WHERE id = ?')->execute([$id]);
      out(['ok' => true]);
    }

    /* ---------- tracking ---------- */
    case 'track': {
      $u = requireUser();
      $in = input();
      $type = (string)($in['type'] ?? '');
      if (!in_array($type, ['open', 'copy', 'fav'], true)) fail('Aksi tidak dikenal.');
      if ($u['role'] === 'admin') out(['ok' => true, 'skipped' => true]);
      db()->prepare('INSERT INTO events (ts, username, type, prompt_id) VALUES (?,?,?,?)')
        ->execute([gmdate('c'), $u['username'], $type, preg_replace('/[^a-z0-9_-]/i', '', str($in, 'id', 40))]);
      out(['ok' => true]);
    }

    case 'lt': {
      header('Access-Control-Allow-Origin: ' . siteUrl());
      if ($method !== 'POST') fail('Metode salah.', 405);
      $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
      if ($ua === '' || preg_match('/bot|crawl|spider|facebookexternalhit|preview|headless/i', $ua)) out(['ok' => true]);
      $j = json_decode((string)file_get_contents('php://input'), true);
      if (!is_array($j)) fail('Data tidak valid.');
      $vid = (string)($j['vid'] ?? '');
      if (!preg_match('/^[a-z0-9]{8,32}$/', $vid)) fail('Data tidak valid.');
      $type = (string)($j['type'] ?? '');
      $page = cleanTag($j['page'] ?? 'landing', 30);
      $pdo = db();
      if ($type === 'ping') {
        $pdo->prepare('INSERT OR REPLACE INTO presence (vid, ts, page) VALUES (?,?,?)')->execute([$vid, time(), $page]);
        if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM presence WHERE ts < ?')->execute([time() - 3600]);
        out(['ok' => true]);
      }
      if (!in_array($type, ['view', 'cta', 'checkout', 'pay'], true)) fail('Data tidak valid.');
      $day = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
      $cnt = $pdo->prepare('SELECT COUNT(*) FROM lt_events WHERE vid = ? AND day = ?'); $cnt->execute([$vid, $day]);
      if ((int)$cnt->fetchColumn() > 300) out(['ok' => true]);
      $ref = cleanTag($j['ref'] ?? '', 200);
      $refHost = $ref !== '' ? strtolower((string)parse_url($ref, PHP_URL_HOST)) : '';
      $src = strtolower(cleanTag($j['src'] ?? '', 60));
      if ($src === '' && !empty($j['fbclid'])) $src = 'facebook';
      if ($src === '' && $refHost !== '' && $refHost !== strtolower((string)parse_url(siteUrl(), PHP_URL_HOST))) $src = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $refHost);
      if ($src === '') $src = 'direct';
      $vou = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($j['vou'] ?? '')));
      $pdo->prepare('INSERT INTO lt_events (ts, day, vid, sid, type, plan, src, med, camp, content, ref, device, page, vou) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([gmdate('c'), $day, $vid, cleanTag($j['sid'] ?? '', 32), $type, cleanTag($j['plan'] ?? '', 20), $src,
          strtolower(cleanTag($j['med'] ?? '', 60)), cleanTag($j['camp'] ?? '', 80), cleanTag($j['content'] ?? '', 80), $refHost, deviceOf($ua), $page,
          mb_substr($vou, 0, 24)]);
      if ($type === 'view') $pdo->prepare('INSERT OR REPLACE INTO presence (vid, ts, page) VALUES (?,?,?)')->execute([$vid, time(), $page]);
      out(['ok' => true]);
    }

    case 'pixel': {
      // publik: ID Meta Pixel untuk halaman iklan. Ini BUKAN rahasia — pixel ID memang
      // terbaca di sumber halaman tiap situs yang memakainya. Disimpan di settings supaya
      // bisa diganti dari Admin -> Iklan tanpa membangun ulang halaman. Token Conversions
      // API (kalau nanti dipakai) TIDAK boleh ikut ke sini, itu rahasia server.
      header('Cache-Control: public, max-age=300');
      out(['ok' => true, 'id' => setting('meta_pixel_id')]);
    }

    case 'live': {
      header('Cache-Control: public, max-age=15');
      $pdo = db();
      $page = cleanTag($_GET['page'] ?? '', 30);          // kosong = semua halaman
      $f = $page !== '' ? ' AND page = ?' : '';
      $arg = $page !== '' ? [$page] : [];
      $now = $pdo->prepare('SELECT COUNT(*) FROM presence WHERE ts >= ?' . $f);
      $now->execute(array_merge([time() - 60], $arg));
      $d = $pdo->prepare("SELECT COUNT(DISTINCT vid) FROM lt_events WHERE type = 'view' AND ts >= ?" . $f);
      $d->execute(array_merge([gmdate('c', time() - 86400)], $arg));
      out(['ok' => true, 'now' => (int)$now->fetchColumn(), 'day' => (int)$d->fetchColumn()]);
    }

    /* ---------- laporan ---------- */
    case 'report_users': {
      requireSuperAdmin();
      $pdo = db();
      $days = dayRange(30); $from = $days[0];
      $members = $pdo->query('SELECT * FROM members')->fetchAll();
      $status = ['ok' => 0, 'expired' => 0, 'off' => 0]; $plans = []; $newPer = array_fill_keys($days, 0);
      foreach ($members as $m) {
        $s = memberStatus($m); $status[$s] = ($status[$s] ?? 0) + 1;
        $plans[$m['plan']] = ($plans[$m['plan']] ?? 0) + 1;
        $d = jktDay((string)$m['created_at']); if (isset($newPer[$d])) $newPer[$d]++;
      }
      $since30 = gmdate('c', strtotime($from . ' 00:00:00 Asia/Jakarta'));
      $ev = $pdo->prepare('SELECT ts, username, type, prompt_id FROM events WHERE ts >= ?'); $ev->execute([$since30]);
      $per = []; foreach ($days as $d) $per[$d] = ['login' => 0, 'open' => 0, 'copy' => 0, 'users' => []];
      $active = ['d1' => [], 'd7' => [], 'd30' => []]; $top = []; $byUser = [];
      $t1 = time() - 86400; $t7 = time() - 7 * 86400;
      foreach ($ev->fetchAll() as $e) {
        $d = jktDay($e['ts']); $t = strtotime($e['ts']);
        if (isset($per[$d])) { if (isset($per[$d][$e['type']])) $per[$d][$e['type']]++; $per[$d]['users'][$e['username']] = 1; }
        $active['d30'][$e['username']] = 1; if ($t >= $t7) $active['d7'][$e['username']] = 1; if ($t >= $t1) $active['d1'][$e['username']] = 1;
        if ($e['prompt_id']) { $top[$e['prompt_id']][$e['type']] = ($top[$e['prompt_id']][$e['type']] ?? 0) + 1; }
        if ($e['type'] === 'copy') $byUser[$e['username']] = ($byUser[$e['username']] ?? 0) + 1;
      }
      $prompts = $pdo->query('SELECT id, title, cat, image, title_en, created_at FROM prompts')->fetchAll();
      $pmap = []; foreach ($prompts as $p) $pmap[$p['id']] = $p;
      $topList = [];
      foreach ($top as $pid => $c) if (isset($pmap[$pid])) $topList[] = ['id' => $pid, 'title' => $pmap[$pid]['title'], 'cat' => $pmap[$pid]['cat'], 'image' => $pmap[$pid]['image'],
        'copy' => $c['copy'] ?? 0, 'open' => $c['open'] ?? 0, 'fav' => $c['fav'] ?? 0];
      usort($topList, fn($a, $b) => [$b['copy'], $b['open']] <=> [$a['copy'], $a['open']]);
      $unused = count(array_filter($prompts, fn($p) => empty($top[$p['id']]['copy'])));
      arsort($byUser);
      $orders = $pdo->query('SELECT plan, amount, state, created_at FROM orders')->fetchAll();
      $ord = ['paid' => 0, 'revenue' => 0, 'pending' => 0, 'rejected' => 0, 'byPlan' => [], 'revenue30' => 0, 'paid30' => 0];
      foreach ($orders as $o) {
        $paid = in_array($o['state'], ['aktif', 'lunas'], true);
        if ($paid) { $ord['paid']++; $ord['revenue'] += (int)$o['amount']; $ord['byPlan'][$o['plan']] = ($ord['byPlan'][$o['plan']] ?? 0) + 1;
          if ($o['created_at'] >= $since30) { $ord['paid30']++; $ord['revenue30'] += (int)$o['amount']; } }
        elseif ($o['state'] === 'ditolak') $ord['rejected']++; else $ord['pending']++;
      }
      $recent = $pdo->query("SELECT e.ts, e.username, e.type, e.prompt_id, m.name FROM events e LEFT JOIN members m ON m.username = e.username ORDER BY e.id DESC LIMIT 15")->fetchAll();
      foreach ($recent as &$r) $r['title'] = $r['prompt_id'] && isset($pmap[$r['prompt_id']]) ? $pmap[$r['prompt_id']]['title'] : '';
      unset($r);
      $tests = (int)$pdo->query('SELECT COUNT(*) FROM prompt_tests')->fetchColumn();
      $testedPrompts = (int)$pdo->query('SELECT COUNT(DISTINCT prompt_id) FROM prompt_tests')->fetchColumn();
      $aiCalls = $pdo->prepare('SELECT COUNT(*) FROM ai_log WHERE ts >= ?'); $aiCalls->execute([$since30]);
      $daily = [];
      foreach ($per as $d => $v) $daily[] = ['day' => $d, 'new' => $newPer[$d], 'login' => $v['login'], 'open' => $v['open'], 'copy' => $v['copy'], 'active' => count($v['users'])];
      $lastLogin = 0; foreach ($members as $m) if (!empty($m['last_login']) && strtotime($m['last_login']) >= $t7) $lastLogin++;
      out(['ok' => true,
        'members' => ['total' => count($members), 'status' => $status, 'plans' => $plans, 'new30' => array_sum($newPer), 'loggedIn7' => $lastLogin],
        'active' => ['d1' => count($active['d1']), 'd7' => count($active['d7']), 'd30' => count($active['d30'])],
        'daily' => $daily, 'top' => array_slice($topList, 0, 10),
        'topUsers' => array_map(fn($u, $n) => ['username' => $u, 'copies' => $n], array_slice(array_keys($byUser), 0, 5), array_slice(array_values($byUser), 0, 5)),
        'orders' => $ord,
        'app' => ['prompts' => count($prompts), 'categories' => count(array_unique(array_column($prompts, 'cat'))),
          'withEn' => count(array_filter($prompts, fn($p) => (string)$p['title_en'] !== '')), 'unused30' => $unused,
          'tests' => $tests, 'testedPrompts' => $testedPrompts, 'aiCalls30' => (int)$aiCalls->fetchColumn(),
          'newPrompts30' => count(array_filter($prompts, fn($p) => (string)$p['created_at'] >= $since30))],
        'recent' => $recent]);
    }

    case 'report_ads': {
      requireSuperAdmin();
      $pdo = db();
      $n = (int)($_GET['days'] ?? 30); if (!in_array($n, [1, 7, 30, 90], true)) $n = 30;
      $days = dayRange($n); $from = $days[0]; $to = end($days);
      $st = $pdo->prepare('SELECT day, vid, type, plan, src, med, camp, device, ref FROM lt_events WHERE day >= ? AND day <= ?');
      $st->execute([$from, $to]);
      $rows = $st->fetchAll();
      $daily = []; foreach ($days as $d) $daily[$d] = ['view' => [], 'cta' => [], 'checkout' => [], 'pay' => [], 'orders' => 0, 'revenue' => 0, 'spend' => 0];
      $tot = ['views' => 0, 'view' => [], 'cta' => [], 'checkout' => [], 'pay' => []];
      $camps = []; $srcs = []; $devices = []; $refs = []; $planClicks = [];
      foreach ($rows as $r) {
        $t = $r['type']; $v = $r['vid'];
        if ($t === 'view') $tot['views']++;
        $tot[$t][$v] = 1;
        if (isset($daily[$r['day']][$t])) $daily[$r['day']][$t][$v] = 1;
        $ck = ($r['camp'] !== '' ? $r['camp'] : '(tanpa kampanye)') . '|' . $r['src'];
        if (!isset($camps[$ck])) $camps[$ck] = ['camp' => $r['camp'] !== '' ? $r['camp'] : '(tanpa kampanye)', 'src' => $r['src'], 'med' => $r['med'], 'view' => [], 'cta' => [], 'checkout' => [], 'pay' => []];
        $camps[$ck][$t][$v] = 1;
        $srcs[$r['src']][$t][$v] = 1;
        if ($t === 'view') { $devices[$r['device']][$v] = 1; if ($r['ref'] !== '') $refs[$r['ref']][$v] = 1; }
        if (($t === 'pay' || $t === 'checkout') && $r['plan'] !== '') $planClicks[$r['plan']][$t][$v] = 1;
      }
      $sinceIso = gmdate('c', strtotime($from . ' 00:00:00 Asia/Jakarta'));
      $os = $pdo->prepare('SELECT plan, amount, state, created_at FROM orders WHERE created_at >= ?'); $os->execute([$sinceIso]);
      $orders = ['paid' => 0, 'revenue' => 0, 'pending' => 0, 'byPlan' => []];
      foreach ($os->fetchAll() as $o) {
        $d = jktDay($o['created_at']);
        if (in_array($o['state'], ['aktif', 'lunas'], true)) {
          $orders['paid']++; $orders['revenue'] += (int)$o['amount'];
          $orders['byPlan'][$o['plan']] = ($orders['byPlan'][$o['plan']] ?? 0) + 1;
          if (isset($daily[$d])) { $daily[$d]['orders']++; $daily[$d]['revenue'] += (int)$o['amount']; }
        } elseif ($o['state'] !== 'ditolak') $orders['pending']++;
      }
      $sp = $pdo->prepare('SELECT day, campaign, amount, note FROM ad_spend WHERE day >= ? AND day <= ? ORDER BY day DESC, campaign');
      $sp->execute([$from, $to]);
      $spendRows = $sp->fetchAll(); $spend = 0; $spendByCamp = [];
      foreach ($spendRows as $s) {
        $spend += (int)$s['amount'];
        $spendByCamp[$s['campaign']] = ($spendByCamp[$s['campaign']] ?? 0) + (int)$s['amount'];
        if (isset($daily[$s['day']])) $daily[$s['day']]['spend'] += (int)$s['amount'];
      }
      $cnt = fn($a) => count($a);
      $campList = [];
      foreach ($camps as $c) {
        $sp1 = $spendByCamp[$c['camp']] ?? 0;
        $campList[] = ['camp' => $c['camp'], 'src' => $c['src'], 'med' => $c['med'], 'visitors' => $cnt($c['view']), 'cta' => $cnt($c['cta']),
          'checkout' => $cnt($c['checkout']), 'pay' => $cnt($c['pay']), 'spend' => $sp1];
      }
      foreach ($spendByCamp as $name => $amt) {
        $found = false; foreach ($campList as $c) if ($c['camp'] === $name) $found = true;
        if (!$found) $campList[] = ['camp' => $name, 'src' => '-', 'med' => '', 'visitors' => 0, 'cta' => 0, 'checkout' => 0, 'pay' => 0, 'spend' => $amt];
      }
      usort($campList, fn($a, $b) => [$b['visitors'], $b['spend']] <=> [$a['visitors'], $a['spend']]);
      $srcList = []; foreach ($srcs as $k => $v) $srcList[] = ['src' => $k, 'visitors' => $cnt($v['view'] ?? []), 'pay' => $cnt($v['pay'] ?? [])];
      usort($srcList, fn($a, $b) => $b['visitors'] <=> $a['visitors']);
      $devList = []; foreach ($devices as $k => $v) $devList[$k] = $cnt($v);
      $refList = []; foreach ($refs as $k => $v) $refList[] = ['ref' => $k, 'visitors' => $cnt($v)];
      usort($refList, fn($a, $b) => $b['visitors'] <=> $a['visitors']);
      $plansOut = []; foreach ($planClicks as $k => $v) $plansOut[$k] = ['checkout' => $cnt($v['checkout'] ?? []), 'pay' => $cnt($v['pay'] ?? [])];
      $dailyOut = []; foreach ($daily as $d => $v) $dailyOut[] = ['day' => $d, 'visitors' => $cnt($v['view']), 'checkout' => $cnt($v['checkout']),
        'pay' => $cnt($v['pay']), 'orders' => $v['orders'], 'revenue' => $v['revenue'], 'spend' => $v['spend']];
      $now = $pdo->prepare('SELECT COUNT(*) FROM presence WHERE ts >= ?'); $now->execute([time() - 60]);
      $visitors = $cnt($tot['view']);
      out(['ok' => true, 'days' => $n, 'from' => $from, 'to' => $to, 'liveNow' => (int)$now->fetchColumn(),
        'totals' => ['views' => $tot['views'], 'visitors' => $visitors, 'cta' => $cnt($tot['cta']), 'checkout' => $cnt($tot['checkout']), 'pay' => $cnt($tot['pay']),
          'orders' => $orders['paid'], 'pending' => $orders['pending'], 'revenue' => $orders['revenue'], 'spend' => $spend,
          'roas' => $spend > 0 ? round($orders['revenue'] / $spend, 2) : null, 'cpa' => $orders['paid'] > 0 && $spend > 0 ? (int)round($spend / $orders['paid']) : null,
          'cpv' => $visitors > 0 && $spend > 0 ? (int)round($spend / $visitors) : null, 'conv' => $visitors > 0 ? round($orders['paid'] / $visitors * 100, 2) : null],
        'ordersByPlan' => $orders['byPlan'], 'planClicks' => $plansOut,
        'daily' => $dailyOut, 'campaigns' => $campList, 'sources' => $srcList, 'devices' => $devList, 'refs' => array_slice($refList, 0, 8),
        'spendRows' => $spendRows, 'metaPixelId' => setting('meta_pixel_id'),
        'tracking' => (int)$pdo->query('SELECT COUNT(*) FROM lt_events')->fetchColumn() > 0]);
    }

    case 'ad_spend_save': {
      requireSuperAdmin();
      $in = input();
      $day = str($in, 'day', 10);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) fail('Format tanggal tidak valid.');
      $camp = cleanTag($in['campaign'] ?? '', 80);
      if ($camp === '') $camp = '(tanpa kampanye)';
      $amt = (int)preg_replace('/\D/', '', (string)($in['amount'] ?? '0'));
      if ($amt <= 0) db()->prepare('DELETE FROM ad_spend WHERE day = ? AND campaign = ?')->execute([$day, $camp]);
      else db()->prepare('INSERT OR REPLACE INTO ad_spend (day, campaign, amount, note) VALUES (?,?,?,?)')->execute([$day, $camp, $amt, str($in, 'note', 120)]);
      out(['ok' => true]);
    }

    /* ---------- voucher ----------
     * Ada dua jalur, dan bedanya penting:
     *
     * 1. `voucher_create` MEMBUAT kupon sungguhan di Mayar lewat API, lengkap dengan
     *    kuota dan tanggal kedaluwarsa. Ini jalur yang dianjurkan.
     * 2. `voucher_save` hanya MENCATAT kode yang sudah dibuat manual di dashboard Mayar.
     *    Dipertahankan untuk kode lama; mengosongkannya tidak mematikan kupon di Mayar.
     *
     * Dalam kedua kasus, potongan harga dan sisa kuota ditegakkan Mayar saat checkout —
     * pembayaran terjadi di domain Mayar, aplikasi ini baru tahu setelah webhook masuk.
     * API Mayar tidak punya endpoint hapus/ubah, jadi mematikan kupon tetap lewat dashboard.
     */
    case 'vouchers': {
      requireSuperAdmin();
      $rows = db()->query('SELECT * FROM vouchers ORDER BY pct')->fetchAll();
      out(['ok' => true, 'vouchers' => array_map('publicVoucher', $rows), 'tiers' => VOUCHER_TIERS,
        'hasMayarKey' => mayarApiKey() !== '']);
    }

    case 'voucher_create': {
      requireSuperAdmin();
      $in = input();
      // Tingkat diskon TIDAK ditentukan klien — dibaca dari dua angka terakhir tiap kode,
      // supaya HEMAT30/DISKON30/PROMO30 otomatis menempel di tingkat 30%.
      $codes = voucherCodeList(isset($in['codes']) ? $in['codes'] : (isset($in['code']) ? $in['code'] : ''));
      if (!$codes) fail('Isi minimal satu kode voucher.');
      if (count($codes) > 10) fail('Maksimal 10 kode untuk satu tingkat.');
      foreach ($codes as $c) {
        if (!preg_match('/^[A-Z0-9-]{5,24}$/', $c)) fail('Kode "' . $c . '" tidak valid: 5-24 karakter, hanya huruf, angka, dan tanda minus.');
      }
      $pct = voucherTierFromCode($codes[0]);
      if ($pct === 0) fail('Kode harus diakhiri dua angka tingkat diskon (10, 20, 30, 40, 50, 60, 70, 80, atau 90). Contoh: HEMAT30.');
      foreach ($codes as $c) {
        if (voucherTierFromCode($c) !== $pct) fail('Semua kode satu tingkat harus berakhiran angka yang sama. "' . $c . '" tidak cocok dengan ' . $pct . '%.');
      }
      if (isset($in['pct']) && (int)$in['pct'] !== $pct) fail('Kode berakhiran ' . $pct . ' tidak bisa dipasang di tingkat ' . (int)$in['pct'] . '%.');
      foreach ($codes as $c) {
        $pemilik = voucherCodeOwner($c, $pct);
        if ($pemilik) fail('Kode "' . $c . '" sudah dipakai tingkat ' . $pemilik . '%.');
      }
      $lama = db()->prepare('SELECT mayar_id FROM vouchers WHERE pct = ?'); $lama->execute([$pct]);
      if ((string)$lama->fetchColumn() !== '') fail('Tingkat ini sudah punya kupon di Mayar. Kosongkan dulu sebelum membuat yang baru.');
      $quota = (int)($in['quota'] ?? 0);
      if ($quota < 1 || $quota > 100000) fail('Kuota harus antara 1 dan 100000.');
      $exp = str($in, 'expires', 10);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) fail('Tanggal kedaluwarsa wajib diisi, format YYYY-MM-DD.');
      if ($exp <= gmdate('Y-m-d')) fail('Tanggal kedaluwarsa harus setelah hari ini.');
      $onetime = !empty($in['onetime']);
      try { $r = mayarCreateCoupon($codes, $pct, $quota, $exp, $onetime); }
      catch (Throwable $e) { fail($e->getMessage(), 502); }
      // Yang disimpan adalah kode yang DIAKUI Mayar lewat responsnya, bukan yang kita kirim.
      $jadi = $r['codes'];
      if (!$jadi) fail('Mayar tidak mengembalikan satu kode pun.', 502);
      db()->prepare('UPDATE vouchers SET code = ?, codes = ?, note = ?, active = 1, quota = ?, expires = ?, kind = ?, mayar_id = ?, mayar_ids = ?, synced_at = ?, updated_at = ? WHERE pct = ?')
        ->execute([$jadi[0], json_encode(array_values($jadi)), str($in, 'note', 160), $quota, $exp, $onetime ? 'onetime' : 'reusable',
          $r['id'], json_encode(array_values($r['ids'])), gmdate('c'), gmdate('c'), $pct]);
      $st = db()->prepare('SELECT * FROM vouchers WHERE pct = ?'); $st->execute([$pct]);
      // 'warning' terisi kalau sebagian alias gagal: yang berhasil tetap tersimpan,
      // dan panel harus mengatakannya, bukan pura-pura semuanya beres.
      out(['ok' => true, 'voucher' => publicVoucher($st->fetch()), 'warning' => (string)$r['warning']]);
    }

    case 'mayar_coupons': {
      // Melihat daftar kupon Mayar apa adanya. Dibuat karena menebak bentuk jawaban
      // lewat siklus deploy sangat mahal, dan berguna seterusnya kalau ada kupon yang
      // "hilang" dari panel. Hanya super admin, dan isinya jawaban Mayar — bukan kunci.
      requireSuperAdmin();
      $cari = isset($_GET['q']) ? (string)$_GET['q'] : 'ResepFoto';
      try { $j = mayarApi('GET', '/coupons?limit=100&search=' . rawurlencode($cari), null, true); }
      catch (Throwable $e) { fail($e->getMessage(), 502); }
      $ringkas = [];
      foreach (mayarCouponRows($j) as $r) {
        $ringkas[] = ['id' => (string)$r['id'], 'name' => (string)($r['name'] ?? ''),
          'status' => (string)($r['status'] ?? ''), 'totalUsage' => (int)($r['totalUsage'] ?? 0)];
      }
      out(['ok' => true, 'cari' => $cari, 'jumlah' => count($ringkas), 'kupon' => $ringkas,
        'mentah' => mb_substr((string)json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 1500)]);
    }

    case 'voucher_sync': {
      requireSuperAdmin();
      $pct = (int)(input()['pct'] ?? 0);
      if (!in_array($pct, VOUCHER_TIERS, true)) fail('Persentase harus salah satu dari 10 sampai 90.');
      $st = db()->prepare('SELECT * FROM vouchers WHERE pct = ?'); $st->execute([$pct]);
      $v = $st->fetch();
      $ids = $v ? voucherMayarIds($v) : [];
      if (!$ids) fail('Tingkat ini belum punya kupon yang dibuat lewat panel.');
      // Satu tingkat bisa punya beberapa diskon (satu per alias). Kuota diambil dari
      // yang terakhir dibaca, dan tingkat dianggap aktif hanya kalau SEMUA aliasnya aktif —
      // satu alias mati sudah cukup untuk membuat link yang beredar berhenti bekerja.
      $kuota = (int)$v['quota']; $aktif = null;
      try {
        foreach ($ids as $idDiskon) {
          $d = mayarCouponDetail($idDiskon);
          if (isset($d['totalCoupons'])) $kuota = (int)$d['totalCoupons'];
          if (isset($d['coupons'][0]['isActive'])) {
            $aktif = ($aktif === null ? true : $aktif) && (bool)$d['coupons'][0]['isActive'];
          }
        }
      } catch (Throwable $e) { fail($e->getMessage(), 502); }
      if ($aktif === null) $aktif = (bool)$v['active'];
      $dipakai = mayarCouponUsage($ids);
      db()->prepare('UPDATE vouchers SET quota = ?, active = ?, used = ?, synced_at = ?, updated_at = ? WHERE pct = ?')
        ->execute([$kuota, $aktif ? 1 : 0, $dipakai, gmdate('c'), gmdate('c'), $pct]);
      $st->execute([$pct]);
      out(['ok' => true, 'voucher' => publicVoucher($st->fetch())]);
    }

    case 'voucher_save': {
      requireSuperAdmin();
      $in = input();
      $codes = voucherCodeList(isset($in['codes']) ? $in['codes'] : (isset($in['code']) ? $in['code'] : ''));
      // Daftar kosong berarti tingkat ini dikembalikan jadi template kosong; tingkatnya
      // diambil dari 'pct' karena tidak ada kode yang bisa dibaca angkanya.
      if (!$codes) {
        $pct = (int)($in['pct'] ?? 0);
        if (!in_array($pct, VOUCHER_TIERS, true)) fail('Persentase harus salah satu dari 10 sampai 90.');
        db()->prepare("UPDATE vouchers SET code = '', codes = '', note = ?, active = 0, updated_at = ? WHERE pct = ?")
          ->execute([str($in, 'note', 160), gmdate('c'), $pct]);
      } else {
        if (count($codes) > 10) fail('Maksimal 10 kode untuk satu tingkat.');
        foreach ($codes as $c) {
          if (!preg_match('/^[A-Z0-9-]{5,24}$/', $c)) fail('Kode "' . $c . '" tidak valid: 5-24 karakter, hanya huruf, angka, dan tanda minus.');
        }
        $pct = voucherTierFromCode($codes[0]);
        if ($pct === 0) fail('Kode harus diakhiri dua angka tingkat diskon (10, 20, 30, 40, 50, 60, 70, 80, atau 90). Contoh: HEMAT30.');
        foreach ($codes as $c) {
          if (voucherTierFromCode($c) !== $pct) fail('Semua kode satu tingkat harus berakhiran angka yang sama. "' . $c . '" tidak cocok dengan ' . $pct . '%.');
        }
        if (isset($in['pct']) && (int)$in['pct'] !== $pct) fail('Kode berakhiran ' . $pct . ' tidak bisa dipasang di tingkat ' . (int)$in['pct'] . '%.');
        foreach ($codes as $c) {
          $pemilik = voucherCodeOwner($c, $pct);
          if ($pemilik) fail('Kode "' . $c . '" sudah dipakai tingkat ' . $pemilik . '%.');
        }
        db()->prepare('UPDATE vouchers SET code = ?, codes = ?, note = ?, active = ?, updated_at = ? WHERE pct = ?')
          ->execute([$codes[0], json_encode($codes), str($in, 'note', 160), !empty($in['active']) ? 1 : 0, gmdate('c'), $pct]);
      }
      $st = db()->prepare('SELECT * FROM vouchers WHERE pct = ?'); $st->execute([$pct]);
      out(['ok' => true, 'voucher' => publicVoucher($st->fetch())]);
    }

    case 'voucher_delete': {
      // Mengosongkan catatan sebuah tingkat. Templatenya sendiri tidak pernah dihapus, dan
      // kupon yang sudah dibuat di Mayar TIDAK ikut mati — API Mayar tidak punya endpoint
      // hapus. Matikan lewat dashboard Mayar kalau kampanyenya memang mau dihentikan.
      requireSuperAdmin();
      $in = input();
      $pct = (int)($in['pct'] ?? 0);
      if (!in_array($pct, VOUCHER_TIERS, true)) fail('Persentase harus salah satu dari 10 sampai 90.');
      db()->prepare("UPDATE vouchers SET code = '', codes = '', note = '', active = 0, quota = 0, expires = '', kind = '', mayar_id = '', mayar_ids = '', used = -1, synced_at = '', updated_at = ? WHERE pct = ?")
        ->execute([gmdate('c'), $pct]);
      out(['ok' => true]);
    }

    default:
      fail('Endpoint tidak dikenal.', 404);
  }
} catch (RfError $e) {
  fail($e->getMessage(), 422);
} catch (Throwable $e) {
  error_log('[resepfoto] ' . $e->getMessage());
  fail('Terjadi kesalahan di server. Coba lagi sebentar lagi.', 500);
}
