<?php
/**
 * ResepFoto — fungsi bersama (database, member, pesanan, email).
 * Dipakai oleh api.php dan webhook-mayar.php. Kompatibel PHP 7.4.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const PLAN_LIFETIME = ['Standard', 'Premium', 'Lifetime'];
/** Tingkat diskon yang tersedia sebagai template voucher. */
const VOUCHER_TIERS = [10, 20, 30, 40, 50, 60, 70, 80, 90];
/** Jumlah resep yang didapat pembeli Standard BARU. Member lama tidak terpotong. */
const STANDARD_CAP = 100;
/** Komposisi jatah Standard baru: [best seller, Tren Viral]. Sisanya resep reguler. */
const STANDARD_MIX = [7, 5];
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
  // Sembilan TEMPLATE tingkat diskon (10%..90%), satu baris per persen. Pemilik tinggal
  // mengisi kodenya dari panel, tidak perlu membuat baris baru.
  // CATATAN PENTING: tabel ini TIDAK memotong harga apa pun. Potongan dihitung dan
  // divalidasi Mayar lewat parameter ?coupon= pada link pembayaran, sehingga mengosongkan
  // kode di sini TIDAK mematikan kupon di Mayar.
  $vinfo = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='vouchers'")->fetchAll();
  if ($vinfo) {
    $pkCode = false;
    foreach ($pdo->query('PRAGMA table_info(vouchers)')->fetchAll() as $c) {
      if ($c['name'] === 'code' && (int)$c['pk'] === 1) $pkCode = true;
    }
    if ($pkCode) $pdo->exec('ALTER TABLE vouchers RENAME TO vouchers_lama');   // bentuk lama: satu baris per kode
  }
  $pdo->exec('CREATE TABLE IF NOT EXISTS vouchers (pct INTEGER PRIMARY KEY, code TEXT DEFAULT \'\', note TEXT DEFAULT \'\', active INTEGER DEFAULT 0, updated_at TEXT)');
  $benih = $pdo->prepare("INSERT OR IGNORE INTO vouchers (pct, code, note, active, updated_at) VALUES (?, '', '', 0, ?)");
  foreach (VOUCHER_TIERS as $tp) $benih->execute([$tp, gmdate('c')]);
  // kolom untuk kupon yang dibuat lewat API Mayar; baris lama tetap valid dengan nilai kosong
  $vcols = array_column($pdo->query('PRAGMA table_info(vouchers)')->fetchAll(), 'name');
  foreach (['quota' => 'INTEGER DEFAULT 0', 'expires' => "TEXT DEFAULT ''", 'kind' => "TEXT DEFAULT ''",
            'mayar_id' => "TEXT DEFAULT ''", 'synced_at' => "TEXT DEFAULT ''",
            'codes' => "TEXT DEFAULT ''"] as $kol => $def) {
    if (!in_array($kol, $vcols, true)) $pdo->exec("ALTER TABLE vouchers ADD COLUMN $kol $def");
  }
  // pindahkan kode dari bentuk lama, satu kode per tingkat
  if ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='vouchers_lama'")->fetchAll()) {
    $pindah = $pdo->prepare('UPDATE vouchers SET code = ?, note = ?, active = ? WHERE pct = ? AND code = \'\'');
    foreach ($pdo->query('SELECT * FROM vouchers_lama ORDER BY created_at') as $v) {
      if (in_array((int)$v['pct'], VOUCHER_TIERS, true)) $pindah->execute([$v['code'], $v['note'], (int)$v['active'], (int)$v['pct']]);
    }
    $pdo->exec('DROP TABLE vouchers_lama');
  }
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
  if (!in_array('session_token', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN session_token TEXT');
  // Batas jumlah resep untuk member Standard. Sengaja dibiarkan NULL pada member yang
  // sudah ada saat kolom ini dibuat, supaya akses mereka tidak berkurang.
  if (!in_array('plan_cap', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN plan_cap INTEGER');
  // Daftar id resep yang dibekukan untuk member Standard saat dia mendaftar (JSON).
  // NULL = member lama / Premium: tidak ada daftar beku, pakai aturan dinamis.
  if (!in_array('allow_ids', $mcols, true)) $pdo->exec('ALTER TABLE members ADD COLUMN allow_ids TEXT');
  $ocols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
  if (!in_array('wa_sent', $ocols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN wa_sent INTEGER DEFAULT 0');
  if (!in_array('reminded_at', $ocols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN reminded_at TEXT');
  if (!in_array('reminder_count', $ocols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN reminder_count INTEGER DEFAULT 0');
  $ltcols = array_column($pdo->query('PRAGMA table_info(lt_events)')->fetchAll(), 'name');
  if (!in_array('vou', $ltcols, true)) $pdo->exec('ALTER TABLE lt_events ADD COLUMN vou TEXT');
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
/**
 * Jatah katalog per paket: [best seller, Tren Viral, reguler]. -1 = semua.
 * $cap = batas TOTAL resep untuk member Standard. Diisi dari kolom members.plan_cap:
 *   - null  -> member Standard lama, aturan sebelumnya (semua resep reguler)
 *   - angka -> pembeli baru, totalnya dibatasi persis segitu
 * Pembedanya dengan Premium tetap sama: Premium bebas, termasuk semua Tren Viral
 * dan semua resep yang ditambahkan kemudian.
 */
function planQuota(string $plan, ?int $cap = null): ?array {
  if ($plan === 'Standard') {
    if ($cap === null) return [10, 10, -1];                       // member lama: aturan sebelumnya
    list($nPop, $nVir) = STANDARD_MIX;
    return [$nPop, $nVir, max(0, $cap - $nPop - $nVir)];          // sisanya resep reguler
  }
  $q = ['Trial' => [3, 1, 6]];
  return isset($q[$plan]) ? $q[$plan] : null;         // null = paket bebas (Premium dsb.)
}

/**
 * Bekukan jatah resep untuk pembeli Standard BARU, dihitung sekali saat dia mendaftar.
 *
 * - best seller & Tren Viral: diambil dari resep yang PALING BARU diunggah saat itu,
 *   supaya pembeli baru mendapat yang sedang hangat.
 * - reguler: diacak.
 * - hasilnya disimpan di members.allow_ids dan TIDAK PERNAH dihitung ulang, sehingga
 *   koleksinya tetap seumur hidup: tidak bertambah, dan yang sudah dimiliki tidak hilang.
 *   Resep baru -- termasuk tren viral baru -- hanya mengalir ke Premium.
 */
function freezeStandardIds(int $cap): array {
  list($nPop, $nVir) = STANDARD_MIX;
  $nPop = max(0, (int)$nPop); $nVir = max(0, (int)$nVir);
  $pdo = db();
  $pop = $pdo->query('SELECT id FROM prompts WHERE popular = 1 ORDER BY created_at DESC, ord DESC LIMIT ' . $nPop)
    ->fetchAll(PDO::FETCH_COLUMN);
  $st = $pdo->prepare('SELECT id FROM prompts WHERE popular = 0 AND cat = ? ORDER BY created_at DESC, ord DESC LIMIT ' . $nVir);
  $st->execute([VIRAL_CAT]);
  $vir = $st->fetchAll(PDO::FETCH_COLUMN);
  $sisa = max(0, $cap - count($pop) - count($vir));
  $reg = [];
  if ($sisa > 0) {
    $st = $pdo->prepare('SELECT id FROM prompts WHERE popular = 0 AND cat <> ? ORDER BY RANDOM() LIMIT ' . $sisa);
    $st->execute([VIRAL_CAT]);
    $reg = $st->fetchAll(PDO::FETCH_COLUMN);
  }
  return array_values(array_unique(array_merge($pop, $vir, $reg)));
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
function allowedPromptIds(array $rows, string $plan, string $seed, ?int $cap = null, ?array $fixed = null): ?array {
  // Daftar beku menang atas apa pun: inilah koleksi yang dikunci saat member mendaftar.
  if ($fixed !== null) return array_fill_keys($fixed, true);
  $q = planQuota($plan, $cap);
  if ($q === null) return null;
  list($nPop, $nVir, $nReg) = $q;
  $pop = $vir = $reg = [];
  foreach ($rows as $r) {
    if ((int)$r['popular']) $pop[] = $r['id'];
    elseif ((string)$r['cat'] === VIRAL_CAT) $vir[] = $r['id'];
    else $reg[] = $r['id'];
  }
  // Untuk Standard berbatas, jatah reguler diambil menurut URUTAN KURASI, bukan diacak.
  // Kalau diacak per member, setiap resep baru ikut diundi ulang dan bisa MENGGESER resep
  // yang sudah dimiliki member -- akses yang kemarin ada, besok hilang. Dengan urutan
  // kurasi, resep baru selalu ber-ord lebih besar sehingga tidak pernah menggeser apa pun:
  // koleksi Standard tetap, dan resep baru mengalir ke Premium saja.
  $seedReg = ($plan === 'Standard' && $cap !== null) ? '' : $seed;
  $take = array_merge(
    pickStable($pop, $nPop, ''),                               // best seller: urutan kurasi
    pickStable($vir, $nVir, $nVir === 1 ? $seed : ''),         // jatah 1 (Trial) diacak per member
    $nReg < 0 ? $reg : pickStable($reg, $nReg, $seedReg)
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
    'planCap' => isset($m['plan_cap']) && $m['plan_cap'] !== null ? (int)$m['plan_cap'] : null,
    'allowIds' => isset($m['allow_ids']) && $m['allow_ids'] !== null ? (json_decode((string)$m['allow_ids'], true) ?: []) : null,
    'createdAt' => $m['created_at'], 'lastLogin' => $m['last_login']];
}
function findMember(string $username): ?array {
  $st = db()->prepare('SELECT * FROM members WHERE username = ?'); $st->execute([$username]);
  $m = $st->fetch(); return $m ?: null;
}
function saveMember(array $m, ?PDO $pdo = null): void {
  $pdo = $pdo ?: db();
  // INSERT OR REPLACE menulis ulang seluruh baris, jadi session_token harus ikut disertakan.
  // Pemanggil biasa (mis. simpan profil / ganti foto) tidak mengirimnya -> pertahankan nilai lama
  // supaya member tidak ikut ter-logout. Kirim 'session_token' => '' untuk sengaja memutus sesi.
  if (array_key_exists('session_token', $m)) {
    $tok = (string)$m['session_token'];
  } else {
    $q = $pdo->prepare('SELECT session_token FROM members WHERE username = ?');
    $q->execute([$m['username']]);
    $tok = (string)($q->fetchColumn() ?: '');
  }
  $lama = null;
  if (!array_key_exists('plan_cap', $m) || !array_key_exists('allow_ids', $m)) {
    $q2 = $pdo->prepare('SELECT plan_cap, allow_ids FROM members WHERE username = ?');
    $q2->execute([$m['username']]);
    $lama = $q2->fetch() ?: null;
  }
  if (array_key_exists('plan_cap', $m)) {
    $cap = $m['plan_cap'] === null ? null : (int)$m['plan_cap'];
  } else {
    $v = $lama ? $lama['plan_cap'] : null;
    $cap = ($v === null) ? null : (int)$v;
  }
  if (array_key_exists('allow_ids', $m)) {
    $ids = is_array($m['allow_ids']) ? json_encode(array_values($m['allow_ids'])) : ($m['allow_ids'] === null ? null : (string)$m['allow_ids']);
  } else {
    $ids = $lama ? $lama['allow_ids'] : null;
  }
  $pdo->prepare('INSERT OR REPLACE INTO members (username, name, code_hash, code_hint, plan, expires, active, created_at, last_login, email, phone, role, avatar, session_token, plan_cap, allow_ids)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    $m['username'], $m['name'], $m['code_hash'], $m['code_hint'], $m['plan'], $m['expires'] ?? '', (int)$m['active'],
    $m['created_at'], $m['last_login'] ?? null, $m['email'] ?? '', $m['phone'] ?? '', $m['role'] ?? 'member', $m['avatar'] ?? '', $tok, $cap, $ids]);
}
/** Token sesi acak. Dipakai saat login, dan saat sengaja memutus semua sesi lama. */
function newSessionToken(): string { return bin2hex(random_bytes(16)); }
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
/**
 * Username member = alamat email lengkap (huruf kecil), supaya pembeli tidak perlu
 * menghafal apa pun selain kode akses. Kalau email kosong atau tidak valid, jatuh
 * kembali ke nama seperti sebelumnya.
 */
function usernameFromEmail(string $email, string $name): string {
  $e = strtolower(trim($email));
  if ($e !== '' && strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL)) {
    if (!findMember($e) && $e !== strtolower(ADMIN_USER)) return $e;
    $parts = explode('@', $e, 2); $i = 1;
    do { $i++; $u = $parts[0] . $i . '@' . $parts[1]; } while (findMember($u) || $u === strtolower(ADMIN_USER));
    return $u;
  }
  $base = preg_replace('/[^a-z0-9._-]/', '', strtolower($name));
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
    'note' => $o['note'], 'createdAt' => $o['created_at'], 'updatedAt' => $o['updated_at'],
    'reminderCount' => (int)($o['reminder_count'] ?? 0), 'remindedAt' => (string)($o['reminded_at'] ?? '')];
}
/** Satu template tingkat voucher untuk panel admin. */
/** Pecah isian kode jadi daftar rapi: huruf besar, tanpa duplikat, tanpa yang kosong. */
function voucherCodeList($raw): array {
  $items = is_array($raw) ? $raw : preg_split('/[\\s,;]+/', (string)$raw);
  $out = [];
  foreach ($items as $c) {
    $c = strtoupper(trim((string)$c));
    if ($c === '') continue;
    if (mb_strlen($c) > 24) $c = mb_substr($c, 0, 24);
    if (!in_array($c, $out, true)) $out[] = $c;
  }
  return $out;
}

/** Tingkat lain yang sudah memakai kode ini, atau 0 kalau bebas. Satu kode hanya boleh di satu tingkat. */
function voucherCodeOwner(string $code, int $selain): int {
  foreach (db()->query('SELECT * FROM vouchers') as $v) {
    if ((int)$v['pct'] === $selain) continue;
    foreach (voucherCodes($v) as $c) if (strcasecmp($c, $code) === 0) return (int)$v['pct'];
  }
  return 0;
}

/** Semua alias satu tingkat. Baris lama hanya punya kolom `code`, jadi itu yang dipakai. */
function voucherCodes(array $v): array {
  $j = json_decode((string)($v['codes'] ?? ''), true);
  if (is_array($j) && $j) return array_values(array_filter(array_map('strval', $j)));
  $satu = (string)($v['code'] ?? '');
  return $satu === '' ? [] : [$satu];
}

/**
 * Tingkat diskon dibaca dari DUA ANGKA TERAKHIR kode: HEMAT30, DISKON30, PROMO30 → 30%.
 * Itu sebabnya satu tingkat boleh punya banyak alias tanpa panel perlu daftar terpisah.
 * Mengembalikan 0 kalau dua karakter terakhir bukan angka atau bukan salah satu tingkat.
 */
function voucherTierFromCode(string $code): int {
  if (!preg_match('/(\d{2})$/', $code, $m)) return 0;
  $p = (int)$m[1];
  return in_array($p, VOUCHER_TIERS, true) ? $p : 0;
}

function publicVoucher(array $v): array {
  $code = (string)($v['code'] ?? '');
  $mayarId = (string)($v['mayar_id'] ?? '');
  return ['pct' => (int)$v['pct'], 'code' => $code, 'codes' => voucherCodes($v), 'note' => (string)($v['note'] ?? ''),
    'active' => (bool)$v['active'] && $code !== '', 'filled' => $code !== '',
    // diMayar = kupon ini benar-benar dibuat lewat API, jadi panel tahu statusnya.
    // Kode yang cuma dicatat manual tetap punya diMayar = false.
    'diMayar' => $mayarId !== '', 'quota' => (int)($v['quota'] ?? 0),
    'expires' => (string)($v['expires'] ?? ''), 'kind' => (string)($v['kind'] ?? ''),
    'syncedAt' => (string)($v['synced_at'] ?? ''),
    'updatedAt' => (string)($v['updated_at'] ?? '')];
}
/* ---------- API Mayar (kupon) ----------
 * Dipakai tab Voucher untuk MEMBUAT kupon di Mayar, bukan sekadar mencatatnya.
 * Potongan harganya tetap dihitung dan ditegakkan Mayar saat checkout — termasuk
 * kuotanya (totalCoupons), karena pembayaran terjadi di domain Mayar dan aplikasi
 * ini baru tahu setelah webhook masuk. Jadi panel adalah antarmukanya, Mayar
 * tetap sumber kebenarannya.
 */
const MAYAR_API = 'https://api.mayar.id/hl/v1';
/** Basis API Mayar. Env RF_MAYAR_BASE HANYA untuk uji lokal (server tiruan);
 *  di server produksi variabel itu tidak ada, jadi selalu jatuh ke MAYAR_API. */
function mayarBase(): string { return getenv('RF_MAYAR_BASE') ?: MAYAR_API; }
function mayarApiKey(): string { return setting('mayar_api_key'); }

/** Panggil Mayar Headless API. Melempar RuntimeException berisi sebab yang aman ditampilkan ke admin. */
function mayarApi(string $method, string $path, ?array $json = null): array {
  $key = mayarApiKey();
  if ($key === '') throw new RuntimeException('API key Mayar belum diisi di tab Voucher.');
  $url = mayarBase() . $path;
  $body = $json === null ? null : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
  if ($body !== null) $headers[] = 'Content-Type: application/json';
  $res = false; $code = 0; $err = '';
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => 25, 'ignore_errors' => true,
      'header' => implode("\r\n", $headers) . "\r\n", 'content' => $body ?? '']]);
    $res = @file_get_contents($url, false, $ctx);
    foreach (($http_response_header ?? []) as $baris) {
      if (preg_match('#^HTTP/\S+\s+(\d+)#', $baris, $m)) $code = (int)$m[1];
    }
  }
  if ($res === false) throw new RuntimeException('Mayar tidak bisa dihubungi' . ($err !== '' ? ': ' . $err : '.'));
  $j = json_decode((string)$res, true);
  if (!is_array($j)) throw new RuntimeException('Jawaban Mayar tidak dikenali (HTTP ' . $code . ').');
  $sc = (int)($j['statusCode'] ?? $code);
  if ($sc < 200 || $sc >= 300) {
    $pesan = trim((string)($j['messages'] ?? $j['message'] ?? ''));
    if ($pesan === '' && ($sc === 401 || $sc === 403)) $pesan = 'API key ditolak — pastikan key-nya bertipe Read & Write.';
    throw new RuntimeException('Mayar menolak: ' . ($pesan !== '' ? $pesan : 'HTTP ' . $sc));
  }
  return $j;
}

/** Ambil objek data pertama dari jawaban Mayar (kadang objek, kadang array berisi satu objek). */
function mayarData(array $j): array {
  $d = $j['data'] ?? [];
  if (is_array($d) && isset($d[0]) && is_array($d[0])) $d = $d[0];
  return is_array($d) ? $d : [];
}

/**
 * Buat kupon persentase di Mayar. $expires format YYYY-MM-DD.
 * Mengembalikan id diskon — wajib disimpan, karena endpoint detail memakai id, bukan kode.
 */
/**
 * Membuat SATU diskon di Mayar yang memuat beberapa kode sekaligus.
 *
 * Bentuk payload mengikuti contoh curl resmi di
 * https://docs.mayar.id/api-reference/discount/create — `discount` sebuah objek,
 * sementara `coupon` dan `products` SEJAJAR dengannya di tingkat atas. Daftar field
 * di halaman yang sama menyebut `discount` "array of object" dan menaruh `coupon`
 * di dalamnya; keduanya bertentangan, dan yang terbukti diterima server sungguhan
 * adalah bentuk contoh curl-nya. Jangan kembalikan ke bentuk bersarang tanpa
 * mengujinya lagi ke API asli — uji tiruan tidak membuktikan apa pun soal ini.
 *
 * `products` sengaja dikirim kosong: respons Mayar membalas `discountProductType: "all"`,
 * artinya kupon berlaku untuk semua produk. Kalau suatu saat diskon hanya boleh untuk
 * satu paket, isi array ini dengan id produknya.
 */
function mayarCreateCoupon(array $codes, int $pct, int $quota, string $expires, bool $onetime): array {
  $tipe = $onetime ? 'onetime' : 'reusable';
  $kupon = [];
  foreach ($codes as $c) $kupon[] = ['code' => $c, 'type' => $tipe];
  $d = mayarData(mayarApi('POST', '/coupon/create', [
    'name' => 'ResepFoto ' . $pct . '% - ' . implode(' / ', $codes),
    'expiredAt' => $expires . 'T23:59:59.000Z',
    'discount' => [
      'discountType' => 'percentage',
      'eligibleCustomerType' => 'all',
      'minimumPurchase' => 0,
      'value' => $pct,
      'totalCoupons' => $quota,
    ],
    'coupon' => $kupon,
    'products' => [],
  ]));
  // Kode yang BENAR-BENAR dibuat dibaca balik dari respons, bukan diasumsikan dari
  // yang dikirim: kalau Mayar hanya menerima kode pertama, panel harus tahu.
  $jadi = [];
  foreach (($d['coupons'] ?? []) as $c) {
    if (isset($c['code']) && $c['code'] !== '') $jadi[] = (string)$c['code'];
  }
  return ['id' => (string)($d['id'] ?? ''), 'codes' => $jadi ?: $codes, 'data' => $d];
}

/** Baca status diskon dari Mayar berdasarkan id yang disimpan saat pembuatan. */
function mayarCouponDetail(string $id): array {
  return mayarData(mayarApi('GET', '/coupon/' . rawurlencode($id)));
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
    if ($plan === 'Premium') { $m['plan'] = 'Premium'; $m['plan_cap'] = null; $m['allow_ids'] = null; }   // naik paket -> batas & daftar beku dilepas
    $m['active'] = 1; $m['expires'] = in_array($m['plan'], PLAN_LIFETIME, true) ? '' : $m['expires'];
    $m['code_hash'] = password_hash($code, PASSWORD_DEFAULT); $m['code_hint'] = substr($code, -4);
    $m['session_token'] = newSessionToken(); // kode baru -> token diganti, semua perangkat lama terputus
    if (!$m['phone'] && $o['phone']) $m['phone'] = $o['phone'];
    saveMember($m);
    $username = $m['username'];
  } else {
    $username = usernameFromEmail((string)$o['email'], (string)$o['name']);
    saveMember(['username' => $username, 'name' => $o['name'] ?: $username, 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
      'code_hint' => substr($code, -4), 'plan' => $plan, 'expires' => '', 'active' => 1, 'created_at' => gmdate('c'),
      'last_login' => null, 'email' => $o['email'], 'phone' => $o['phone'], 'session_token' => newSessionToken(),
      'plan_cap' => $plan === 'Standard' ? STANDARD_CAP : null,
      'allow_ids' => $plan === 'Standard' ? freezeStandardIds(STANDARD_CAP) : null]);
  }
  updateOrder($id, ['state' => 'aktif', 'username' => $username, 'code' => $code]);
  if ($sendEmail) { sendAccessEmail($id); sendAccessWa($id); }
  notifyAdmin($id);
  return findOrder($id);
}

/* ---------- template pesan ke pembeli ----------
 * SATU template per kejadian, ditulis dengan format WhatsApp (*tebal*, _miring_,
 * daftar bernomor). Dari satu sumber itu dihasilkan tiga keluaran:
 *   - WhatsApp                         : apa adanya
 *   - email HTML                       : tandanya jadi <strong>/<em>/<ol>
 *   - email teks & tombol salin panel  : tandanya dibuang
 * Dengan begitu isi ketiganya tidak mungkin berbeda.
 */
const MSG_PAID_SUBJECT = 'Akses ResepFoto kamu sudah aktif';
const MSG_PAID = "Halo *Kak {nama_depan}*! \u{2728}\nSelamat datang di *ResepFoto {paket}*.\nAkses Kakak sudah aktif dan bisa langsung digunakan.\n\n*Detail akun*\n1. Website: {situs}\n2. Username: {username}\n3. Kode akses: *{kode}*\n4. Paket: {paket} \u{2014} akses selamanya\n\n_Mohon simpan informasi akses ini dengan baik ya, Kak._\n\nKalau suka dengan hasilnya, jangan lupa share di sosial media dan tag kami ya, Kak. Dukungan Kakak sangat berarti untuk membantu ResepFoto terus berkembang.\n\nTerima kasih dan selamat berkreasi! \u{1F4F8}\u{2728}";
const MSG_PENDING_SUBJECT = 'Pesanan ResepFoto kamu belum selesai';
const MSG_PENDING = "Halo *Kak {nama_depan}*! \u{2728}\nTerima kasih sudah memilih *ResepFoto {paket}*.\n\nPesanan Kakak _belum selesai_ \u{2014} tinggal satu langkah lagi:\n\n1. Buka halaman pembayaran: {link_bayar}\n2. Pilih metode: Virtual Account, QRIS, atau kartu\n3. Selesaikan pembayaran sebesar *{nominal}*\n\nSetelah pembayaran terkonfirmasi, *akses Kakak langsung dikirim otomatis* ke WhatsApp dan email ini.\n\nKalau ada kendala, balas pesan ini ya, Kak. \u{1F4F8}\u{2728}";

/** Ambil template dari panel admin; kalau kosong pakai bawaan. */
function msgTpl(string $key, string $default): string {
  $v = trim(setting($key));
  return $v !== '' ? $v : $default;
}
/** Link pembayaran dari payload Mayar kalau ada; kalau tidak, halaman promo. */
function orderPayLink(array $o): string {
  $raw = json_decode((string)($o['raw'] ?? ''), true);
  if (is_array($raw)) {
    $d = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;
    foreach (['link', 'paymentUrl', 'payment_url', 'invoiceUrl', 'invoice_url', 'url'] as $k) {
      $v = (string)($d[$k] ?? '');
      if (strpos($v, 'https://') === 0) return $v;
    }
  }
  return siteUrl() . '/promo';
}
/** Nilai pengganti untuk placeholder {..} di template. */
function msgVars(array $o): array {
  $nama = trim((string)($o['name'] ?? ''));
  $first = trim(explode(' ', $nama)[0]);
  $amount = (int)($o['amount'] ?? 0);
  return [
    '{nama}'       => $nama !== '' ? $nama : 'Kak',
    '{nama_depan}' => $first !== '' ? $first : 'Kak',
    '{paket}'      => (string)($o['plan'] ?? ''),
    '{username}'   => (string)($o['username'] ?? ''),
    '{kode}'       => (string)($o['code'] ?? ''),
    '{situs}'      => siteUrl(),
    '{nominal}'    => $amount > 0 ? 'Rp' . number_format($amount, 0, ',', '.') : '',
    '{link_bayar}' => orderPayLink($o),
  ];
}
/** Isi placeholder sekali jalan (strtr, bukan str_replace berantai). */
function renderMsg(string $tpl, array $vars): string { return strtr($tpl, $vars); }
/** Buang tanda format WhatsApp — untuk email versi teks dan tombol salin di panel. */
function waStrip(string $s): string {
  $s = preg_replace('/\*([^\*\n]+)\*/u', '$1', $s);
  $s = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+)_(?![A-Za-z0-9])/u', '$1', $s);
  return preg_replace('/~([^~\n]+)~/u', '$1', $s);
}
/** Ubah format WhatsApp jadi HTML aman: escape dulu, baru tandanya diterjemahkan. */
function waToHtml(string $s): string {
  $inline = function (string $t): string {
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" style="color:#2856D8">$1</a>', $t);
    $t = preg_replace('/\*([^\*\n]+)\*/u', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+)_(?![A-Za-z0-9])/u', '<em>$1</em>', $t);
    return preg_replace('/~([^~\n]+)~/u', '<del>$1</del>', $t);
  };
  // Jenis tiap baris. Satu blok boleh campur: judul lalu daftar, seperti
  // "*Detail akun*" yang langsung diikuti "1. Website: ..." tanpa baris kosong.
  $jenis = function (string $b): string {
    if (preg_match('/^\d+[.)]\s+/', $b)) return 'ol';
    if (preg_match('/^[-\x{2022}]\s+/u', $b)) return 'ul';
    return 'p';
  };
  $out = '';
  foreach (preg_split("/\n[ \t]*\n/", str_replace("\r\n", "\n", $s)) as $blok) {
    $baris = array_values(array_filter(array_map('rtrim', explode("\n", $blok)), function ($x) { return $x !== ''; }));
    if (!$baris) continue;
    $i = 0; $n = count($baris);
    while ($i < $n) {
      $j = $jenis($baris[$i]);
      $grup = [];
      while ($i < $n && $jenis($baris[$i]) === $j) { $grup[] = $baris[$i]; $i++; }
      if ($j === 'p') {
        $out .= '<p style="margin:0 0 14px;color:#44506A;line-height:1.6">' . implode('<br>', array_map($inline, $grup)) . '</p>';
        continue;
      }
      $out .= '<' . $j . ' style="margin:0 0 16px;padding-left:22px;color:#44506A;line-height:1.7">';
      foreach ($grup as $b) {
        $isi = preg_replace($j === 'ol' ? '/^\d+[.)]\s+/' : '/^[-\x{2022}]\s+/u', '', $b);
        $out .= '<li style="margin:0 0 6px">' . $inline($isi) . '</li>';
      }
      $out .= '</' . $j . '>';
    }
  }
  return $out;
}
/** Kerangka email: logo di atas, isi di kartu putih. Gaya inline — syarat Gmail. */
function emailShell(string $preheader, string $isiHtml): string {
  $situs = siteUrl();
  $pre = htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8');
  $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
  return '<!doctype html><html lang="id"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
    . '<title>ResepFoto</title></head>'
    . '<body style="margin:0;padding:0;background:#F5F7FC">'
    . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $pre . '</div>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F5F7FC;padding:28px 12px">'
    . '<tr><td align="center">'
    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%">'
    . '<tr><td align="center" style="padding:0 0 22px">'
    . '<a href="' . $situs . '" style="text-decoration:none"><img src="' . $situs . '/brand/email-logo.png" width="190" alt="ResepFoto" style="display:block;border:0;width:190px;max-width:70%;height:auto"></a>'
    . '</td></tr>'
    . '<tr><td style="background:#FFFFFF;border:1px solid #E4E9F4;border-radius:18px;padding:30px 28px;font-family:' . $font . ';font-size:15px">'
    . $isiHtml
    . '</td></tr>'
    . '<tr><td align="center" style="padding:20px 8px 0;font-family:' . $font . ';font-size:12px;color:#8A93A6;line-height:1.6">'
    . '&copy; ' . date('Y') . ' ResepFoto &middot; <a href="' . $situs . '" style="color:#8A93A6">resepfoto.kitlab.id</a><br>'
    . 'Email ini dikirim otomatis, mohon tidak dibalas.'
    . '</td></tr></table></td></tr></table></body></html>';
}
/** Tombol ajakan untuk email. */
function emailButton(string $url, string $teks): string {
  return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 20px"><tr>'
    . '<td style="background:#2856D8;border-radius:12px"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
    . ' style="display:inline-block;padding:13px 26px;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:15px">'
    . htmlspecialchars($teks, ENT_QUOTES, 'UTF-8') . '</a></td></tr></table>';
}

/* --- tiga keluaran untuk pesanan yang sudah lunas --- */
function accessWa(array $o): string { return renderMsg(msgTpl('msg_paid', MSG_PAID), msgVars($o)); }
function accessMessage(array $o): string { return waStrip(accessWa($o)); }
function accessHtml(array $o): string {
  return emailShell('Akses ResepFoto kamu sudah aktif.',
    waToHtml(accessWa($o)) . emailButton(siteUrl(), 'Buka ResepFoto'));
}
function accessSubject(array $o): string { return renderMsg(msgTpl('msg_paid_subject', MSG_PAID_SUBJECT), msgVars($o)); }

/* --- tiga keluaran untuk pengingat sebelum bayar --- */
function pendingWa(array $o): string { return renderMsg(msgTpl('msg_pending', MSG_PENDING), msgVars($o)); }
function pendingMessage(array $o): string { return waStrip(pendingWa($o)); }
function pendingHtml(array $o): string {
  return emailShell('Pesanan ResepFoto kamu belum selesai.',
    waToHtml(pendingWa($o)) . emailButton(orderPayLink($o), 'Lanjutkan pembayaran'));
}
function pendingSubject(array $o): string { return renderMsg(msgTpl('msg_pending_subject', MSG_PENDING_SUBJECT), msgVars($o)); }
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
function mailHeaders(?string $from = null, string $boundary = ''): string {
  $from = $from ?: mailFrom();
  $domain = ltrim((string)strrchr($from, '@'), '@') ?: 'kitlab.id';
  // Dengan boundary -> multipart/alternative (teks + HTML). Tanpa boundary -> teks polos
  // seperti sebelumnya. MAIL FROM / -f sengaja TIDAK disentuh: itu yang membuat SPF lolos.
  $ctype = $boundary !== ''
    ? "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n"
    : "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
  return "From: ResepFoto <$from>\r\n"
    . "Reply-To: $from\r\n"
    . 'Date: ' . date('r') . "\r\n"
    . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
    . "MIME-Version: 1.0\r\n"
    . $ctype
    . "Auto-Submitted: auto-generated\r\n"
    . 'X-Mailer: ResepFoto';
}
/** Rakit isi pesan. Ada HTML -> dua bagian; tanpa HTML -> base64 teks seperti dulu. */
function mimeBody(string $text, ?string $html, string $boundary): string {
  if ($html === null || $boundary === '') return chunk_split(base64_encode($text));
  return "--$boundary\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($text))
    . "\r\n--$boundary\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($html))
    . "\r\n--$boundary--\r\n";
}
/** Boundary acak untuk satu pesan. */
function mimeBoundary(): string { return 'rf' . bin2hex(random_bytes(10)); }
/**
 * Kirim email. Pakai SMTP kalau smtp_host diisi di panel admin; kalau tidak,
 * mail() dengan envelope sender (-f) supaya Return-Path sejajar dengan From —
 * itu syarat SPF/DMARC lolos dan email tidak dianggap spam.
 * Melempar RuntimeException berisi sebab yang aman ditampilkan ke admin.
 */
function sendMailOrFail(string $to, string $subject, string $body, ?string $html = null): void {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Alamat tujuan tidak valid.');
  $from = mailFrom();
  if (setting('smtp_host') !== '') { smtpSend($to, $subject, $body, $from, $html); return; }
  $boundary = $html !== null ? mimeBoundary() : '';
  $headers = mailHeaders($from, $boundary);
  $enc = mimeBody($body, $html, $boundary);
  $subj = mailSubject($subject);
  if (@mail($to, $subj, $enc, $headers, '-f' . $from)) return;
  if (@mail($to, $subj, $enc, $headers)) return;          // sebagian host melarang parameter -f
  throw new RuntimeException('Server menolak mengirim email (fungsi mail() gagal).');
}
function sendMail(string $to, string $subject, string $body, ?string $html = null): bool {
  try { sendMailOrFail($to, $subject, $body, $html); return true; } catch (Throwable $e) { return false; }
}
/** Klien SMTP minimal: AUTH LOGIN, STARTTLS (587) atau SSL langsung (465). */
function smtpSend(string $to, string $subject, string $body, string $from, ?string $html = null): void {
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
  $boundary = $html !== null ? mimeBoundary() : '';
  $msg = mailHeaders($from, $boundary) . "\r\nTo: <$to>\r\nSubject: " . mailSubject($subject) . "\r\n\r\n" . mimeBody($body, $html, $boundary);
  fwrite($fp, preg_replace('/^\./m', '..', $msg) . "\r\n.\r\n");
  $cmd('', '250', 'kirim');
  @fwrite($fp, "QUIT\r\n");
  @fclose($fp);
}
function sendAccessEmail(string $id): bool {
  $o = findOrder($id);
  if (!$o || !$o['email'] || !filter_var($o['email'], FILTER_VALIDATE_EMAIL) || !$o['code']) return false;
  $ok = sendMail((string)$o['email'], accessSubject($o), accessMessage($o), accessHtml($o));
  updateOrder($id, ['emailed' => $ok ? 1 : 0, 'note' => $ok ? 'Email akses terkirim.' : 'Email gagal dikirim server. Kirim manual via WA.']);
  return $ok;
}

/* ---------- pengingat sebelum bayar ----------
 * Sengaja TIDAK memakai ulang sendAccessEmail/sendAccessWa: keduanya mensyaratkan
 * kode akses, yang justru belum ada pada pesanan yang belum lunas.
 */
function sendPendingEmail(string $id): bool {
  $o = findOrder($id);
  if (!$o || !$o['email'] || !filter_var($o['email'], FILTER_VALIDATE_EMAIL)) return false;
  return sendMail((string)$o['email'], pendingSubject($o), pendingMessage($o), pendingHtml($o));
}
function sendPendingWa(string $id): bool {
  $o = findOrder($id);
  if (!$o || !$o['phone'] || setting('fonnte_token') === '') return false;
  return waSend((string)$o['phone'], pendingWa($o));
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
  $ok = waSend((string)$o['phone'], accessWa($o));
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
