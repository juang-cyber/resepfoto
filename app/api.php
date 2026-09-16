<?php
/**
 * ResepFoto API — PHP + SQLite
 * Semua data disimpan di SQLite dalam folder data/ (diblokir dari web). Fungsi bersama ada di lib.php.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

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
  if ($s['role'] === 'admin') return ['role' => 'admin', 'username' => ADMIN_USER, 'name' => 'Admin ResepFoto', 'plan' => 'Admin', 'expires' => ''];
  $st = db()->prepare('SELECT * FROM members WHERE username = ?');
  $st->execute([$s['username']]);
  $m = $st->fetch();
  if (!$m || memberStatus($m) !== 'ok') { unset($_SESSION['user']); return null; }
  return ['role' => 'member'] + publicMember($m);
}
function requireUser(): array { $u = currentUser(); if (!$u) fail('Sesi berakhir. Silakan masuk lagi.', 401); return $u; }
function requireAdmin(): void { $u = requireUser(); if ($u['role'] !== 'admin') fail('Khusus admin.', 403); }
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

/* ---------------- routes ---------------- */
$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if ($method === 'POST' && !hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.', 419);

try {
  switch ($a) {
    case 'me':
      out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => currentUser()]);

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
      $missing = array_filter([$title === '' ? 'judul' : '', $cat === '' ? 'kategori' : '', $prompt === '' ? 'prompt' : '', (!$old && !$hasFile) ? 'gambar contoh' : '']);
      if ($missing) fail('Lengkapi dulu: ' . implode(', ', $missing) . '.');
      $image = $old['image'] ?? '';
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
      $rows = db()->query('SELECT * FROM members ORDER BY name COLLATE NOCASE')->fetchAll();
      out(['ok' => true, 'members' => array_map('publicMember', $rows)]);
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
      $newCode = null;
      if ($isNew || !empty($in['resetCode'])) $newCode = strtoupper(str($in, 'code', 40)) ?: genCode();
      $hash = $newCode ? password_hash($newCode, PASSWORD_DEFAULT) : $old['code_hash'];
      $hint = $newCode ? substr($newCode, -4) : $old['code_hint'];
      $email = str($in, 'email', 120);
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Format email tidak valid.');
      saveMember(['username' => $username, 'name' => $name, 'code_hash' => $hash, 'code_hint' => $hint, 'plan' => $plan,
        'expires' => $expires, 'active' => !empty($in['active']) ? 1 : 0, 'created_at' => $old['created_at'] ?? gmdate('c'),
        'last_login' => $old['last_login'] ?? null, 'email' => $email !== '' ? $email : ($old['email'] ?? ''),
        'phone' => str($in, 'phone', 30) ?: ($old['phone'] ?? '')], $pdo);
      $st->execute([$username]);
      out(['ok' => true, 'member' => publicMember($st->fetch()), 'code' => $newCode]);
    }

    case 'orders': {
      requireAdmin();
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
      requireAdmin();
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
      requireAdmin();
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
      requireAdmin();
      $to = setting('admin_email');
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Isi dan simpan email admin dulu.');
      $ok = @mail($to, 'Tes email ResepFoto', "Email dari server ResepFoto berhasil terkirim.

" . siteUrl(), mailHeaders());
      if (!$ok) fail('Server menolak mengirim email. Cek pengaturan email di cPanel.');
      out(['ok' => true]);
    }

    case 'member_delete': {
      requireAdmin();
      db()->prepare('DELETE FROM members WHERE username = ?')->execute([str(input(), 'username', 30)]);
      out(['ok' => true]);
    }

    default:
      fail('Endpoint tidak dikenal.', 404);
  }
} catch (Throwable $e) {
  error_log('[resepfoto] ' . $e->getMessage());
  fail('Terjadi kesalahan di server. Coba lagi sebentar lagi.', 500);
}
