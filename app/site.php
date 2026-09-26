<?php
/**
 * Etalase di luar ResepFoto (Indonesia), dilayani dari folder, kode, DAN database yang sama.
 *   imagine.kitlab.id  → etalase internasional "Imagine", English saja.
 * .htaccess mengarahkan halaman utama host itu ke sini. Isinya tetap index.html; yang diganti hanya atribut <html>
 * (bahasa + data-site) dan blok meta <head> di antara penanda <!--site:head-->, supaya judul tab dan link preview
 * sudah benar sebelum JavaScript jalan. Akun, resep, dan pembelian berlaku di semua etalase.
 */
declare(strict_types=1);

const SITES = [
  'imagine' => [
    'brand' => 'Imagine', 'langs' => 'en', 'locale' => 'en_US', 'host' => 'imagine.kitlab.id',
    'title' => 'Imagine · AI Photo Recipes',
    'desc' => 'Imagine Your Photo. Copy-ready AI photo prompt recipes for Gemini & ChatGPT: retro, cinematic, professional portraits and viral trends.',
    'ogTitle' => 'Imagine · Turn ordinary photos into extraordinary ones',
    'ogDesc' => 'Just copy the recipe. Photo prompts ready for Gemini & ChatGPT.',
  ],
];

function siteKey(): string {
  $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
  foreach (SITES as $k => $s) if ($host === $s['host'] || strpos($host, $k . '.') === 0) return $k;
  return '';
}

$key = siteKey();
$html = (string)file_get_contents(__DIR__ . '/index.html');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
if ($key === '') { header('Content-Type: text/html; charset=utf-8'); echo $html; exit; }   // host lain: apa adanya
$s = SITES[$key];
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if (($_GET['f'] ?? '') === 'manifest') {
  header('Content-Type: application/manifest+json; charset=utf-8');
  echo json_encode(['name' => $s['brand'], 'short_name' => $s['brand'], 'description' => $s['desc'], 'start_url' => '/', 'scope' => '/',
    'display' => 'standalone', 'background_color' => '#F5F7FC', 'theme_color' => '#F5F7FC',
    'icons' => [['src' => '/brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
      ['src' => '/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

$url = 'https://' . $s['host'] . '/';
$head = '<meta name="description" content="' . $e($s['desc']) . '">' . "\n"
  . '<meta name="theme-color" content="#F5F7FC">' . "\n"
  . '<title>' . $e($s['title']) . '</title>' . "\n"
  . '<link rel="canonical" href="' . $e($url) . '">' . "\n"
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
  . '<link rel="manifest" href="/site.webmanifest">' . "\n"
  . '<meta name="apple-mobile-web-app-title" content="' . $e($s['brand']) . '">' . "\n";
$html = preg_replace('#<!--site:head-->.*?<!--/site:head-->#s', '<!--site:head-->' . "\n" . str_replace('$', '\\$', $head) . '<!--/site:head-->', $html, 1);
$html = preg_replace('#<html lang="[a-z]+">#', '<html lang="' . explode(',', $s['langs'])[0] . '" data-site="' . $e($key) . '" data-langs="' . $e($s['langs'])
  . '" data-brand="' . $e($s['brand']) . '" data-title="' . $e($s['title']) . '">', $html, 1);
header('Content-Type: text/html; charset=utf-8');
echo $html;
