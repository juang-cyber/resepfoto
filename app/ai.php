<?php
/**
 * ResepFoto — integrasi Gemini API (dipakai oleh api.php, tidak bisa diakses langsung).
 */
declare(strict_types=1);
if (!defined('RF_API')) { http_response_code(403); exit; }

/** Error yang pesannya aman ditampilkan ke admin. */
class RfError extends Exception {}

const AI_DEFAULT_MODEL = 'gemini-3.8-flash';
const AI_DEFAULT_IMAGE_MODEL = 'gemini-3.1-flash-image';
const DS_DEFAULT_MODEL = 'deepseek-flash';

function aiKey(): string { return setting('gemini_api_key'); }
function aiModel(): string { $m = setting('gemini_model'); return $m !== '' ? $m : AI_DEFAULT_MODEL; }
function aiImageModel(): string { $m = setting('gemini_image_model'); return $m !== '' ? $m : AI_DEFAULT_IMAGE_MODEL; }
function dsKey(): string { return setting('deepseek_api_key'); }
function dsModel(): string { $m = setting('deepseek_model'); return $m !== '' ? $m : DS_DEFAULT_MODEL; }
/** Mesin utama untuk membaca referensi: 'deepseek' (bawaan) atau 'gemini'. Yang satunya jadi cadangan. */
function refEngine(): string { return setting('ai_ref_engine') === 'gemini' ? 'gemini' : 'deepseek'; }

function aiLog(string $action, string $model, bool $ok, array $usage, int $ms, string $note = ''): void {
  db()->prepare('INSERT INTO ai_log (ts, action, model, ok, tokens_in, tokens_out, ms, note) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([gmdate('c'), $action, $model, $ok ? 1 : 0, (int)($usage['promptTokenCount'] ?? 0),
      (int)($usage['candidatesTokenCount'] ?? 0) + (int)($usage['thoughtsTokenCount'] ?? 0), $ms, mb_substr($note, 0, 300)]);
  db()->exec('DELETE FROM ai_log WHERE id NOT IN (SELECT id FROM ai_log ORDER BY id DESC LIMIT 300)');
}

/** HTTP helper (curl kalau ada, fallback stream). */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 90, int $maxBytes = 15000000): array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $h,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ResepFotoBot/1.0; +https://resepfoto.kitlab.id)',
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RfError('Koneksi gagal: ' . $err);
    if (strlen($res) > $maxBytes) throw new RfError('File terlalu besar.');
    return ['code' => $code, 'body' => $res, 'type' => $type, 'url' => $final];
  }
  $hdr = '';
  foreach ($headers as $k => $v) $hdr .= "$k: $v\r\n";
  if (!isset($headers['User-Agent'])) $hdr .= "User-Agent: Mozilla/5.0 (compatible; ResepFotoBot/1.0)\r\n";
  $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $hdr,
    'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5]]);
  $res = @file_get_contents($url, false, $ctx, 0, $maxBytes);
  if ($res === false) throw new RfError('Koneksi gagal.');
  $code = 0; $type = '';
  foreach (($http_response_header ?? []) as $line) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $code = (int)$m[1];
    if (stripos($line, 'content-type:') === 0) $type = trim(substr($line, 13));
  }
  return ['code' => $code, 'body' => $res, 'type' => $type, 'url' => $url];
}

/** Panggil generateContent. $parts = daftar part Gemini. */
function geminiCall(string $action, array $parts, array $opts = []): array {
  $key = aiKey();
  if ($key === '') throw new RfError('API key Gemini belum diisi. Buka Admin → AI.');
  $model = $opts['model'] ?? aiModel();
  $payload = ['contents' => [['role' => 'user', 'parts' => $parts]]];
  $gen = $opts['generationConfig'] ?? [];
  if (!empty($opts['json'])) $gen['responseMimeType'] = 'application/json';
  if ($gen) $payload['generationConfig'] = $gen;
  if (!empty($opts['tools'])) $payload['tools'] = $opts['tools'];
  if (!empty($opts['system'])) $payload['systemInstruction'] = ['parts' => [['text' => $opts['system']]]];
  $t0 = microtime(true);
  $r = httpRequest('POST', (getenv('RF_GEMINI_BASE') ?: 'https://generativelanguage.googleapis.com') . '/v1beta/models/' . rawurlencode($model) . ':generateContent',
    ['Content-Type' => 'application/json', 'x-goog-api-key' => $key], json_encode($payload), (int)($opts['timeout'] ?? 90));
  $ms = (int)round((microtime(true) - $t0) * 1000);
  $j = json_decode($r['body'], true);
  if ($r['code'] !== 200 || !is_array($j)) {
    $msg = is_array($j) ? (string)($j['error']['message'] ?? 'HTTP ' . $r['code']) : 'HTTP ' . $r['code'];
    aiLog($action, $model, false, [], $ms, $msg);
    if ($r['code'] === 400 && stripos($msg, 'API key') !== false) throw new RfError('API key Gemini tidak valid.');
    if ($r['code'] === 404) throw new RfError("Model \"$model\" tidak ditemukan. Cek nama model di Admin → AI.");
    if ($r['code'] === 429) throw new RfError('Kuota Gemini habis atau terlalu banyak permintaan. Coba lagi sebentar.');
    throw new RfError('Gemini error: ' . mb_substr($msg, 0, 200));
  }
  $text = ''; $images = [];
  foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
    if (!empty($p['thought'])) continue;
    if (isset($p['text'])) $text .= $p['text'];
    $inl = $p['inlineData'] ?? $p['inline_data'] ?? null;
    if ($inl && !empty($inl['data'])) $images[] = ['mime' => $inl['mimeType'] ?? $inl['mime_type'] ?? 'image/png', 'data' => $inl['data']];
  }
  $finish = (string)($j['candidates'][0]['finishReason'] ?? '');
  aiLog($action, $model, true, $j['usageMetadata'] ?? [], $ms, $finish);
  if ($text === '' && !$images) {
    $block = (string)($j['promptFeedback']['blockReason'] ?? $finish);
    throw new RfError('Gemini tidak memberi jawaban' . ($block ? " ($block)" : '') . '.');
  }
  return ['text' => $text, 'images' => $images, 'ms' => $ms, 'model' => $model];
}

