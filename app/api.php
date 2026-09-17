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
    'Peran tidak valid.' => 'Invalid role.',
    'Akun ini dikelola di tab Admin.' => 'This account is managed in the Admin tab.',
    'Pilih atau tempel foto dulu.' => 'Choose or paste a photo first.',
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
  if ($s['role'] === 'admin') return ['role' => 'admin', 'adminRole' => 'super_admin', 'username' => ADMIN_USER, 'name' => 'Admin ResepFoto', 'plan' => 'Admin', 'expires' => '', 'avatar' => setting('admin_avatar')];
  $st = db()->prepare('SELECT * FROM members WHERE username = ?');
  $st->execute([$s['username']]);
  $m = $st->fetch();
  if (!$m || memberStatus($m) !== 'ok') { unset($_SESSION['user']); return null; }
  $mrole = $m['role'] ?? 'member';
  $pub = publicMember($m);
  if ($mrole === 'admin' || $mrole === 'super_admin') return ['role' => 'admin', 'adminRole' => $mrole] + $pub;
  return ['role' => 'member', 'adminRole' => ''] + $pub;
}
function requireUser(): array { $u = currentUser(); if (!$u) fail('Sesi berakhir. Silakan masuk lagi.', 401); return $u; }
function requireAdmin(): void { $u = requireUser(); if ($u['role'] !== 'admin') fail('Khusus admin.', 403); }
function requireSuperAdmin(): array { $u = requireUser(); if (($u['adminRole'] ?? '') !== 'super_admin') fail('Khusus super admin.', 403); return $u; }
function coverConfig(): array {
  return [
    'title' => setting('cover_title'), 'sub' => setting('cover_sub'),
    'titleEn' => setting('cover_title_en'), 'subEn' => setting('cover_sub_en'),
    'chip' => setting('cover_chip'),
    'img' => [setting('cover_img1'), setting('cover_img2'), setting('cover_img3')],
  ];
}
function rowToPrompt(array $r): array {
  return ['id' => $r['id'], 'order' => (int)$r['ord'], 'cat' => $r['cat'], 'title' => $r['title'], 'desc' => $r['descr'],
    'popular' => (bool)$r['popular'], 'tools' => json_decode($r['tools'] ?: '[]', true), 'prompt' => $r['prompt'],
    'tips' => $r['tips'], 'image' => $r['image'], 'createdAt' => $r['created_at'] ?? '',
    'catEn' => (string)($r['cat_en'] ?? ''), 'titleEn' => (string)($r['title_en'] ?? ''),
    'descEn' => (string)($r['descr_en'] ?? ''), 'tipsEn' => (string)($r['tips_en'] ?? '')];
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
    case 'me':
      out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => currentUser(), 'cover' => coverConfig(), 'v' => 'admin-4']);

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
      $ok = false; $role = 'member';
      if ($user === strtolower(ADMIN_USER)) {
        $ok = password_verify($code, ADMIN_HASH); $role = 'admin';
        if (!$ok) { $pdo->prepare('INSERT INTO attempts VALUES (?,?)')->execute([$ip, time()]); fail('Kode akses admin salah.'); }
      } else {
        $st = $pdo->prepare('SELECT * FROM members WHERE username = ?'); $st->execute([$user]);
        $m = $st->fetch();
        if (!$m || !password_verify(strtoupper($code), $m['code_hash'])) {
          $pdo->prepare('INSERT INTO attempts VALUES (?,?)')->execute([$ip, time()]);
          fail('Username atau kode akses tidak cocok. Cek lagi pesan konfirmasi pembelianmu.');
        }
        $s = memberStatus($m);
        if ($s === 'off') fail('Akses akun ini sedang nonaktif. Hubungi admin untuk mengaktifkan lagi.');
        if ($s === 'expired') fail('Akses kamu sudah berakhir. Perpanjang paket untuk lanjut.');
        $pdo->prepare('UPDATE members SET last_login = ? WHERE username = ?')->execute([gmdate('c'), $user]);
        $pdo->prepare('INSERT INTO events (ts, username, type, prompt_id) VALUES (?,?,?,?)')->execute([gmdate('c'), $user, 'login', null]);
      }
      session_regenerate_id(true);
      $_SESSION['user'] = ['role' => $role, 'username' => $user];
      out(['ok' => true, 'user' => currentUser()]);
    }

    case 'logout':
      unset($_SESSION['user']); session_regenerate_id(true);
      out(['ok' => true]);

    case 'prompts': {
      $u = requireUser();
      if ($u['role'] === 'member' && $u['plan'] === 'Standard') {
        // Standard: hanya resep yang sudah ada saat member dibuat
        $st = db()->prepare('SELECT * FROM prompts WHERE COALESCE(created_at, updated_at) <= ? ORDER BY ord, id');
        $st->execute([$u['createdAt']]);
        $rows = $st->fetchAll();
      } else {
        $rows = db()->query('SELECT * FROM prompts ORDER BY ord, id')->fetchAll();
      }
      out(['ok' => true, 'prompts' => array_map('rowToPrompt', $rows)]);
    }

    case 'prompt_save': {
      requireAdmin();
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
      $pdo->prepare('INSERT OR REPLACE INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, updated_at, created_at, cat_en, title_en, descr_en, tips_en) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $id, $ord, $cat, $title, str($in, 'desc', 160), !empty($in['popular']) && $in['popular'] !== '0' ? 1 : 0,
        json_encode($tools), $prompt, str($in, 'tips', 400), $image, gmdate('c'), $old['created_at'] ?? gmdate('c'),
        str($in, 'cat_en', 40), str($in, 'title_en', 80), str($in, 'desc_en', 160), str($in, 'tips_en', 400)]);
      $st = $pdo->prepare('SELECT * FROM prompts WHERE id = ?'); $st->execute([$id]);
      out(['ok' => true, 'prompt' => rowToPrompt($st->fetch())]);
    }

    case 'prompt_delete': {
      requireAdmin();
      $id = str(input(), 'id', 40); $pdo = db();
      $st = $pdo->prepare('SELECT image FROM prompts WHERE id = ?'); $st->execute([$id]);
      $img = (string)$st->fetchColumn();
      $pdo->prepare('DELETE FROM prompts WHERE id = ?')->execute([$id]);
      $ts = $pdo->prepare('SELECT image, input_image FROM prompt_tests WHERE prompt_id = ?'); $ts->execute([$id]);
      foreach ($ts->fetchAll() as $t) foreach ([$t['image'], $t['input_image']] as $f) if ($f && strpos((string)$f, 'uploads/') === 0) @unlink(__DIR__ . '/' . $f);
      $pdo->prepare('DELETE FROM prompt_tests WHERE prompt_id = ?')->execute([$id]);
      if (strpos($img, 'uploads/') === 0) @unlink(__DIR__ . '/' . $img);
      out(['ok' => true]);
    }

    case 'recent_orders': {
      // publik: pesanan asli 14 hari terakhir, nama disamarkan (untuk notifikasi di landing page)
      $st = db()->prepare("SELECT name, plan, state, created_at FROM orders WHERE created_at >= ? AND state IN ('aktif','lunas','perlu_cek','belum_lunas') ORDER BY created_at DESC LIMIT 20");
      $st->execute([gmdate('c', time() - 14 * 86400)]);
      $list = [];
      foreach ($st->fetchAll() as $r) {
        $first = trim(explode(' ', trim((string)$r['name']))[0] ?? '');
        if ($first === '') continue;
        $masked = mb_strtoupper(mb_substr($first, 0, 1)) . str_repeat('*', max(3, min(6, mb_strlen($first) - 1)));
        $list[] = ['name' => $masked, 'plan' => (string)$r['plan'], 'paid' => $r['state'] !== 'belum_lunas', 'at' => (string)$r['created_at']];
      }
      header('Cache-Control: public, max-age=60');
      out(['ok' => true, 'orders' => $list]);
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
        'email' => str($in, 'email', 120) ?: ($old['email'] ?? ''), 'phone' => $old['phone'] ?? '', 'role' => $role], $pdo);
      $st->execute([$username]);
      $p = publicMember($st->fetch()); $p['builtin'] = false; $p['self'] = false;
      out(['ok' => true, 'admin' => $p, 'code' => $newCode]);
    }

    case 'admin_delete': {
      $me = requireSuperAdmin();
      $username = strtolower(str(input(), 'username', 30));
      if ($username === strtolower(ADMIN_USER) || $username === $me['username']) fail('Tidak bisa menghapus akun ini.');
      $st = db()->prepare("SELECT role FROM members WHERE username = ?"); $st->execute([$username]);
      $role = (string)$st->fetchColumn();
      if ($role !== 'admin' && $role !== 'super_admin') fail('Akun admin tidak ditemukan.', 404);
      db()->prepare('DELETE FROM members WHERE username = ?')->execute([$username]);
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
      $st->execute([$username]);
      out(['ok' => true, 'member' => publicMember($st->fetch()), 'code' => $newCode]);
    }

    case 'orders': {
      requireSuperAdmin();
      $rows = db()->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200')->fetchAll();
      $log = db()->query('SELECT ts, ip, event, verified, headers, note FROM webhook_log ORDER BY id DESC LIMIT 15')->fetchAll();
      out(['ok' => true, 'orders' => array_map('publicOrder', $rows), 'log' => $log, 'settings' => [
        'hasToken' => setting('mayar_webhook_token') !== '',
        'adminEmail' => setting('admin_email'), 'mailFrom' => setting('mail_from', 'no-reply@oziera.co.id'),
        'webhookUrl' => siteUrl() . 'webhook-mayar.php',
        'autoWithoutToken' => setting('auto_without_token') === '1',
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
      $ae = str($in, 'adminEmail', 120);
      if ($ae !== '' && !filter_var($ae, FILTER_VALIDATE_EMAIL)) fail('Email admin tidak valid.');
      setSetting('admin_email', $ae);
      $mf = str($in, 'mailFrom', 120);
      if ($mf !== '' && !filter_var($mf, FILTER_VALIDATE_EMAIL)) fail('Email pengirim tidak valid.');
      setSetting('mail_from', $mf !== '' ? $mf : 'no-reply@oziera.co.id');
      setSetting('auto_without_token', !empty($in['autoWithoutToken']) ? '1' : '0');
      out(['ok' => true]);
    }

    case 'test_email': {
      requireSuperAdmin();
      $to = setting('admin_email');
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Isi dan simpan email admin dulu.');
      $ok = @mail($to, 'Tes email ResepFoto', "Email dari server ResepFoto berhasil terkirim.

" . siteUrl(), mailHeaders());
      if (!$ok) fail('Server menolak mengirim email. Cek pengaturan email di cPanel.');
      out(['ok' => true]);
    }

    case 'member_delete': {
      requireAdmin();
      $username = str(input(), 'username', 30);
      $st = db()->prepare("SELECT role FROM members WHERE username = ?"); $st->execute([$username]);
      if (in_array((string)$st->fetchColumn(), ['admin', 'super_admin'], true)) fail('Akun ini dikelola di tab Admin.');
      db()->prepare("DELETE FROM members WHERE username = ? AND COALESCE(role,'member') = 'member'")->execute([$username]);
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
      out(['ok' => true, 'settings' => [
        'hasKey' => $key !== '', 'keyHint' => $key !== '' ? substr($key, -4) : '',
        'model' => aiModel(), 'imageModel' => aiImageModel(),
        'defaultModel' => AI_DEFAULT_MODEL, 'defaultImageModel' => AI_DEFAULT_IMAGE_MODEL,
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
      $m = str($in, 'model', 60); $im = str($in, 'imageModel', 60);
      foreach ([$m, $im] as $x) if ($x !== '' && !preg_match('/^[a-z0-9][a-z0-9.\-]{2,59}$/', $x)) fail('Nama model tidak valid.');
      setSetting('gemini_model', $m);
      setSetting('gemini_image_model', $im);
      out(['ok' => true]);
    }

    case 'ai_test': {
      requireSuperAdmin();
      $r = geminiCall('test', [['text' => 'Balas persis dengan satu kata: SIAP']]);
      out(['ok' => true, 'reply' => mb_substr(trim($r['text']), 0, 60), 'ms' => $r['ms'], 'model' => $r['model']]);
    }

    case 'ai_link': {
      requireAdmin();
      @set_time_limit(180);
      $rec = recipeFromLink(str(input(), 'url', 500));
      out(['ok' => true, 'recipe' => $rec]);
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
      $pdo->prepare('INSERT INTO lt_events (ts, day, vid, sid, type, plan, src, med, camp, content, ref, device, page) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([gmdate('c'), $day, $vid, cleanTag($j['sid'] ?? '', 32), $type, cleanTag($j['plan'] ?? '', 20), $src,
          strtolower(cleanTag($j['med'] ?? '', 60)), cleanTag($j['camp'] ?? '', 80), cleanTag($j['content'] ?? '', 80), $refHost, deviceOf($ua), $page]);
      if ($type === 'view') $pdo->prepare('INSERT OR REPLACE INTO presence (vid, ts, page) VALUES (?,?,?)')->execute([$vid, time(), $page]);
      out(['ok' => true]);
    }

    case 'live': {
      header('Cache-Control: public, max-age=15');
      $pdo = db();
      $now = $pdo->prepare('SELECT COUNT(*) FROM presence WHERE ts >= ?'); $now->execute([time() - 60]);
      $d = $pdo->prepare("SELECT COUNT(DISTINCT vid) FROM lt_events WHERE type = 'view' AND ts >= ?"); $d->execute([gmdate('c', time() - 86400)]);
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
        'spendRows' => $spendRows, 'tracking' => (int)$pdo->query('SELECT COUNT(*) FROM lt_events')->fetchColumn() > 0]);
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

    default:
      fail('Endpoint tidak dikenal.', 404);
  }
} catch (RfError $e) {
  fail($e->getMessage(), 422);
} catch (Throwable $e) {
  error_log('[resepfoto] ' . $e->getMessage());
  fail('Terjadi kesalahan di server. Coba lagi sebentar lagi.', 500);
}
