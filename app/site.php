<?php
/**
 * Etalase di luar ResepFoto (Indonesia), dilayani dari folder, kode, DAN database yang sama.
 *   imagine.kitlab.id      → etalase internasional "Imagine", English saja.
 *   imagine.kitlab.id/th/  → etalase Thailand: bahasa Thai bawaan + English (site.php?s=th).
 * .htaccess mengarahkan halaman utama host itu ke sini. Isinya tetap index.html; yang diganti hanya atribut <html>
 * (bahasa + data-site) dan blok meta <head> di antara penanda <!--site:head-->, supaya judul tab dan link preview
 * sudah benar sebelum JavaScript jalan. Akun, resep, dan pembelian berlaku di semua etalase.
 */
declare(strict_types=1);

const SITES = [
  'imagine' => [
    'brand' => 'Imagine', 'langs' => 'en', 'locale' => 'en_US', 'host' => 'imagine.kitlab.id', 'path' => '/',
    'title' => 'Imagine · AI Photo Recipes',
    'desc' => 'Imagine Your Photo. Copy-ready AI photo prompt recipes for Gemini & ChatGPT: retro, cinematic, professional portraits and viral trends.',
    'ogTitle' => 'Imagine · Turn ordinary photos into extraordinary ones',
    'ogDesc' => 'Just copy the recipe. Photo prompts ready for Gemini & ChatGPT.',
  ],
  // Sub-etalase: dipilih lewat ?s=th pada host imagine (lihat .htaccess), bukan lewat nama host.
  'imagine-th' => [
    'brand' => 'Imagine', 'langs' => 'th,en', 'locale' => 'th_TH', 'host' => 'imagine.kitlab.id', 'path' => '/th/', 'parent' => 'imagine', 'sub' => 'th',
    'title' => 'Imagine · สูตรภาพถ่าย AI',
    'desc' => 'Imagine Your Photo สูตรพรอมต์ภาพถ่าย AI พร้อมคัดลอกไปใช้กับ Gemini และ ChatGPT ได้ทันที ทั้งลุควินเทจ โทนภาพยนตร์ รูปโปรไฟล์มืออาชีพ และเทรนด์ไวรัล',
    'ogTitle' => 'Imagine · เปลี่ยนรูปธรรมดาให้ไม่ธรรมดา',
    'ogDesc' => 'แค่คัดลอกสูตรไปใช้ พรอมต์พร้อมใช้กับ Gemini และ ChatGPT',
    // Huruf Thai; huruf Latin tetap Inter (lihat html[lang="th"] di index.html).
    'font' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400..800&display=swap',
  ],
];

function siteKey(): string {
  $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
  foreach (SITES as $k => $s) {
    if (isset($s['parent']) || !($host === $s['host'] || strpos($host, $k . '.') === 0)) continue;
    $sub = (string)($_GET['s'] ?? '');
    foreach (SITES as $k2 => $s2) if (($s2['parent'] ?? '') === $k && ($s2['sub'] ?? '') === $sub) return $k2;
    return $k;
  }
  return '';
}

$key = siteKey();
$html = (string)file_get_contents(__DIR__ . '/index.html');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
if ($key === '') { header('Content-Type: text/html; charset=utf-8'); echo $html; exit; }   // host lain: apa adanya
$s = SITES[$key];
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$lang = explode(',', $s['langs'])[0];

if (($_GET['f'] ?? '') === 'manifest') {
  header('Content-Type: application/manifest+json; charset=utf-8');
  echo json_encode(['name' => $s['brand'], 'short_name' => $s['brand'], 'description' => $s['desc'], 'lang' => $lang,
    'start_url' => $s['path'], 'scope' => $s['path'],
    'display' => 'standalone', 'background_color' => '#F5F7FC', 'theme_color' => '#F5F7FC',
    'icons' => [['src' => '/brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
      ['src' => '/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

$url = 'https://' . $s['host'] . $s['path'];
// Versi bahasa lain dari etalase yang sama (hreflang), supaya mesin pencari menampilkan /th ke pengguna Thailand.
$alt = '';
foreach (SITES as $s2) if ($s2['host'] === $s['host']) {
  $alt .= '<link rel="alternate" hreflang="' . $e(explode(',', $s2['langs'])[0]) . '" href="' . $e('https://' . $s2['host'] . $s2['path']) . '">' . "\n";
  if ($s2['path'] === '/') $alt .= '<link rel="alternate" hreflang="x-default" href="' . $e('https://' . $s2['host'] . '/') . '">' . "\n";
}
$head = '<meta name="description" content="' . $e($s['desc']) . '">' . "\n"
  . '<meta name="theme-color" content="#F5F7FC">' . "\n"
  . '<title>' . $e($s['title']) . '</title>' . "\n"
  . '<link rel="canonical" href="' . $e($url) . '">' . "\n"
  . $alt
  . '<meta property="og:type" content="website">' . "\n"
  . '<meta property="og:site_name" content="' . $e($s['brand']) . '">' . "\n"
  . '<meta property="og:url" content="' . $e($url) . '">' . "\n"
  . '<meta property="og:title" content="' . $e($s['ogTitle']) . '">' . "\n"
  . '<meta property="og:description" content="' . $e($s['ogDesc']) . '">' . "\n"
  . '<meta property="og:locale" content="' . $e($s['locale']) . '">' . "\n"
  . '<meta name="twitter:card" content="summary">' . "\n"
  . '<meta name="twitter:title" content="' . $e($s['ogTitle']) . '">' . "\n"
  . '<meta name="twitter:description" content="' . $e($s['ogDesc']) . '">' . "\n"
  . '<link rel="icon" href="/favicon.ico" sizes="48x48">' . "\n"
  . '<link rel="icon" href="/favicon.svg" type="image/svg+xml">' . "\n"
  . '<link rel="apple-touch-icon" href="/apple-touch-icon.png">' . "\n"
  . '<link rel="manifest" href="' . $e($s['path'] . 'site.webmanifest') . '">' . "\n"
  . '<meta name="apple-mobile-web-app-title" content="' . $e($s['brand']) . '">' . "\n"
  . (isset($s['font']) ? '<link rel="stylesheet" href="' . $e($s['font']) . '">' . "\n" : '');
$html = preg_replace('#<!--site:head-->.*?<!--/site:head-->#s', '<!--site:head-->' . "\n" . str_replace('$', '\\$', $head) . '<!--/site:head-->', $html, 1);
$html = preg_replace('#<html lang="[a-z]+">#', '<html lang="' . $e($lang) . '" data-site="' . $e($key) . '" data-langs="' . $e($s['langs'])
  . '" data-brand="' . $e($s['brand']) . '" data-title="' . $e($s['title']) . '">', $html, 1);
header('Content-Type: text/html; charset=utf-8');
echo $html;