/**
 * Panggil DeepSeek chat/completions (format OpenAI). $content = daftar part
 * ['type' => 'text', 'text' => …] atau ['type' => 'image_url', 'image_url' => ['url' => data URI]].
 * Model bawaan deepseek-flash bisa membaca gambar; thinking dimatikan supaya token tidak habis untuk berpikir.
 */
function deepseekCall(string $action, array $content, array $opts = []): array {
  $key = dsKey();
  if ($key === '') throw new RfError('API key DeepSeek belum diisi. Buka Admin → AI.');
  $model = $opts['model'] ?? dsModel();
  $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $content]],
    'temperature' => 0.1, 'max_tokens' => (int)($opts['maxTokens'] ?? 4000), 'thinking' => ['type' => 'disabled']];
  if (!empty($opts['json'])) $payload['response_format'] = ['type' => 'json_object'];
  $t0 = microtime(true);
  $r = httpRequest('POST', (getenv('RF_DEEPSEEK_BASE') ?: 'https://api.deepseek.com') . '/chat/completions',
    ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key], json_encode($payload), (int)($opts['timeout'] ?? 120));
  $ms = (int)round((microtime(true) - $t0) * 1000);
  $j = json_decode($r['body'], true);
  if ($r['code'] !== 200 || !is_array($j)) {
    $msg = is_array($j) ? (string)($j['error']['message'] ?? 'HTTP ' . $r['code']) : 'HTTP ' . $r['code'];
    aiLog($action, $model, false, [], $ms, 'DeepSeek: ' . $msg);
    if ($r['code'] === 401) throw new RfError('API key DeepSeek tidak valid.');
    if ($r['code'] === 402) throw new RfError('Saldo DeepSeek habis. Isi ulang di platform.deepseek.com.');
    if ($r['code'] === 429) throw new RfError('DeepSeek sedang sibuk atau batas permintaan tercapai. Coba lagi sebentar.');
    if ($r['code'] === 404 || preg_match('/model.{0,20}(not|exist)/i', $msg)) throw new RfError("Model DeepSeek \"$model\" tidak dikenali. Cek nama model di Admin → AI.");
    throw new RfError('DeepSeek error: ' . mb_substr($msg, 0, 200));
  }
  $text = (string)($j['choices'][0]['message']['content'] ?? '');
  $u = $j['usage'] ?? [];
  aiLog($action, $model, true, ['promptTokenCount' => $u['prompt_tokens'] ?? 0, 'candidatesTokenCount' => $u['completion_tokens'] ?? 0],
    $ms, 'DeepSeek ' . (string)($j['choices'][0]['finish_reason'] ?? ''));
  if (trim($text) === '') throw new RfError('DeepSeek tidak memberi jawaban.');
  return ['text' => $text, 'ms' => $ms, 'model' => $model];
}

function dataUri(string $path): string {
  $info = @getimagesize($path);
  return 'data:' . ($info['mime'] ?? 'image/jpeg') . ';base64,' . base64_encode((string)file_get_contents($path));
}

/**
 * Minta JSON dari AI berdasarkan instruksi + gambar berlabel ($images = [['label' => 'SLIDE 1', 'path' => …]]).
 * Mesin utama mengikuti refEngine(); kalau gagal (atau jawabannya bukan JSON) langsung dicoba mesin satunya,
 * asal key-nya terisi. 'fallback' berisi alasan mesin utama gagal supaya admin tahu.
 */
function aiVisionJson(string $action, string $instr, array $images, array $opts = []): array {
  $order = refEngine() === 'gemini' ? ['gemini', 'deepseek'] : ['deepseek', 'gemini'];
  $order = array_values(array_filter($order, fn($e) => ($e === 'gemini' ? aiKey() : dsKey()) !== ''));
  if (!$order) throw new RfError('Isi dulu API key DeepSeek atau Gemini di Admin → AI.');
  $errors = [];
  foreach ($order as $engine) {
    $name = $engine === 'deepseek' ? 'DeepSeek' : 'Gemini';
    try {
      if ($engine === 'deepseek') {
        $content = [['type' => 'text', 'text' => $instr]];
        foreach ($images as $im) {
          $content[] = ['type' => 'text', 'text' => $im['label'] . ':'];
          $content[] = ['type' => 'image_url', 'image_url' => ['url' => dataUri($im['path'])]];
        }
        $res = deepseekCall($action, $content, ['json' => true, 'timeout' => 150, 'maxTokens' => (int)($opts['maxTokens'] ?? 4000)]);
      } else {
        $parts = [['text' => $instr]];
        foreach ($images as $im) { $parts[] = ['text' => $im['label'] . ':']; $parts[] = imagePart($im['path']); }
        $res = geminiCall($action, $parts, ['json' => true, 'timeout' => 150]);
      }
      return ['json' => aiJson($res['text']), 'engine' => $name, 'model' => $res['model'], 'fallback' => implode(' · ', $errors)];
    } catch (RfError $e) {
      $errors[] = "$name: " . $e->getMessage();
    }
  }
  throw new RfError(implode(' · ', $errors));
}

/** Ambil objek JSON dari teks jawaban. */
function aiJson(string $text): array {
  $t = trim($text);
  $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
  $j = json_decode($t, true);
  if (!is_array($j) && preg_match('/\{.*\}/s', $t, $m)) $j = json_decode($m[0], true);
  if (!is_array($j)) throw new RfError('Jawaban AI tidak bisa dibaca. Coba lagi.');
  return $j;
}

function imagePart(string $path): array {
  $info = @getimagesize($path);
  $mime = $info['mime'] ?? 'image/jpeg';
  return ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode((string)file_get_contents($path))]];
}

