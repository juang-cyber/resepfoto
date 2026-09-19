<?php
/**
 * Pembaca & penulis XLSX seadanya — tanpa pustaka luar.
 *
 * Kenapa ditulis sendiri: hosting ini tidak punya Composer, dan satu-satunya
 * yang dibutuhkan cuma satu lembar berisi teks. PhpSpreadsheet berlebihan.
 *
 * Aturan yang dipegang file ini:
 * - Semua nilai ditulis sebagai inlineStr kecuali kolom yang ditandai angka.
 *   Kalau id seperti "r1a2b3" atau tanggal ISO ditulis sebagai sel biasa,
 *   Excel akan menebak tipenya dan MENGUBAH isinya. Teks apa adanya aman.
 * - Tanggal sengaja tetap teks ISO, bukan tanggal Excel. Tanggal Excel itu
 *   angka berbasis zona waktu; sekali bolak-balik ekspor-impor bisa geser
 *   sehari. Teks ISO pulang-pergi persis sama.
 * - Saat membaca: tanpa jaringan (LIBXML_NONET) dan tanpa substitusi entitas,
 *   supaya file kiriman orang tidak bisa dipakai membaca file server (XXE).
 *
 * Kompatibel PHP 7.4.
 */

const XLSX_MAX_UNZIP = 40 * 1024 * 1024;   // pagar zip bomb: total hasil ekstrak
const XLSX_SHEET_NS  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

/** Indeks kolom 0-based menjadi huruf: 0=A, 25=Z, 26=AA. */
function xlsxCol(int $i): string {
  $s = ''; $n = $i + 1;
  while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - $m - 1, 26); }
  return $s;
}

