<?php
/**
 * Penerima webhook Mayar (event payment.received).
 * Pasang URL ini di Mayar → Integrasi → Webhook.
 * Keamanan: pesanan hanya diaktifkan otomatis jika Webhook Token dari Mayar
 * (disimpan di panel admin) cocok dengan token yang dikirim Mayar.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): void { http_response_code($code); echo json_encode($data); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['ok' => true, 'message' => 'ResepFoto webhook aktif. Gunakan POST.']);

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 200000) reply(['ok' => false, 'error' => 'payload terlalu besar'], 413);
$j = json_decode($raw, true);
if (!is_array($j)) { $j = $_POST ?: []; }

// kumpulkan header (nama saja yang dicatat, nilai tidak disimpan)
$headers = [];
foreach ($_SERVER as $k => $v) {
  if (strpos($k, 'HTTP_') === 0) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
}
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// verifikasi token
$token = setting('mayar_webhook_token');
$verified = false;
if ($token !== '') {
  $candidates = array_values($headers);
  if (isset($_GET['token'])) $candidates[] = (string)$_GET['token'];
  if (isset($j['token'])) $candidates[] = (string)$j['token'];
  foreach ($candidates as $c) {
    $c = trim(preg_replace('/^Bearer\s+/i', '', $c));
    if ($c !== '' && hash_equals($token, $c)) { $verified = true; break; }
  }
}

$event = (string)($j['event'] ?? $j['type'] ?? $j['eventName'] ?? '');
$d = is_array($j['data'] ?? null) ? $j['data'] : $j;

$pdo = db();
$pdo->prepare('INSERT INTO webhook_log (ts, ip, event, verified, headers, note) VALUES (?,?,?,?,?,?)')
  ->execute([gmdate('c'), $ip, $event ?: '(kosong)', $verified ? 1 : 0, implode(', ', array_keys($headers)),
    $token === '' ? 'token belum diisi di panel' : ($verified ? 'token cocok' : 'token TIDAK cocok')]);
$pdo->exec('DELETE FROM webhook_log WHERE id NOT IN (SELECT id FROM webhook_log ORDER BY id DESC LIMIT 100)');

// Dua event yang kita layani. 'reminder' dipakai untuk mengirim pengingat dari
// KitLab sendiri — Mayar punya pengingatnya sendiri, tapi pembeli lebih percaya
// pesan dari penjualnya, dan template kita memuat link bayar serta nominalnya.
$isPaid   = stripos($event, 'payment.received') !== false;
$isRemind = !$isPaid && stripos($event, 'reminder') !== false;
if (!$isPaid && !$isRemind) reply(['ok' => true, 'ignored' => 'event ' . $event]);

$txId = (string)($d['id'] ?? $d['transactionId'] ?? $d['invoiceId'] ?? '');
$txId = preg_replace('/[^A-Za-z0-9_.:-]/', '', $txId);
if ($txId === '') reply(['ok' => true, 'ignored' => 'tanpa id transaksi']);

$product = (string)($d['productName'] ?? $d['product']['name'] ?? '');
$amount = (int)round((float)($d['amount'] ?? $d['totalAmount'] ?? 0));
$plan = planFromProduct($product, $amount);
if ($plan === null) reply(['ok' => true, 'ignored' => 'bukan produk ResepFoto']);

$statusRaw = $d['status'] ?? '';
$status = is_bool($statusRaw) ? ($statusRaw ? 'true' : 'false') : strtolower((string)$statusRaw);
// Event pengingat TIDAK BOLEH dianggap lunas. Status sering kosong di payload itu,
// dan aturan "status kosong = lunas" akan salah menafsirkannya jadi pembayaran sah.
$paid = $isRemind ? false
  : ($status === '' || in_array($status, ['success', 'succeeded', 'paid', 'settled', 'settlement', 'completed', 'complete', 'true', 'lunas'], true));

$name = mb_substr(trim((string)($d['customerName'] ?? $d['customer']['name'] ?? '')), 0, 80);
$email = mb_substr(trim((string)($d['customerEmail'] ?? $d['customer']['email'] ?? '')), 0, 120);
$phone = preg_replace('/[^0-9+]/', '', (string)($d['customerMobile'] ?? $d['customer']['mobile'] ?? ''));
$phone = mb_substr($phone, 0, 20);

$existing = findOrder($txId);
if ($existing && $existing['state'] === 'aktif') reply(['ok' => true, 'duplicate' => true]);

$state = !$paid ? 'belum_lunas' : ($verified ? 'lunas' : 'perlu_cek');
$now = gmdate('c');
if ($existing) {
  updateOrder($txId, ['event' => $event, 'status' => $status, 'verified' => $verified ? 1 : (int)$existing['verified'],
    'state' => $existing['state'] === 'ditolak' ? 'ditolak' : $state, 'raw' => mb_substr($raw, 0, 20000)]);
} else {
  $pdo->prepare('INSERT INTO orders (id, source, event, status, verified, state, plan, product, amount, name, email, phone, username, code, emailed, note, raw, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
    $txId, 'mayar', $event, $status, $verified ? 1 : 0, $state, $plan, $product, $amount, $name, $email, $phone,
    null, null, 0, $verified ? '' : 'Token webhook belum cocok. Cek transaksi di dashboard Mayar lalu aktifkan manual.',
    mb_substr($raw, 0, 20000), $now, $now]);
}

$o = findOrder($txId);
$auto = $paid && ($verified || ($token === '' && setting('auto_without_token') === '1'));
if ($auto && $o['state'] !== 'ditolak') {
  try { fulfillOrder($txId, true); }
  catch (Throwable $e) { error_log('[resepfoto webhook] ' . $e->getMessage()); updateOrder($txId, ['note' => 'Gagal aktivasi otomatis: cek log server.']); }
} elseif ($paid) {
  notifyAdmin($txId);
} elseif ($isRemind && $verified && $o['state'] === 'belum_lunas') {
  // Pengingat otomatis dari KitLab. Pengamannya (maks 2 kali, jeda 24 jam, penanda
  // ditulis sebelum kirim) ada di autoRemind(), sama persis dengan tombol di panel —
  // jadi webhook kembar dari Mayar tidak bisa membanjiri pembeli.
  try { autoRemind($txId); }
  catch (Throwable $e) { error_log('[resepfoto webhook] pengingat: ' . $e->getMessage()); }
}
reply(['ok' => true]);
