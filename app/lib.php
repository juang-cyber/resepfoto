<?php
/**
 * ResepFoto — fungsi bersama (database, member, pesanan, email).
 * Dipakai oleh api.php dan webhook-mayar.php. Kompatibel PHP 7.4.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const PLAN_LIFETIME = ['Standard', 'Premium', 'Lifetime'];
const PLAN_ALL = ['Standard', 'Premium', 'Lifetime', 'Bulanan', 'Tahunan'];
// Paket resep tambahan di data/ — diimpor sekali per versi (lihat importPromptPacks()).
const PROMPT_PACKS = ['pack2-prompts.json'];

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = __DIR__ . '/data';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $pdo = new PDO('sqlite:' . $dir . '/' . (defined('DB_FILE') ? DB_FILE : 'app.sqlite'));
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA journal_mode=WAL');
  $pdo->exec('PRAGMA busy_timeout=5000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS prompts (
    id TEXT PRIMARY KEY, ord INTEGER DEFAULT 0, cat TEXT, title TEXT, descr TEXT, popular INTEGER DEFAULT 0,
    tools TEXT, prompt TEXT, tips TEXT, image TEXT, updated_at TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS members (
    username TEXT PRIMARY KEY, name TEXT, code_hash TEXT, code_hint TEXT, plan TEXT, expires TEXT,
    active INTEGER DEFAULT 1, created_at TEXT, last_login TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS attempts (ip TEXT, ts INTEGER)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
    id TEXT PRIMARY KEY, source TEXT, event TEXT, status TEXT, verified INTEGER DEFAULT 0, state TEXT,
    plan TEXT, product TEXT, amount INTEGER, name TEXT, email TEXT, phone TEXT,
    username TEXT, code TEXT, emailed INTEGER DEFAULT 0, note TEXT, raw TEXT, created_at TEXT, updated_at TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS webhook_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, ip TEXT, event TEXT, verified INTEGER, headers TEXT, note TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS ai_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, action TEXT, model TEXT, ok INTEGER, tokens_in INTEGER, tokens_out INTEGER, ms INTEGER, note TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS prompt_tests (id INTEGER PRIMARY KEY AUTOINCREMENT, prompt_id TEXT, image TEXT, input_image TEXT, source TEXT, model TEXT, tool TEXT, status TEXT, note TEXT, created_at TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS ix_tests_prompt ON prompt_tests(prompt_id)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, username TEXT, type TEXT, prompt_id TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS ix_events_ts ON events(ts)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS lt_events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, day TEXT, vid TEXT, sid TEXT, type TEXT, plan TEXT, src TEXT, med TEXT, camp TEXT, content TEXT, ref TEXT, device TEXT, page TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS ix_lt_day ON lt_events(day)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS presence (vid TEXT PRIMARY KEY, ts INTEGER, page TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS ad_spend (day TEXT, campaign TEXT, amount INTEGER, note TEXT, PRIMARY KEY (day, campaign))');
  // migrasi kolom baru
  $cols = array_column($pdo->query('PRAGMA table_info(prompts)')->fetchAll(), 'name');
  if (!in_array('created_at', $cols, true)) {
    $pdo->exec('ALTER TABLE prompts ADD COLUMN created_at TEXT');
    $pdo->exec("UPDATE prompts SET created_at = COALESCE(updated_at, '2026-09-01T00:00:00+00:00')");
  }
  $mcols = array_column($pdo->query('PRAGMA table_info(members)')->fetchAll(), 'name');
  if (!in_array('email', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN email TEXT');
  if (!in_array('phone', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN phone TEXT');
  if (!in_array('role', $mcols, true)) $pdo->exec("ALTER TABLE members ADD COLUMN role TEXT DEFAULT 'member'");
  if (!in_array('avatar', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN avatar TEXT');
  // kolom terjemahan Inggris
  if (!in_array('title_en', $cols, true)) {
    foreach (['cat_en', 'title_en', 'descr_en', 'tips_en'] as $c) $pdo->exec("ALTER TABLE prompts ADD COLUMN $c TEXT DEFAULT ''");
    if (is_file($dir . '/seed-en.json')) {
      $en = json_decode((string)file_get_contents($dir . '/seed-en.json'), true) ?: [];
      $st = $pdo->prepare("UPDATE prompts SET cat_en = ?, title_en = ?, descr_en = ?, tips_en = ? WHERE id = ? AND COALESCE(title_en, '') = ''");
      foreach ($en as $id => $v) $st->execute([$v[0], $v[1], $v[2], $v[3], $id]);
    }
  }

  if ((int)$pdo->query('SELECT COUNT(*) FROM prompts')->fetchColumn() === 0 && is_file($dir . '/seed-prompts.json')) {
    $seed = json_decode((string)file_get_contents($dir . '/seed-prompts.json'), true) ?: [];
    $en = is_file($dir . '/seed-en.json') ? (json_decode((string)file_get_contents($dir . '/seed-en.json'), true) ?: []) : [];
    $st = $pdo->prepare('INSERT INTO prompts (id, ord, cat, title, descr, popular, tools, prompt, tips, image, updated_at, created_at, cat_en, title_en, descr_en, tips_en) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($seed as $p) {
      $now = gmdate('c'); $e = $en[$p['id']] ?? ['', '', '', ''];
      $st->execute([$p['id'], $p['order'], $p['cat'], $p['title'], $p['desc'], $p['popular'] ? 1 : 0,
        json_encode($p['tools']), $p['prompt'], $p['tips'], $p['image'], $now, $now, $e[0], $e[1], $e[2], $e[3]]);
    }
  }
  if ((int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn() === 0 && defined('DEMO_MEMBER')) {
    [$u, $n, $c] = DEMO_MEMBER;
    saveMember(['username' => $u, 'name' => $n, 'code_hash' => password_hash($c, PASSWORD_DEFAULT), 'code_hint' => substr($c, -4),
      'plan' => 'Premium', 'expires' => '', 'active' => 1, 'created_at' => gmdate('c'), 'last_login' => null, 'email' => '', 'phone' => ''], $pdo);
  }
  importPromptPacks($pdo, $dir);
  return $pdo;
}

/**
 * Impor paket resep tambahan (data/pack*-prompts.json) — sekali per versi paket.
 * Dipakai karena seed awal hanya jalan saat tabel prompts masih kosong, sedangkan
 * database yang sudah dipakai perlu tetap menerima resep baru saat deploy.
 * INSERT OR IGNORE: resep yang id-nya sudah ada (mis. sudah diedit admin) tidak tertimpa.
 */
