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
// Penulis yang dicatatkan untuk resep yang sudah ada sebelum kolom created_by dibuat.
const LEGACY_PROMPT_AUTHOR = 'daffa';
const ADMIN_DISPLAY_NAME = 'Admin ResepFoto';

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
  $pdo->exec('CREATE INDEX IF NOT EXISTS ix_lt_ts ON lt_events(ts)');   // dipakai recent_orders (aktivitas keranjang) & live
  $pdo->exec('CREATE TABLE IF NOT EXISTS presence (vid TEXT PRIMARY KEY, ts INTEGER, page TEXT)');
  // Tempat sampah resep. Barisnya disimpan utuh sebagai JSON supaya bisa dipulihkan apa adanya,
  // dan file gambarnya sengaja TIDAK ikut dihapus sampai sampahnya benar-benar dikosongkan.
  $pdo->exec('CREATE TABLE IF NOT EXISTS prompts_trash (id TEXT PRIMARY KEY, data TEXT, deleted_at TEXT, deleted_by TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS ad_spend (day TEXT, campaign TEXT, amount INTEGER, note TEXT, PRIMARY KEY (day, campaign))');
  // migrasi kolom baru
  $cols = array_column($pdo->query('PRAGMA table_info(prompts)')->fetchAll(), 'name');
  if (!in_array('created_at', $cols, true)) {
    $pdo->exec('ALTER TABLE prompts ADD COLUMN created_at TEXT');
    $pdo->exec("UPDATE prompts SET created_at = COALESCE(updated_at, '2026-09-01T00:00:00+00:00')");
  }
  if (!in_array('created_by', $cols, true)) $pdo->exec('ALTER TABLE prompts ADD COLUMN created_by TEXT');
  // kolom review (panel admin): status QC, penilaian hasil, dan penanda resep khusus Inggris
  if (!in_array('qc_status', $cols, true)) $pdo->exec("ALTER TABLE prompts ADD COLUMN qc_status TEXT DEFAULT ''");
  if (!in_array('result_status', $cols, true)) $pdo->exec("ALTER TABLE prompts ADD COLUMN result_status TEXT DEFAULT ''");
  if (!in_array('en_only', $cols, true)) $pdo->exec('ALTER TABLE prompts ADD COLUMN en_only INTEGER DEFAULT 0');
  $mcols = array_column($pdo->query('PRAGMA table_info(members)')->fetchAll(), 'name');
  if (!in_array('email', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN email TEXT');
  if (!in_array('phone', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN phone TEXT');
  if (!in_array('role', $mcols, true)) $pdo->exec("ALTER TABLE members ADD COLUMN role TEXT DEFAULT 'member'");
  if (!in_array('avatar', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN avatar TEXT');
  $ocols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
  if (!in_array('wa_sent', $ocols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN wa_sent INTEGER DEFAULT 0');
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
  fixAmbiguousPrompts($pdo);
  backfillPromptAuthors($pdo, $dir);
  return $pdo;
}

/**
 * Isi kolom created_by untuk resep lama — sekali saja.
 * Resep dari paket resep dicatat atas nama super admin bawaan, sisanya atas nama
 * LEGACY_PROMPT_AUTHOR. Resep baru mengisi created_by sendiri lewat `prompt_save`.
 */
/**
 * Sekali jalan: bedakan dua judul Spider-Man yang nyaris kembar, dan perjelas bahwa
 * resep iklan jaring laba-laba butuh foto PRODUK, bukan foto orang — tiga resep itu
 * bersebelahan di katalog dan mudah tertukar.
 * importPromptPacks() memakai INSERT OR IGNORE, jadi memperbaiki JSON saja tidak
 * menyentuh database yang sudah berisi. Nilai hanya diganti kalau MASIH persis seperti
 * aslinya, sehingga suntingan admin tidak pernah tertimpa.
 */
function fixAmbiguousPrompts(PDO $pdo): void {
  $chk = $pdo->prepare('SELECT 1 FROM settings WHERE k = ?');
  $chk->execute(['fix_ambigu_spiderman']);
  if ($chk->fetchColumn() !== false) return;
  try {
    $pdo->beginTransaction();
    $tt = $pdo->prepare('UPDATE prompts SET title = ?, title_en = ? WHERE id = ? AND title = ?');
    $tt->execute(['Selfie Spider-Man di Times Square', 'Spider-Man Selfie in Times Square', 'p24', 'Selfie Spider-Man']);
    $tt->execute(['Photobooth Spider-Man 4 Pose', 'Spider-Man Photobooth (4 Poses)', 'p33', 'Photobooth Spider-Man']);
    $pdo->prepare('UPDATE prompts SET descr = ?, descr_en = ? WHERE id = ? AND descr = ?')->execute([
      'Upload foto PRODUK (bukan foto orang). Iklan sinematik: produkmu melayang di atas jalanan kota, digantung jaring laba-laba tebal saat senja.',
      'Upload a PRODUCT photo (not a person). Cinematic ad: your product floats above the city street, slung from thick spiderwebs at dusk.',
      'p34', 'Iklan sinematik: produkmu melayang di atas jalanan kota, digantung jaring laba-laba tebal saat senja.']);
    $pdo->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['fix_ambigu_spiderman', gmdate('c')]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

function backfillPromptAuthors(PDO $pdo, string $dir): void {
  $chk = $pdo->prepare('SELECT 1 FROM settings WHERE k = ?');
  $chk->execute(['backfill_created_by']);
  if ($chk->fetchColumn() !== false) return;
  try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE prompts SET created_by = ? WHERE COALESCE(created_by, \'\') = \'\'')
        ->execute([resolveAuthorUsername($pdo, LEGACY_PROMPT_AUTHOR)]);
    $admin = defined('ADMIN_USER') ? ADMIN_USER : 'admin';
    foreach (PROMPT_PACKS as $file) {
      $path = $dir . '/' . $file;
      if (!is_file($path)) continue;
      $pack = json_decode((string)file_get_contents($path), true);
      if (!is_array($pack) || empty($pack['prompts']) || !is_array($pack['prompts'])) continue;
      $ids = [];
      foreach ($pack['prompts'] as $p) if (!empty($p['id'])) $ids[] = (string)$p['id'];
      if (!$ids) continue;
      $in = implode(',', array_fill(0, count($ids), '?'));
      $pdo->prepare("UPDATE prompts SET created_by = ? WHERE id IN ($in)")->execute(array_merge([$admin], $ids));
    }
    $pdo->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['backfill_created_by', gmdate('c')]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

/**
 * Cocokkan nama penulis dengan akun admin yang ada supaya foto profilnya ikut terpakai.
 * Hanya akun beperan admin/super admin yang dicocokkan — resep memang hanya bisa dibuat admin,
 * jadi member biasa yang kebetulan senama tidak ikut terpilih. Kalau tidak ada yang cocok,
 * namanya disimpan apa adanya dan tampil sebagai label tanpa foto.
 */
function resolveAuthorUsername(PDO $pdo, string $want): string {
  $st = $pdo->prepare("SELECT username FROM members WHERE role IN ('admin', 'super_admin') AND lower(username) = lower(?) LIMIT 1");
  $st->execute([$want]);
  $u = $st->fetchColumn();
  if ($u !== false) return (string)$u;
  $st = $pdo->prepare("SELECT username FROM members WHERE role IN ('admin', 'super_admin') AND lower(name) LIKE lower(?) ORDER BY username LIMIT 1");
  $st->execute([$want . '%']);
  $u = $st->fetchColumn();
  return $u !== false ? (string)$u : $want;
}

/** Peta penulis resep: username → nama & foto, untuk ditampilkan di panel admin. */
function promptAuthors(PDO $pdo): array {
  $admin = defined('ADMIN_USER') ? ADMIN_USER : 'admin';
  $out = [];
  $rows = $pdo->query('SELECT DISTINCT created_by FROM prompts WHERE COALESCE(created_by, \'\') <> \'\'')->fetchAll();
  $st = $pdo->prepare('SELECT name, avatar, role FROM members WHERE username = ?');
  foreach ($rows as $r) {
    $u = (string)$r['created_by'];
    if ($u === $admin) { $out[$u] = ['name' => ADMIN_DISPLAY_NAME, 'avatar' => setting('admin_avatar'), 'role' => 'super_admin']; continue; }
    $st->execute([$u]);
    $m = $st->fetch();
    $out[$u] = ['name' => $m ? (string)$m['name'] : $u, 'avatar' => $m ? (string)($m['avatar'] ?? '') : '',
      'role' => $m ? (string)($m['role'] ?? 'member') : ''];
  }
  return $out;
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
/* ---------------- hak akses katalog per paket ---------------- */
const VIRAL_CAT = 'Tren Viral';

/** Jatah per paket: [best seller, Tren Viral, regular]. -1 berarti semuanya. */
function planQuota(string $plan): ?array {
  $q = ['Trial' => [3, 1, 6], 'Standard' => [10, 10, -1]];
  return isset($q[$plan]) ? $q[$plan] : null;         // null = paket bebas (Premium dsb.)
}

/**
 * Ambil $n id secara stabil. Seed kosong = pakai urutan kurasi (ord) apa adanya;
 * seed terisi = diacak tapi tetap sama tiap kali dipanggil untuk seed yang sama,
 * supaya katalog member tidak berubah-ubah tiap halaman dimuat.
 */
function pickStable(array $ids, int $n, string $seed): array {
  if ($n <= 0 || !$ids) return [];
  if (count($ids) <= $n) return $ids;
  if ($seed !== '') usort($ids, function ($a, $b) use ($seed) {
    return strcmp(md5($seed . '|' . $a), md5($seed . '|' . $b));
  });
  return array_slice($ids, 0, $n);
}

/**
 * Peta id resep yang boleh dibuka paket ini (id => true). null berarti semuanya boleh.
 * Ember-nya saling lepas dan best seller menang: resep yang popular DAN Tren Viral
 * dihitung sebagai best seller, tidak dua kali.
 */
function allowedPromptIds(array $rows, string $plan, string $seed): ?array {
  $q = planQuota($plan);
  if ($q === null) return null;
  list($nPop, $nVir, $nReg) = $q;
  $pop = $vir = $reg = [];
  foreach ($rows as $r) {
    if ((int)$r['popular']) $pop[] = $r['id'];
    elseif ((string)$r['cat'] === VIRAL_CAT) $vir[] = $r['id'];
    else $reg[] = $r['id'];
  }
  $take = array_merge(
    pickStable($pop, $nPop, ''),                               // best seller: urutan kurasi
    pickStable($vir, $nVir, $nVir === 1 ? $seed : ''),         // jatah 1 (Trial) diacak per member
    $nReg < 0 ? $reg : pickStable($reg, $nReg, $seed)
  );
  return array_fill_keys($take, true);
}

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
  $host = $_SERVER['HTTP_HOST'] ?? 'resepfoto.kitlab.id';
  if (!preg_match('/^[a-z0-9.-]+$/i', $host)) $host = 'resepfoto.kitlab.id';
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
    'waSent' => (bool)($o['wa_sent'] ?? 0),
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
  if ($sendEmail) { sendAccessEmail($id); sendAccessWa($id); }
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
/* ---------- email ---------- */
/** Alamat pengirim; harus di domain sendiri supaya SPF & DKIM cocok. */
function mailFrom(): string {
  $from = setting('mail_from', 'no-reply@kitlab.id');
  return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'no-reply@kitlab.id';
}
function mailSubject(string $s): string { return '=?UTF-8?B?' . base64_encode($s) . '?='; }
/**
 * Header RFC 5322 lengkap. Tanpa MIME-Version, Date dan Message-ID penyaring
 * spam memberi nilai buruk walaupun SPF & DKIM sudah benar.
 */
function mailHeaders(?string $from = null): string {
  $from = $from ?: mailFrom();
  $domain = ltrim((string)strrchr($from, '@'), '@') ?: 'kitlab.id';
  return "From: ResepFoto <$from>\r\n"
    . "Reply-To: $from\r\n"
    . 'Date: ' . date('r') . "\r\n"
    . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: base64\r\n"
    . "Auto-Submitted: auto-generated\r\n"
    . 'X-Mailer: ResepFoto';
}
/**
 * Kirim email. Pakai SMTP kalau smtp_host diisi di panel admin; kalau tidak,
 * mail() dengan envelope sender (-f) supaya Return-Path sejajar dengan From —
 * itu syarat SPF/DMARC lolos dan email tidak dianggap spam.
 * Melempar RuntimeException berisi sebab yang aman ditampilkan ke admin.
 */
function sendMailOrFail(string $to, string $subject, string $body): void {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Alamat tujuan tidak valid.');
  $from = mailFrom();
  if (setting('smtp_host') !== '') { smtpSend($to, $subject, $body, $from); return; }
  $headers = mailHeaders($from);
  $enc = chunk_split(base64_encode($body));
  $subj = mailSubject($subject);
  if (@mail($to, $subj, $enc, $headers, '-f' . $from)) return;
  if (@mail($to, $subj, $enc, $headers)) return;          // sebagian host melarang parameter -f
  throw new RuntimeException('Server menolak mengirim email (fungsi mail() gagal).');
}
function sendMail(string $to, string $subject, string $body): bool {
  try { sendMailOrFail($to, $subject, $body); return true; } catch (Throwable $e) { return false; }
}
/** Klien SMTP minimal: AUTH LOGIN, STARTTLS (587) atau SSL langsung (465). */
function smtpSend(string $to, string $subject, string $body, string $from): void {
  $host = setting('smtp_host');
  $port = (int)setting('smtp_port', '587'); if ($port <= 0) $port = 587;
  $secure = strtolower(setting('smtp_secure', 'tls'));
  $user = setting('smtp_user');
  $pass = setting('smtp_pass');
  $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port, $errno, $errstr, 20);
  if (!$fp) throw new RuntimeException("SMTP: tidak bisa terhubung ke $host:$port ($errstr)");
  stream_set_timeout($fp, 20);
  $read = function () use ($fp) {
    $out = '';
    while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
    return $out;
  };
  // $label dipakai supaya baris kredensial tidak ikut masuk ke pesan error
  $cmd = function (string $c, string $expect, string $label = '') use ($fp, $read) {
    if ($c !== '') fwrite($fp, $c . "\r\n");
    $r = $read();
    if (strncmp($r, $expect, strlen($expect)) !== 0) throw new RuntimeException('SMTP ' . ($label !== '' ? $label : trim($c)) . ': ' . trim($r));
    return $r;
  };
  $ehlo = 'resepfoto.kitlab.id';
  $cmd('', '220', 'sambungan');
  $cmd('EHLO ' . $ehlo, '250');
  if ($secure === 'tls') {
    $cmd('STARTTLS', '220');
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { @fclose($fp); throw new RuntimeException('SMTP: STARTTLS gagal.'); }
    $cmd('EHLO ' . $ehlo, '250');
  }
  if ($user !== '') {
    $cmd('AUTH LOGIN', '334', 'AUTH');
    $cmd(base64_encode($user), '334', 'AUTH pengguna');
    $cmd(base64_encode($pass), '235', 'AUTH sandi');
  }
  $cmd('MAIL FROM:<' . $from . '>', '250');
  $cmd('RCPT TO:<' . $to . '>', '250');
  $cmd('DATA', '354');
  $msg = mailHeaders($from) . "\r\nTo: <$to>\r\nSubject: " . mailSubject($subject) . "\r\n\r\n" . chunk_split(base64_encode($body));
  fwrite($fp, preg_replace('/^\./m', '..', $msg) . "\r\n.\r\n");
  $cmd('', '250', 'kirim');
  @fwrite($fp, "QUIT\r\n");
  @fclose($fp);
}
function sendAccessEmail(string $id): bool {
  $o = findOrder($id);
  if (!$o || !$o['email'] || !filter_var($o['email'], FILTER_VALIDATE_EMAIL) || !$o['code']) return false;
  $ok = sendMail((string)$o['email'], 'Akses ResepFoto kamu sudah aktif', accessMessage($o));
  updateOrder($id, ['emailed' => $ok ? 1 : 0, 'note' => $ok ? 'Email akses terkirim.' : 'Email gagal dikirim server. Kirim manual via WA.']);
  return $ok;
}

/* ---------- WhatsApp (Fonnte) ---------- */
/** Ubah 08xx / +62xx menjadi format 62xx yang dipakai Fonnte. */
function waNumber(string $phone): string {
  $p = preg_replace('/[^0-9]/', '', $phone);
  if ($p === '') return '';
  if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
  elseif (strpos($p, '62') !== 0) $p = '62' . $p;
  return (strlen($p) >= 10 && strlen($p) <= 15) ? $p : '';
}
/** Kirim pesan WhatsApp lewat Fonnte. Melempar RuntimeException kalau gagal. */
function waSendOrFail(string $phone, string $message): void {
  $token = setting('fonnte_token');
  if ($token === '') throw new RuntimeException('Token Fonnte belum diisi di panel.');
  $target = waNumber($phone);
  if ($target === '') throw new RuntimeException('Nomor WhatsApp tidak valid.');
  $post = http_build_query(['target' => $target, 'message' => $message, 'countryCode' => '62']);
  if (function_exists('curl_init')) {
    $ch = curl_init('https://api.fonnte.com/send');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 12,
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
      CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Content-Type: application/x-www-form-urlencoded']]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException('Fonnte tidak bisa dihubungi: ' . $err);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 25, 'ignore_errors' => true,
      'header' => "Authorization: $token\r\nContent-Type: application/x-www-form-urlencoded\r\n", 'content' => $post]]);
    $res = @file_get_contents('https://api.fonnte.com/send', false, $ctx);
    if ($res === false) throw new RuntimeException('Fonnte tidak bisa dihubungi.');
  }
  $j = json_decode((string)$res, true);
  if (!is_array($j) || empty($j['status'])) {
    $reason = is_array($j) ? (string)($j['reason'] ?? $j['detail'] ?? '') : '';
    throw new RuntimeException('Fonnte menolak: ' . ($reason !== '' ? $reason : mb_substr((string)$res, 0, 120)));
  }
}
function waSend(string $phone, string $message): bool {
  try { waSendOrFail($phone, $message); return true; } catch (Throwable $e) { return false; }
}
/** Kirim detail akses ke WhatsApp pembeli (kalau nomor & token ada). */
function sendAccessWa(string $id): bool {
  $o = findOrder($id);
  if (!$o || !$o['phone'] || !$o['code'] || setting('fonnte_token') === '') return false;
  $ok = waSend((string)$o['phone'], accessMessage($o));
  updateOrder($id, ['wa_sent' => $ok ? 1 : 0]);
  return $ok;
}

function notifyAdmin(string $id): void {
  $o = findOrder($id);
  if (!$o) return;
  $body = "Penjualan baru ResepFoto\n\nPaket: {$o['plan']}\nNominal: Rp " . number_format((int)$o['amount'], 0, ',', '.')
    . "\nNama: {$o['name']}\nEmail: {$o['email']}\nHP: {$o['phone']}\nUsername: {$o['username']}\nStatus: {$o['state']}";
  $to = setting('admin_email');
  if (filter_var($to, FILTER_VALIDATE_EMAIL)) sendMail($to, 'Penjualan baru: ResepFoto ' . $o['plan'], $body);
  $wa = setting('admin_wa');
  if ($wa !== '' && setting('fonnte_token') !== '') waSend($wa, $body);
}
