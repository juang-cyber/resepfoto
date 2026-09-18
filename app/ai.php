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

function aiKey(): string { return setting('gemini_api_key'); }
function aiModel(): string { $m = setting('gemini_model'); return $m !== '' ? $m : AI_DEFAULT_MODEL; }
function aiImageModel(): string { $m = setting('gemini_image_model'); return $m !== '' ? $m : AI_DEFAULT_IMAGE_MODEL; }

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
  $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $hdr . "User-Agent: Mozilla/5.0 (compatible; ResepFotoBot/1.0)\r\n",
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

/** Link referensi: Gemini / ChatGPT share link → data resep. */

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

/** Tes generate internal memakai model gambar Gemini. */
function generateTestImage(string $prompt, string $inputPath): array {
  $res = geminiCall('generate', [['text' => $prompt], imagePart($inputPath)],
    ['model' => aiImageModel(), 'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']], 'timeout' => 150]);
  if (!$res['images']) throw new RfError('Model tidak mengembalikan gambar' . ($res['text'] ? ': ' . mb_substr($res['text'], 0, 160) : '.'));
  return $res;
}