function importPromptPacks(PDO $pdo, string $dir): void {
  foreach (PROMPT_PACKS as $file) {
    $path = $dir . '/' . $file;
    if (!is_file($path)) continue;
    $pack = json_decode((string)file_get_contents($path), true);
    if (!is_array($pack) || empty($pack['version']) || empty($pack['prompts']) || !is_array($pack['prompts'])) continue;
    $key = 'pack_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$pack['version']);
    $chk = $pdo->prepare('SELECT 1 FROM settings WHERE k = ?');
    $chk->execute([$key]);
    if ($chk->fetchColumn() !== false) continue;            // paket ini sudah pernah diimpor
    try {
      $base = (int)$pdo->query('SELECT COALESCE(MAX(ord), 0) FROM prompts')->fetchColumn();
      $ins = $pdo->prepare('INSERT OR IGNORE INTO prompts
        (id, ord, cat, title, descr, popular, tools, prompt, tips, image, updated_at, created_at, cat_en, title_en, descr_en, tips_en)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $now = gmdate('c'); $i = 0; $added = 0;
      $pdo->beginTransaction();
      foreach ($pack['prompts'] as $p) {
        if (!is_array($p) || empty($p['id']) || empty($p['title'])) continue;
        $i++;
        $tools = isset($p['tools']) && is_array($p['tools'])
          ? array_values(array_intersect(['Gemini', 'ChatGPT'], $p['tools'])) : ['Gemini', 'ChatGPT'];
        $ins->execute([
          (string)$p['id'], $base + $i, (string)($p['cat'] ?? ''), (string)$p['title'], (string)($p['desc'] ?? ''),
          !empty($p['popular']) ? 1 : 0, json_encode($tools), (string)($p['prompt'] ?? ''), (string)($p['tips'] ?? ''),
          (string)($p['image'] ?? ''), $now, $now,
          (string)($p['cat_en'] ?? ''), (string)($p['title_en'] ?? ''), (string)($p['desc_en'] ?? ''), (string)($p['tips_en'] ?? '')]);
        $added += $ins->rowCount();
      }
      $pdo->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute([$key, $now . ' +' . $added]);
      $pdo->commit();
    } catch (Throwable $e) {                                 // paket rusak tidak boleh mematikan aplikasi
      if ($pdo->inTransaction()) $pdo->rollBack();
    }
  }
}

function setting(string $k, string $default = ''): string {
  $st = db()->prepare('SELECT v FROM settings WHERE k = ?'); $st->execute([$k]);
  $v = $st->fetchColumn();
  return $v === false ? $default : (string)$v;
}
function setSetting(string $k, string $v): void {
  db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function today(): string { return (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d'); }
function memberStatus(array $m): string {
  if (!(int)$m['active']) return 'off';
  if (!empty($m['expires']) && $m['expires'] < today()) return 'expired';
  return 'ok';
}
function publicMember(array $m): array {
  return ['username' => $m['username'], 'name' => $m['name'], 'plan' => $m['plan'], 'expires' => $m['expires'] ?: '',
    'active' => (bool)$m['active'], 'codeHint' => $m['code_hint'], 'status' => memberStatus($m),
    'email' => $m['email'] ?? '', 'phone' => $m['phone'] ?? '', 'role' => $m['role'] ?? 'member',
    'avatar' => $m['avatar'] ?? '',
    'createdAt' => $m['created_at'], 'lastLogin' => $m['last_login']];
}
function findMember(string $username): ?array {
  $st = db()->prepare('SELECT * FROM members WHERE username = ?'); $st->execute([$username]);
  $m = $st->fetch(); return $m ?: null;
}
function saveMember(array $m, ?PDO $pdo = null): void {
  $pdo = $pdo ?: db();
  $pdo->prepare('INSERT OR REPLACE INTO members (username, name, code_hash, code_hint, plan, expires, active, created_at, last_login, email, phone, role, avatar)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    $m['username'], $m['name'], $m['code_hash'], $m['code_hint'], $m['plan'], $m['expires'] ?? '', (int)$m['active'],
    $m['created_at'], $m['last_login'] ?? null, $m['email'] ?? '', $m['phone'] ?? '', $m['role'] ?? 'member', $m['avatar'] ?? '']);
}
function genCode(): string {
  $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $n = '23456789'; $s = 'RF-';
  for ($i = 0; $i < 4; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
  $s .= '-';
  for ($i = 0; $i < 4; $i++) $s .= $n[random_int(0, strlen($n) - 1)];
  return $s;
}
function siteUrl(): string {
  $host = $_SERVER['HTTP_HOST'] ?? 'resepfoto.oziera.co.id';
  if (!preg_match('/^[a-z0-9.-]+$/i', $host)) $host = 'resepfoto.oziera.co.id';
  return 'https://' . $host . '/';
}

/* ---------- pesanan ---------- */
function planFromProduct(string $product, int $amount): ?string {
  $p = strtolower($product);
  if (strpos($p, 'resepfoto') === false && strpos($p, 'resep foto') === false) return null; // bukan produk ResepFoto
  if (strpos($p, 'premium') !== false) return 'Premium';
  if (strpos($p, 'standard') !== false || strpos($p, 'standar') !== false) return 'Standard';
  return $amount >= 90000 ? 'Premium' : 'Standard';
}
function usernameFromEmail(string $email, string $name): string {
  $base = strtolower(explode('@', $email)[0] ?? '');
  if ($base === '') $base = strtolower($name);
  $base = preg_replace('/[^a-z0-9._-]/', '', $base);
  $base = trim(substr($base, 0, 20), '._-');
  if (strlen($base) < 3) $base = 'member' . $base;
  $u = $base; $i = 1;
  while (findMember($u) || $u === strtolower(ADMIN_USER)) { $i++; $u = $base . $i; }
  return $u;
}
function findOrder(string $id): ?array {
  $st = db()->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$id]);
  $o = $st->fetch(); return $o ?: null;
}
function publicOrder(array $o): array {
  return ['id' => $o['id'], 'state' => $o['state'], 'verified' => (bool)$o['verified'], 'status' => $o['status'], 'event' => $o['event'],
    'plan' => $o['plan'], 'product' => $o['product'], 'amount' => (int)$o['amount'], 'name' => $o['name'], 'email' => $o['email'],
    'phone' => $o['phone'], 'username' => $o['username'], 'code' => $o['code'], 'emailed' => (bool)$o['emailed'],
    'note' => $o['note'], 'createdAt' => $o['created_at'], 'updatedAt' => $o['updated_at']];
}
function updateOrder(string $id, array $fields): void {
  $fields['updated_at'] = gmdate('c');
  $sets = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($fields)));
  db()->prepare("UPDATE orders SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
}

/** Buat/aktifkan member untuk pesanan yang sudah lunas. Mengembalikan pesanan terbaru. */
function fulfillOrder(string $id, bool $sendEmail = true): array {
  $o = findOrder($id);
  if (!$o) throw new RuntimeException('Pesanan tidak ditemukan.');
  if ($o['state'] === 'aktif' && $o['username']) return $o;
  $plan = $o['plan'] ?: 'Standard';
  $code = genCode();
  $existing = null;
  if ($o['email']) {
    $st = db()->prepare('SELECT * FROM members WHERE lower(email) = lower(?) LIMIT 1'); $st->execute([$o['email']]);
    $existing = $st->fetch() ?: null;
  }
  if ($existing) {
    $m = $existing;
    if ($plan === 'Premium') $m['plan'] = 'Premium';
    $m['active'] = 1; $m['expires'] = in_array($m['plan'], PLAN_LIFETIME, true) ? '' : $m['expires'];
    $m['code_hash'] = password_hash($code, PASSWORD_DEFAULT); $m['code_hint'] = substr($code, -4);
    if (!$m['phone'] && $o['phone']) $m['phone'] = $o['phone'];
    saveMember($m);
    $username = $m['username'];
  } else {
    $username = usernameFromEmail((string)$o['email'], (string)$o['name']);
    saveMember(['username' => $username, 'name' => $o['name'] ?: $username, 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
      'code_hint' => substr($code, -4), 'plan' => $plan, 'expires' => '', 'active' => 1, 'created_at' => gmdate('c'),
      'last_login' => null, 'email' => $o['email'], 'phone' => $o['phone']]);
  }
  updateOrder($id, ['state' => 'aktif', 'username' => $username, 'code' => $code]);
  if ($sendEmail) sendAccessEmail($id);
  notifyAdmin($id);
  return findOrder($id);
}

function accessMessage(array $o): string {
  $first = trim(explode(' ', (string)$o['name'])[0]) ?: 'Kak';
  return "Halo $first! Terima kasih sudah membeli ResepFoto {$o['plan']}.\n\n"
    . "Link: " . siteUrl() . "\n"
    . "Username: {$o['username']}\n"
    . "Kode akses: {$o['code']}\n"
    . "Paket: {$o['plan']} (akses selamanya)\n\n"
    . "Simpan pesan ini ya. Selamat mencoba!";
}
function mailHeaders(): string {
  $from = setting('mail_from', 'no-reply@oziera.co.id');
  if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'no-reply@oziera.co.id';
  return "From: ResepFoto <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: ResepFoto";
}
function sendAccessEmail(string $id): bool {
  $o = findOrder($id);
  if (!$o || !$o['email'] || !filter_var($o['email'], FILTER_VALIDATE_EMAIL) || !$o['code']) return false;
  $ok = @mail($o['email'], '=?UTF-8?B?' . base64_encode('Akses ResepFoto kamu sudah aktif') . '?=', accessMessage($o), mailHeaders());
  updateOrder($id, ['emailed' => $ok ? 1 : 0, 'note' => $ok ? 'Email akses terkirim.' : 'Email gagal dikirim server. Kirim manual via WA.']);
  return $ok;
}
function notifyAdmin(string $id): void {
  $to = setting('admin_email');
  $o = findOrder($id);
  if (!$o || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
  $body = "Penjualan baru ResepFoto\n\nPaket: {$o['plan']}\nNominal: Rp " . number_format((int)$o['amount'], 0, ',', '.')
    . "\nNama: {$o['name']}\nEmail: {$o['email']}\nHP: {$o['phone']}\nUsername: {$o['username']}\nStatus: {$o['state']}";
  @mail($to, '=?UTF-8?B?' . base64_encode('Penjualan baru: ResepFoto ' . $o['plan']) . '?=', $body, mailHeaders());
}