function existingCategories(): array {
  $rows = db()->query("SELECT cat, MAX(cat_en) AS cat_en, COUNT(*) AS n FROM prompts GROUP BY cat ORDER BY n DESC")->fetchAll();
  return array_map(fn($r) => ['cat' => $r['cat'], 'catEn' => (string)$r['cat_en'], 'count' => (int)$r['n']], $rows);
}

function recipeInstructions(): string {
  $cats = existingCategories();
  $list = $cats ? implode("\n", array_map(fn($c) => '- ' . $c['cat'] . ($c['catEn'] ? ' (EN: ' . $c['catEn'] . ')' : ''), $cats)) : '(belum ada)';
  return <<<TXT
Kamu editor katalog ResepFoto: web berisi "resep" prompt foto AI (semua tentang foto ORANG) yang disalin pengguna ke Gemini atau ChatGPT bersama foto mereka sendiri.
Tugasmu: dari prompt (dan gambar contoh hasil bila ada), buat metadata katalog dalam JSON.

Kategori yang SUDAH ADA (utamakan salah satu ini, tulis persis sama):
$list

Aturan:
- "cat": pilih kategori yang sudah ada bila cocok. Hanya buat kategori baru bila benar-benar tidak ada yang cocok; isi "catIsNew": true.
- "title": judul Indonesia singkat & menarik, maks 40 karakter, tanpa emoji. "titleEn": versi Inggris.
- "desc": 1 kalimat manfaat untuk pembeli (Bahasa Indonesia santai), maks 100 karakter. "descEn": versi Inggris.
- "tips": 1–2 kalimat saran agar hasil maksimal (Indonesia), boleh menyebut potongan teks prompt dalam tanda kutip. "tipsEn": versi Inggris.
- "catEn": nama kategori dalam Bahasa Inggris.
- "prompt": prompt final dalam Bahasa Inggris, siap disalin. Pertahankan isi aslinya; rapikan saja. Pastikan ada kalimat untuk menjaga wajah & identitas sama persis dengan foto yang diupload. Jangan menyebut nama orang terkenal atau merek.
- "tool": "Gemini", "ChatGPT", atau "" bila tidak diketahui.
- "confidence": 0–1 seberapa yakin kategorinya tepat.
- "notes": catatan singkat untuk admin (Indonesia), misalnya kalau prompt kurang cocok untuk foto orang.
Balas HANYA JSON dengan kunci: title, titleEn, cat, catEn, catIsNew, desc, descEn, tips, tipsEn, prompt, tool, confidence, notes.
TXT;
}

function normalizeRecipe(array $j, string $fallbackPrompt = ''): array {
  $s = fn($k, $max) => mb_substr(trim((string)($j[$k] ?? '')), 0, $max);
  $cats = array_column(existingCategories(), 'cat');
  $cat = $s('cat', 40);
  foreach ($cats as $c) if (mb_strtolower($c) === mb_strtolower($cat)) $cat = $c;
  $tool = $s('tool', 20);
  $tool = stripos($tool, 'gpt') !== false ? 'ChatGPT' : (stripos($tool, 'gemini') !== false ? 'Gemini' : '');
  return [
    'title' => $s('title', 60), 'titleEn' => $s('titleEn', 60),
    'cat' => $cat, 'catEn' => $s('catEn', 40), 'catIsNew' => $cat !== '' && !in_array($cat, $cats, true),
    'desc' => $s('desc', 110), 'descEn' => $s('descEn', 110),
    'tips' => $s('tips', 400), 'tipsEn' => $s('tipsEn', 400),
    'prompt' => $s('prompt', 6000) ?: $fallbackPrompt, 'tool' => $tool,
    'confidence' => max(0, min(1, (float)($j['confidence'] ?? 0))), 'notes' => $s('notes', 300),
  ];
}