/** Buang karakter yang tidak sah di XML 1.0 lalu escape. Tab/LF/CR dipertahankan. */
function xlsxText(string $v): string {
  $bersih = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
  // preg_replace mengembalikan null kalau teksnya bukan UTF-8 sah; jangan sampai
  // isi sel hilang diam-diam, jadi ulangi tanpa mode /u sebagai cadangan.
  if ($bersih === null) $bersih = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
  return htmlspecialchars((string)$bersih, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Tulis satu lembar ke file .xlsx.
 * $headers = daftar judul kolom; $rows = daftar baris (array sejajar $headers).
 * $numeric = indeks kolom yang ditulis sebagai angka (supaya bisa diurutkan di Excel).
 * $widths  = lebar kolom per indeks, opsional.
 */
function xlsxWrite(string $path, array $headers, array $rows, array $numeric = [], array $widths = []): void {
  if (!class_exists('ZipArchive')) throw new RuntimeException('Server ini tidak punya ekstensi ZipArchive, file Excel tidak bisa dibuat.');
  $zip = new ZipArchive();
  @unlink($path);
  if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Tidak bisa menulis file Excel sementara.');
  }
  $num = array_flip($numeric);

  $cols = '';
  foreach ($headers as $i => $_) {
    $w = isset($widths[$i]) ? (float)$widths[$i] : 18;
    $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
  }

  $sd = '';
  // Baris judul: tebal, dibekukan supaya tetap terlihat saat digulir.
  $sd .= '<row r="1">';
  foreach ($headers as $i => $h) {
    $sd .= '<c r="' . xlsxCol($i) . '1" t="inlineStr" s="1"><is><t>' . xlsxText((string)$h) . '</t></is></c>';
  }
  $sd .= '</row>';
  $r = 1;
  foreach ($rows as $row) {
    $r++;
    $sd .= '<row r="' . $r . '">';
    foreach ($headers as $i => $_) {
      $v = (string)($row[$i] ?? '');
      if ($v === '') continue;                       // sel kosong tidak perlu ditulis
      $ref = xlsxCol($i) . $r;
      if (isset($num[$i]) && is_numeric($v)) {
        $sd .= '<c r="' . $ref . '"><v>' . xlsxText($v) . '</v></c>';
      } else {
        $sd .= '<c r="' . $ref . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . xlsxText($v) . '</t></is></c>';
      }
    }
    $sd .= '</row>';
  }

  $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $zip->addFromString('[Content_Types].xml', $x
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>');
  $zip->addFromString('_rels/.rels', $x
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');
  $zip->addFromString('xl/workbook.xml', $x
    . '<workbook xmlns="' . XLSX_SHEET_NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Resep" sheetId="1" r:id="rId1"/></sheets></workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels', $x
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>');
  $zip->addFromString('xl/styles.xml', $x
    . '<styleSheet xmlns="' . XLSX_SHEET_NS . '">'
    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
    . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
    . '<borders count="1"><border/></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="3">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
    . '</cellXfs>'
    // cellStyles WAJIB sesudah cellXfs — urutan elemen di styleSheet terikat skema,
    // kalau dibalik Excel menolak seluruh berkas.
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>');
  $zip->addFromString('xl/worksheets/sheet1.xml', $x
    . '<worksheet xmlns="' . XLSX_SHEET_NS . '">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>'
    . '<cols>' . $cols . '</cols>'
    . '<sheetData>' . $sd . '</sheetData></worksheet>');
  if ($zip->close() !== true) throw new RuntimeException('Gagal menutup file Excel.');
}

/** Muat XML dengan aman: tanpa jaringan, tanpa substitusi entitas. */
function xlsxXml(string $s) {
  $prev = libxml_use_internal_errors(true);
  $doc = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  return $doc;
}

/** Baca satu entri zip dengan pagar ukuran. */
function xlsxEntry(ZipArchive $zip, string $name, int &$sisa): string {
  $i = $zip->locateName($name, ZipArchive::FL_NOCASE);
  if ($i === false) return '';
  $st = $zip->statIndex($i);
  $size = (int)($st['size'] ?? 0);
  if ($size > $sisa) throw new RuntimeException('Isi file Excel terlalu besar.');
  $sisa -= $size;
  return (string)$zip->getFromIndex($i);
}

/**
 * Baca lembar pertama .xlsx menjadi array baris berisi array sel (string).
 * Sel kosong diisi string kosong; panjang tiap baris disamakan dengan kolom terjauh.
 */
function xlsxRead(string $path): array {
  if (!class_exists('ZipArchive')) throw new RuntimeException('Server ini tidak punya ekstensi ZipArchive, file Excel tidak bisa dibaca.');
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) throw new RuntimeException('File itu bukan file Excel yang bisa dibuka.');
  $sisa = XLSX_MAX_UNZIP;
  try {
    // Cari lembar pertama lewat relasi workbook; kalau gagal, pakai jalur baku.
    $target = 'xl/worksheets/sheet1.xml';
    $rels = xlsxEntry($zip, 'xl/_rels/workbook.xml.rels', $sisa);
    if ($rels !== '') {
      $rx = xlsxXml($rels);
      if ($rx) foreach ($rx->Relationship as $rel) {
        if (substr((string)$rel['Type'], -9) === 'worksheet') {
          $t = ltrim((string)$rel['Target'], '/');
          if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
          $target = $t; break;
        }
      }
    }
    $shared = [];
    $ss = xlsxEntry($zip, 'xl/sharedStrings.xml', $sisa);
    if ($ss !== '') {
      $sx = xlsxXml($ss);
      if ($sx) foreach ($sx->si as $si) {
        // <si> bisa berisi <t> tunggal atau banyak <r><t> (teks berformat).
        $buf = '';
        foreach ($si->t as $t) $buf .= (string)$t;
        foreach ($si->r as $r) foreach ($r->t as $t) $buf .= (string)$t;
        $shared[] = $buf;
      }
    }
    $sheet = xlsxEntry($zip, $target, $sisa);
    if ($sheet === '') throw new RuntimeException('Lembar pertama tidak ditemukan di file Excel itu.');
    $wx = xlsxXml($sheet);
    if (!$wx) throw new RuntimeException('Isi file Excel tidak bisa dibaca.');
    $out = [];
    foreach ($wx->sheetData->row as $row) {
      $cells = []; $max = -1;
      foreach ($row->c as $c) {
        $ref = (string)$c['r'];
        $col = 0;
        if (preg_match('/^([A-Z]+)/', $ref, $m)) {
          $col = 0;
          foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
          $col--;
        } else { $col = $max + 1; }
        $type = (string)$c['t'];
        if ($type === 'inlineStr') {
          $v = '';
          if (isset($c->is)) { foreach ($c->is->t as $t) $v .= (string)$t; foreach ($c->is->r as $r) foreach ($r->t as $t) $v .= (string)$t; }
        } elseif ($type === 's') {
          $idx = (int)(string)$c->v;
          $v = $shared[$idx] ?? '';
        } else {
          $v = isset($c->v) ? (string)$c->v : '';
        }
        $cells[$col] = $v;
        if ($col > $max) $max = $col;
      }
      $line = [];
      for ($i = 0; $i <= $max; $i++) $line[] = (string)($cells[$i] ?? '');
      $out[] = $line;
    }
    return $out;
  } finally {
    $zip->close();
  }
}

/** Baca CSV (koma atau titik koma, BOM ditoleransi) dengan bentuk keluaran sama seperti xlsxRead. */
function csvRead(string $path): array {
  $fh = @fopen($path, 'r');
  if (!$fh) throw new RuntimeException('File CSV tidak bisa dibuka.');
  $first = (string)fgets($fh);
  if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) $first = substr($first, 3);
  // Excel versi Indonesia sering menyimpan CSV dengan titik koma.
  $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
  rewind($fh);
  $bom = fread($fh, 3);
  if ($bom !== "\xEF\xBB\xBF") rewind($fh);
  $out = [];
  while (($r = fgetcsv($fh, 0, $sep)) !== false) {
    if ($r === [null]) continue;                       // baris kosong
    $out[] = array_map(function ($v) { return (string)$v; }, $r);
  }
  fclose($fh);
  return $out;
}