/** Simpan gambar sementara (untuk mode link / tempel) ke uploads/tmp_*.jpg. */
function saveTempImage(string $bytes): ?string {
  $im = @imagecreatefromstring($bytes);
  if (!$im) return null;
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  foreach (glob($dir . '/tmp_*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
  $name = 'tmp_' . bin2hex(random_bytes(8)) . '.jpg';
  $w = imagesx($im); $h = imagesy($im);
  if ($w < 256 || $h < 256) { imagedestroy($im); return null; } // ikon/avatar
  imagejpeg($im, "$dir/$name", 88);
  imagedestroy($im);
  return "uploads/$name";
}

/* ---------- Referensi Instagram: link postingan → caption + slide → resep ---------- */

/** Kode postingan dari link Instagram (/p/, /reel/, /tv/, boleh diawali nama akun, boleh ada ?utm…). */
function instagramCode(string $url): string {
  if (!preg_match('#^https?://(?:www\.|m\.)?instagram\.com/(?:[A-Za-z0-9_.]+/)?(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,40})#i', trim($url), $m)) {
    throw new RfError('Link harus link postingan Instagram, contoh: https://www.instagram.com/p/XXXX/');
  }
  return $m[1];
}

/** Cari objek media (punya "code" = $code dan data gambar) di dalam JSON halaman, rekursif. */
function igFindMedia($node, string $code, int $depth = 0): ?array {
  if (!is_array($node) || $depth > 40) return null;
  if (($node['code'] ?? null) === $code && (isset($node['carousel_media']) || isset($node['image_versions2']))) return $node;
  foreach ($node as $v) if (is_array($v) && ($hit = igFindMedia($v, $code, $depth + 1))) return $hit;
  return null;
}

/** URL gambar terbesar dari satu item media Instagram. */
function igImageUrl(array $item): string {
  $best = ''; $bestW = -1;
  foreach (($item['image_versions2']['candidates'] ?? []) as $i => $c) {
    $w = (int)($c['width'] ?? 0);
    if (!empty($c['url']) && ($w > $bestW || ($bestW <= 0 && $i === 0))) { $best = (string)$c['url']; $bestW = $w; }
  }
  return $best !== '' ? $best : (string)($item['display_uri'] ?? $item['display_url'] ?? '');
}

/** Ambil caption, akun, dan gambar dari HTML halaman postingan. null kalau yang datang halaman login. */
function instagramParse(string $html, string $code): ?array {
  $media = null;
  if (preg_match_all('#<script type="application/json"[^>]*>(.*?)</script>#s', $html, $m)) {
    foreach ($m[1] as $js) {
      if (strpos($js, '"' . $code . '"') === false) continue;
      if (($media = igFindMedia(json_decode($js, true), $code))) break;
    }
  }
  if ($media) {
    $items = !empty($media['carousel_media']) ? $media['carousel_media'] : [$media];
    $images = [];
    foreach ($items as $it) if (is_array($it) && ($u = igImageUrl($it)) !== '') $images[] = $u;
    return ['code' => $code, 'caption' => (string)($media['caption']['text'] ?? ''), 'author' => (string)($media['user']['username'] ?? ''),
      'images' => $images, 'commentCount' => (int)($media['comment_count'] ?? 0), 'via' => 'data'];
  }
  // Cadangan: tag og:* (caption bisa terpotong, gambar hanya slide pertama).
  $meta = function (string $prop) use ($html): string {
    return preg_match('#<meta property="og:' . $prop . '" content="([^"]*)"#', $html, $mm) ? html_entity_decode($mm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
  };
  $desc = $meta('description'); $img = $meta('image');
  if ($desc === '' && $img === '') return null;
  $author = ''; $caption = $desc;
  if (preg_match('/^.*?-\s*([A-Za-z0-9_.]+) on [^:]{3,40}:\s*"(.*)"\.?\s*$/su', $desc, $mm)) { $author = $mm[1]; $caption = $mm[2]; }
  return ['code' => $code, 'caption' => $caption, 'author' => $author, 'images' => $img !== '' ? [$img] : [], 'commentCount' => 0, 'via' => 'meta'];
}

/**
 * Baca postingan Instagram publik tanpa login. Browser biasa dilempar ke halaman login, tapi Instagram
 * menyajikan data postingan lengkap (caption + semua slide carousel) ke crawler mesin pencari / link preview,
 * jadi server meminta seperti crawler. Komentar TIDAK ikut (butuh login) — admin menempelnya manual.
 * Cara ini tidak resmi: kalau Instagram mengubahnya, jalur cadangannya mode "Prompt dari gambar".
 */
function instagramPost(string $url): array {
  $code = instagramCode($url);
  $page = (getenv('RF_IG_BASE') ?: 'https://www.instagram.com') . '/p/' . $code . '/';
  $agents = ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'];
  $fallback = null; $status = 0;
  foreach ($agents as $ua) {
    try { $r = httpRequest('GET', $page, ['User-Agent' => $ua, 'Accept' => 'text/html', 'Accept-Language' => 'en-US,en;q=0.8'], null, 25, 8000000); }
    catch (RfError $e) { continue; }
    $status = $r['code'];
    if ($r['code'] !== 200) continue;
    $post = instagramParse($r['body'], $code);
    if ($post && $post['via'] === 'data') return $post;
    if ($post && !$fallback) $fallback = $post;
  }
  if ($fallback) return $fallback;
  if ($status === 404) throw new RfError('Postingan tidak ditemukan. Cek lagi link-nya, mungkin sudah dihapus.');
  throw new RfError('Instagram tidak mengizinkan server membaca postingan ini (diminta login, atau akunnya privat). '
    . 'Pakai mode "Prompt dari gambar" dengan screenshot slide-nya.');
}

/** Unduh gambar dari CDN Instagram ke uploads/tmp_*.jpg. Hanya host CDN Instagram/Facebook yang diterima. */
function igDownload(array $urls, int $max = 10): array {
  $out = [];
  $mock = (string)getenv('RF_IG_BASE'); // hanya alat uji, sama seperti RF_GEMINI_BASE
  foreach ($urls as $u) {
    if (count($out) >= $max) break;
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    $cdn = strpos($u, 'https://') === 0 && preg_match('/(^|\.)(cdninstagram\.com|fbcdn\.net)$/', $host);
    if (!$cdn && !($mock !== '' && strpos($u, $mock . '/') === 0)) continue;
    try {
      $g = httpRequest('GET', $u, ['User-Agent' => 'Mozilla/5.0'], null, 25, 15000000);
      if ($g['code'] === 200 && ($p = saveTempImage($g['body']))) $out[] = $p;
    } catch (Throwable $e) { /* slide ini dilewati */ }
  }
  return $out;
}

function referenceInstructions(array $post, string $extra, int $slides): string {
  $who = $post['author'] !== '' ? '@' . $post['author'] : 'sebuah akun';
  $t = "\n\nSUMBER KHUSUS: postingan Instagram $who."
    . ($slides ? " Gambar terlampir adalah $slides slide postingan itu, berurutan, masing-masing diawali label \"SLIDE n\"." : ' Gambar postingan tidak berhasil diambil.')
    . "\nCaption postingan:\n\"\"\"\n" . mb_substr($post['caption'], 0, 5000) . "\n\"\"\"";
  if ($extra !== '') $t .= "\nTeks tambahan dari admin (biasanya komentar yang berisi prompt):\n\"\"\"\n" . $extra . "\n\"\"\"";
  return $t . <<<TXT

Langkah:
1. Temukan PROMPT foto AI yang lengkap. Prompt bisa ada di caption, di teks tambahan dari admin, atau TERTULIS DI DALAM salah satu slide. Caption sering memberi petunjuk seperti "salin prompt di slide 5".
2. Kalau prompt ada di gambar, baca (OCR) seluruh tulisannya dengan teliti dan lengkap — jangan ada kata yang terlewat, jangan mengarang.
3. Abaikan teks yang bukan prompt: watermark/nama akun, ajakan follow, hashtag, langkah cara pakai ("buka Gemini, upload foto"), dan judul poster.
4. Kalau ada beberapa prompt berbeda, ambil yang paling lengkap/utama dan sebutkan jumlahnya di "notes".
5. title, desc, dan tips ditulis untuk pembeli ResepFoto: JANGAN menyebut slide, Instagram, caption, atau nama akun sumber.
Tambahkan kunci:
- "found": true/false apakah prompt ditemukan.
- "promptSource": "image", "caption", atau "extra".
- "promptSlide": nomor slide yang berisi prompt (0 bila bukan dari gambar).
- "ocr": teks prompt PERSIS seperti di sumbernya (verbatim, sebelum dirapikan).
- "exampleSlide": nomor slide terbaik sebagai contoh HASIL foto: foto orang hasil prompt yang TIDAK ditimpa judul atau teks besar. Hindari slide sampul berjudul; pilih slide foto bersih sesudahnya. 0 bila tidak ada.
- "multiple": jumlah prompt berbeda yang terlihat di postingan.
TXT;
}

/** Mode link Instagram: baca postingan, cari prompt-nya (caption / slide / teks tambahan), isi semua kolom resep. */
function recipeFromInstagram(string $url, string $extra = ''): array {
  instagramCode($url); // link salah ditolak sebelum apa pun diambil
  if (aiKey() === '' && dsKey() === '') throw new RfError('Isi dulu API key DeepSeek atau Gemini di Admin → AI.');
  $post = instagramPost($url);
  $slides = igDownload($post['images']);
  if (!$slides && trim($post['caption']) === '' && $extra === '') throw new RfError('Postingan terbaca, tapi gambar dan caption-nya kosong.');
  $images = [];
  foreach ($slides as $i => $p) $images[] = ['label' => 'SLIDE ' . ($i + 1), 'path' => __DIR__ . '/' . $p];
  $ai = aiVisionJson('reference', recipeInstructions() . referenceInstructions($post, $extra, count($slides)), $images);
  $j = $ai['json'];
  $ocr = trim((string)($j['ocr'] ?? ''));
  $notFound = 'Prompt tidak ditemukan di caption maupun slide. Kalau prompt-nya ada di komentar, salin komentarnya ke kolom "Teks tambahan" lalu coba lagi.';
  if (isset($j['found']) && !$j['found'] && $ocr === '' && trim((string)($j['prompt'] ?? '')) === '') throw new RfError($notFound);
  $rec = normalizeRecipe($j, $ocr);
  if ($rec['prompt'] === '') throw new RfError($notFound);
  $n = count($slides);
  $ps = (int)($j['promptSlide'] ?? 0); $ps = ($ps >= 1 && $ps <= $n) ? $ps : 0;
  $ex = (int)($j['exampleSlide'] ?? 0);
  if ($ex < 1 || $ex > $n) { $ex = 0; for ($i = 1; $i <= $n; $i++) if ($i !== $ps) { $ex = $i; break; } }
  $src = (string)($j['promptSource'] ?? '');
  return $rec + [
    'ocr' => mb_substr($ocr, 0, 6000),
    'images' => $slides, 'exampleIndex' => $ex - 1, 'promptSlide' => $ps,
    'promptSource' => in_array($src, ['image', 'caption', 'extra'], true) ? $src : '',
    'multiple' => max(1, min(20, (int)($j['multiple'] ?? 1))),
    'author' => $post['author'], 'source' => 'https://www.instagram.com/p/' . $post['code'] . '/', 'via' => $post['via'],
    'engine' => $ai['engine'], 'model' => $ai['model'], 'fallback' => $ai['fallback'],
  ];
}

/** Mode gambar: prompt ADA DI DALAM gambar (screenshot). OCR teksnya lalu buat metadata. */
function recipeFromImagePrompt(string $imagePath): array {
  $instr = recipeInstructions()
    . "\n\nSUMBER KHUSUS: teks prompt-nya ADA DI DALAM GAMBAR terlampir (screenshot/foto berisi tulisan prompt). "
    . "Baca (OCR) SELURUH teks prompt dari gambar dengan teliti dan lengkap — jangan ada kata yang terlewat, jangan mengarang. "
    . "Kalau di gambar ada beberapa blok teks, ambil bagian yang merupakan prompt foto AI (biasanya paragraf instruksi paling panjang). "
    . "Tambahkan kunci \"ocr\": teks prompt PERSIS seperti tertulis di gambar (verbatim, apa adanya sebelum dirapikan), "
    . "dan \"found\": true/false apakah teks prompt ditemukan di gambar.";
  $res = geminiCall('ocr', [['text' => $instr], imagePart($imagePath)], ['json' => true, 'timeout' => 120]);
  $j = aiJson($res['text']);
  $ocr = trim((string)($j['ocr'] ?? ''));
  if ((isset($j['found']) && !$j['found']) && $ocr === '' && trim((string)($j['prompt'] ?? '')) === '') {
    throw new RfError('Tidak menemukan teks prompt di gambar. Pastikan tulisannya jelas terbaca.');
  }
  $rec = normalizeRecipe($j, $ocr);
  if ($rec['prompt'] === '' && $ocr !== '') $rec['prompt'] = $ocr;
  if ($rec['prompt'] === '') throw new RfError('Tidak menemukan teks prompt di gambar. Pastikan tulisannya jelas terbaca.');
  $rec['ocr'] = mb_substr($ocr, 0, 6000);
  return $rec;
}

/** Mode upload: prompt + gambar contoh (opsional). */
function recipeFromUpload(string $prompt, ?string $imagePath): array {
  $parts = [['text' => recipeInstructions() . "\n\nPrompt dari admin:\n" . $prompt . ($imagePath ? "\n\nGambar terlampir adalah contoh HASIL dari prompt ini." : '')]];
  if ($imagePath) $parts[] = imagePart($imagePath);
  $res = geminiCall('analyze', $parts, ['json' => true]);
  return normalizeRecipe(aiJson($res['text']), $prompt);
}

/**
 * Thumbnail resep BIKINAN SENDIRI: prompt resep + foto wajah + (opsional) slide referensi → foto baru 4:5.
 * $faceFromRef = true berarti wajah diambil dari slide referensi itu sendiri (satu gambar saja dikirim);
 * kalau false, slide hanya jadi acuan pose/cahaya/suasana dan wajahnya dari $facePath.
 * Model gambar hanya Gemini — DeepSeek tidak bisa membuat gambar.
 */
function generateThumbnail(string $prompt, ?string $refPath, ?string $facePath, bool $faceFromRef): array {
  $clean = 'The output must be a clean photograph: no text, captions, watermark, logo, username, border or collage. Vertical 4:5 portrait framing.';
  if ($faceFromRef) {
    $parts = [['text' => "Create a brand-new photo that follows the RECIPE PROMPT below.\n"
      . "IMAGE 1 is the reference: keep the same person (face, facial features, skin tone, hair and identity) and match its composition, pose, lighting, color grade and mood, "
      . "but render it as a fresh new photo — do not copy it pixel for pixel, and remove every piece of text, watermark or logo from it.\n$clean\n\nRECIPE PROMPT:\n$prompt"],
      ['text' => 'IMAGE 1 (person + style reference):'], imagePart($refPath)];
  } else {
    $txt = "Create a brand-new photo that follows the RECIPE PROMPT below.\n"
      . "IMAGE 1 is the identity reference: the person in the result MUST be this person — keep the face, facial features, skin tone and identity exactly the same.\n";
    if ($refPath) $txt .= "IMAGE 2 is a style reference ONLY: match its composition, pose, framing, lighting, color grade and mood, but do NOT copy the person or face from IMAGE 2, "
      . "and do not copy any text, watermark or logo from it.\n";
    $parts = [['text' => $txt . $clean . "\n\nRECIPE PROMPT:\n" . $prompt], ['text' => 'IMAGE 1 (identity):'], imagePart($facePath)];
    if ($refPath) { $parts[] = ['text' => 'IMAGE 2 (style reference only):']; $parts[] = imagePart($refPath); }
  }
  $gen = ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => '4:5']];
  try {
    $res = geminiCall('thumb', $parts, ['model' => aiImageModel(), 'generationConfig' => $gen, 'timeout' => 150]);
  } catch (RfError $e) {
    // Model gambar lama belum mengenal imageConfig; ulang tanpa rasio — cropTo45() tetap merapikan saat disimpan.
    if (!preg_match('/image_?config|aspect/i', $e->getMessage())) throw $e;
    unset($gen['imageConfig']);
    $res = geminiCall('thumb', $parts, ['model' => aiImageModel(), 'generationConfig' => $gen, 'timeout' => 150]);
  }
  if (!$res['images']) throw new RfError('Model tidak mengembalikan gambar' . ($res['text'] ? ': ' . mb_substr($res['text'], 0, 160) : '.'));
  return $res;
}

/* ---------- Versi English untuk etalase internasional (imagine.kitlab.id) ---------- */

/** Nama Inggris bawaan kategori (sama dengan CAT_EN di index.html). Nama yang sudah dipakai di database menang. */
const CAT_EN_MAP = ['Foto Jadul' => 'Retro Photos', 'Jalan-jalan' => 'Travel', 'Keluarga' => 'Family', 'Momen Spesial' => 'Special Moments',
  'Profesional' => 'Professional', 'Tren Viral' => 'Viral Trends', 'Editorial' => 'Editorial', 'Gaya Jalanan' => 'Street Style',
  'Kartun & Ilustrasi' => 'Cartoon & Art'];

/** Kolom EN yang masih kosong di satu resep. "prompt" hanya kalau prompt utamanya berbahasa Indonesia. */
function enNeeds(array $r): array {
  $need = [];
  foreach (['title' => ['title', 'title_en'], 'desc' => ['descr', 'descr_en'], 'tips' => ['tips', 'tips_en'], 'cat' => ['cat', 'cat_en']] as $f => [$src, $dst]) {
    if (trim((string)($r[$dst] ?? '')) === '' && trim((string)($r[$src] ?? '')) !== '') $need[] = $f;
  }
  if (trim((string)($r['prompt_en'] ?? '')) === '' && looksIndonesian((string)$r['prompt'])) $need[] = 'prompt';
  return $need;
}

/** Tampil di etalase EN = punya judul EN dan prompt-nya bisa dibaca dalam bahasa Inggris. */
function enVisible(array $r): bool {
  return trim((string)($r['title_en'] ?? '')) !== '' && !(looksIndonesian((string)$r['prompt']) && trim((string)($r['prompt_en'] ?? '')) === '');
}

function enStatus(): array {
  $s = ['total' => 0, 'visible' => 0, 'complete' => 0, 'fields' => ['title' => 0, 'desc' => 0, 'tips' => 0, 'cat' => 0, 'prompt' => 0]];
  foreach (db()->query('SELECT * FROM prompts')->fetchAll() as $r) {
    $s['total']++;
    if (enVisible($r)) $s['visible']++;
    $n = enNeeds($r);
    if (!$n) $s['complete']++;
    foreach ($n as $f) $s['fields'][$f]++;
  }
  return $s;
}

function enInstructions(): string {
  return <<<TXT
You translate the catalog of ResepFoto, an Indonesian store of AI photo "recipes" (prompts that people paste into Gemini or ChatGPT together with their own photo), for its international English storefront called Imagine.
For every item in ITEMS translate ONLY the fields that are present:
- "title": natural, catchy English title in Title Case, max 40 characters, no emoji.
- "desc": one benefit sentence for buyers, max 100 characters.
- "tips": 1–2 short sentences of practical advice; keep quoted prompt fragments in English.
- "cat": English category name, 1–3 words, Title Case (for example Retro Photos, Travel, Family, Special Moments, Professional, Viral Trends).
- "prompt": an AI image-generation prompt written in Indonesian. Translate it faithfully into clear English that an image model understands. Keep EVERY instruction, detail, number, order and line break. Do not add, remove or "improve" anything. Keep the instruction that the face and identity must stay exactly the same as the uploaded photo.
Write for an international audience. Do not mention Indonesia or Rupiah unless the content itself is about it (traditional Indonesian clothing, for example, stays as it is).
Reply with JSON only: {"items":[{"id":"<same id>", ...the same fields, translated...}]}
TXT;
}

/**
 * Lengkapi kolom EN beberapa resep sekaligus. HANYA mengisi kolom yang masih kosong — terjemahan yang sudah ada
 * dan suntingan admin tidak pernah ditimpa, dan kolom Indonesia (termasuk prompt utama) tidak pernah diubah.
 */
function enFill(int $batch = 4): array {
  $pdo = db();
  // 1) Kategori tanpa AI: pakai nama EN yang sudah dipakai kategori yang sama, atau peta bawaan.
  $known = [];
  foreach ($pdo->query("SELECT cat, cat_en FROM prompts WHERE COALESCE(cat_en, '') <> ''")->fetchAll() as $r) $known[$r['cat']] = $r['cat_en'];
  $known += CAT_EN_MAP;
  $up = $pdo->prepare("UPDATE prompts SET cat_en = ? WHERE cat = ? AND COALESCE(cat_en, '') = ''");
  foreach ($known as $cat => $en) $up->execute([$en, $cat]);
  // 2) Sisanya lewat AI, beberapa resep per permintaan supaya jawaban tidak terpotong.
  $todo = [];
  foreach ($pdo->query('SELECT * FROM prompts ORDER BY ord')->fetchAll() as $r) {
    if ($n = enNeeds($r)) $todo[$r['id']] = [$r, $n];
    if (count($todo) >= $batch) break;
  }
  if (!$todo) return ['done' => 0, 'status' => enStatus()];
  $src = ['title' => 'title', 'desc' => 'descr', 'tips' => 'tips', 'cat' => 'cat', 'prompt' => 'prompt'];
  $items = [];
  foreach ($todo as $id => [$r, $n]) { $it = ['id' => $id]; foreach ($n as $f) $it[$f] = (string)$r[$src[$f]]; $items[] = $it; }
  $ai = aiVisionJson('translate', enInstructions() . "\n\nITEMS (JSON):\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [], ['maxTokens' => 8000]);
  $dst = ['title' => ['title_en', 80], 'desc' => ['descr_en', 160], 'tips' => ['tips_en', 400], 'cat' => ['cat_en', 40], 'prompt' => ['prompt_en', 6000]];
  $done = 0;
  foreach ((array)($ai['json']['items'] ?? []) as $x) {
    $id = is_array($x) ? (string)($x['id'] ?? '') : '';
    if (!isset($todo[$id])) continue;   // id karangan AI diabaikan
    $wrote = false;
    foreach ($todo[$id][1] as $f) {
      $v = mb_substr(trim((string)($x[$f] ?? '')), 0, $dst[$f][1]);
      if ($v === '') continue;
      [$col] = $dst[$f];
      $st = $pdo->prepare("UPDATE prompts SET $col = ? WHERE id = ? AND COALESCE($col, '') = ''");
      $st->execute([$v, $id]);
      $wrote = $wrote || $st->rowCount() > 0;
      if ($f === 'cat') $pdo->prepare("UPDATE prompts SET cat_en = ? WHERE cat = ? AND COALESCE(cat_en, '') = ''")->execute([$v, $todo[$id][0]['cat']]);
    }
    if ($wrote) $done++;
  }
  return ['done' => $done, 'engine' => $ai['engine'], 'status' => enStatus()];
}

/* ---------- Versi Thai untuk etalase imagine.kitlab.id/th ---------- */

/** Nama Thai bawaan kategori (sama dengan CAT_TH di index.html). Nama yang sudah dipakai di database menang. */
const CAT_TH_MAP = ['Foto Jadul' => 'ภาพย้อนยุค', 'Jalan-jalan' => 'ท่องเที่ยว', 'Keluarga' => 'ครอบครัว', 'Momen Spesial' => 'โมเมนต์พิเศษ',
  'Profesional' => 'มืออาชีพ', 'Tren Viral' => 'เทรนด์ไวรัล', 'Editorial' => 'สไตล์นิตยสาร', 'Gaya Jalanan' => 'สตรีทสไตล์',
  'Kartun & Ilustrasi' => 'การ์ตูนและภาพวาด'];
/** field => [kolom Indonesia, kolom English, kolom Thai, panjang maksimum Thai] */
const TH_COLS = ['title' => ['title', 'title_en', 'title_th', 120], 'desc' => ['descr', 'descr_en', 'descr_th', 240],
  'tips' => ['tips', 'tips_en', 'tips_th', 600], 'cat' => ['cat', 'cat_en', 'cat_th', 60]];

/** Kolom Thai yang masih kosong di satu resep (sumbernya teks Indonesia dan/atau English). Prompt tidak ikut. */
function thNeeds(array $r): array {
  $need = [];
  foreach (TH_COLS as $f => [$id, $en, $th]) {
    if (trim((string)($r[$th] ?? '')) === '' && (trim((string)($r[$id] ?? '')) !== '' || trim((string)($r[$en] ?? '')) !== '')) $need[] = $f;
  }
  return $need;
}

/** visible = resep yang tampil di /th (sama dengan etalase English); complete = semua teks Thai-nya sudah ada. */
function thStatus(): array {
  $s = ['total' => 0, 'visible' => 0, 'complete' => 0, 'visibleComplete' => 0, 'fields' => ['title' => 0, 'desc' => 0, 'tips' => 0, 'cat' => 0]];
  foreach (db()->query('SELECT * FROM prompts')->fetchAll() as $r) {
    $s['total']++;
    $n = thNeeds($r);
    if (!$n) $s['complete']++;
    if (enVisible($r)) { $s['visible']++; if (!$n) $s['visibleComplete']++; }
    foreach ($n as $f) $s['fields'][$f]++;
  }
  return $s;
}

function thInstructions(): string {
  return <<<TXT
You are a native Thai UX copywriter localizing the catalog of an AI photo "recipe" app for its Thai storefront (brand name: Imagine).
A recipe is a ready-made prompt that people copy, then paste into Gemini or ChatGPT together with their own photo. The prompt itself stays in English, so never translate prompt text.
Each field in ITEMS gives the source text in Indonesian ("id") and, when available, English ("en"). Use English as the main source and Indonesian to check the meaning.
Write ONLY the fields that are present, in natural modern Thai: the way popular Thai lifestyle, beauty and photo apps talk to users. Warm, clear and friendly. Localize; do not translate word for word.
- "title": short, catchy Thai title, max 30 characters, no emoji, no quotation marks.
- "desc": one short benefit sentence for buyers, max 90 characters.
- "tips": 1-2 short practical sentences. Words or phrases that refer to text inside the prompt stay in English inside quotation marks, exactly as written in the source.
- "cat": Thai category name, 1-3 words.
Thai writing rules: no spaces between words; use a single space only between clauses or sentences; no full stop at the end; no ครับ/ค่ะ or other gendered particles; avoid stiff or overly formal words.
Use the English loanwords Thai users actually say: AI, พรอมต์, โปรไฟล์, ลุค, วินเทจ, ฟิล์ม, สตูดิโอ, คอนเทนต์. Keep brand names in Latin script: Gemini, ChatGPT, LinkedIn, Instagram, TikTok.
Apart from those loanwords, brand names and quoted prompt fragments, write no English words in Latin script (for example "restore" becomes ซ่อมรูปเก่า / ฟื้นฟูภาพเก่า).
Before answering, reread every string as a Thai reader would and rewrite anything that sounds translated: use natural Thai collocations (หันหน้าเข้าหาพระอาทิตย์ตก, not หันหน้าเจอ; ถ่ายคนเดียวหรือเป็นกลุ่ม for "solo or group").
Do not mention Indonesia or Rupiah unless the content itself is about it (traditional Indonesian clothing, for example, stays as it is).
Reply with JSON only: {"items":[{"id":"<same id>", ...only the requested fields, each as a plain Thai string...}]}
TXT;
}

/**
 * Lengkapi kolom Thai beberapa resep sekaligus. Sama seperti enFill: HANYA mengisi kolom yang masih kosong,
 * tidak pernah menimpa terjemahan/suntingan yang sudah ada, dan tidak pernah mengubah kolom Indonesia/English.
 */
function thFill(int $batch = 6): array {
  $pdo = db();
  // 1) Kategori tanpa AI: nama Thai yang sudah dipakai kategori yang sama, atau peta bawaan.
  $known = [];
  foreach ($pdo->query("SELECT cat, cat_th FROM prompts WHERE COALESCE(cat_th, '') <> ''")->fetchAll() as $r) $known[$r['cat']] = $r['cat_th'];
  $known += CAT_TH_MAP;
  $up = $pdo->prepare("UPDATE prompts SET cat_th = ? WHERE cat = ? AND COALESCE(cat_th, '') = ''");
  foreach ($known as $cat => $th) $up->execute([$th, $cat]);
  // 2) Sisanya lewat AI. Resep yang sudah tampil di /th didahulukan.
  $rows = $pdo->query('SELECT * FROM prompts ORDER BY ord')->fetchAll();
  usort($rows, fn($a, $b) => (int)enVisible($b) <=> (int)enVisible($a));
  $todo = [];
  foreach ($rows as $r) {
    if ($n = thNeeds($r)) $todo[$r['id']] = [$r, $n];
    if (count($todo) >= $batch) break;
  }
  if (!$todo) return ['done' => 0, 'status' => thStatus()];
  $items = [];
  foreach ($todo as $id => [$r, $n]) {
    $it = ['id' => $id];
    foreach ($n as $f) {
      [$src, $en] = TH_COLS[$f];
      $it[$f] = array_filter(['id' => trim((string)$r[$src]), 'en' => trim((string)($r[$en] ?? ''))], 'strlen');
    }
    $items[] = $it;
  }
  $ai = aiVisionJson('translate_th', thInstructions() . "\n\nITEMS (JSON):\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [], ['maxTokens' => 8000]);
  $done = 0;
  foreach ((array)($ai['json']['items'] ?? []) as $x) {
    $id = is_array($x) ? (string)($x['id'] ?? '') : '';
    if (!isset($todo[$id])) continue;   // id karangan AI diabaikan
    $wrote = false;
    foreach ($todo[$id][1] as $f) {
      [, , $col, $max] = TH_COLS[$f];
      $v = is_string($x[$f] ?? null) ? mb_substr(trim($x[$f]), 0, $max) : '';
      if (!preg_match('/\p{Thai}/u', $v)) continue;   // jawaban yang bukan huruf Thai tidak disimpan
      $st = $pdo->prepare("UPDATE prompts SET $col = ? WHERE id = ? AND COALESCE($col, '') = ''");
      $st->execute([$v, $id]);
      $wrote = $wrote || $st->rowCount() > 0;
      if ($f === 'cat') $pdo->prepare("UPDATE prompts SET cat_th = ? WHERE cat = ? AND COALESCE(cat_th, '') = ''")->execute([$v, $todo[$id][0]['cat']]);
    }
    if ($wrote) $done++;
  }
  return ['done' => $done, 'engine' => $ai['engine'], 'status' => thStatus()];
}

/** Tes generate internal memakai model gambar Gemini. */
function generateTestImage(string $prompt, string $inputPath): array {
  $res = geminiCall('generate', [['text' => $prompt], imagePart($inputPath)],
    ['model' => aiImageModel(), 'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']], 'timeout' => 150]);
  if (!$res['images']) throw new RfError('Model tidak mengembalikan gambar' . ($res['text'] ? ': ' . mb_substr($res['text'], 0, 160) : '.'));
  return $res;
}
