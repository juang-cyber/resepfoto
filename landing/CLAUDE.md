# Halaman iklan ResepFoto — panduan untuk Claude Code

> Claude Code membaca file ini otomatis saat bekerja di folder `landing/`, sebagai tambahan `CLAUDE.md` di root repo.
> Terakhir diperbarui: 17 September 2026.

## Ringkasan sepuluh detik
Desain dirawat di **`landing/mockup.html`**. Halaman yang tayang di **`resepfoto.oziera.co.id/promo`** tidak diedit
langsung — ia **dibangkitkan** dari mockup oleh `landing/build-promo.mjs`. Jadi alurnya selalu:

```
landing/mockup.html  ──build-promo.mjs──▶  landing/index.html  ──cron cPanel──▶  <docroot>/promo/
     (sumber desain)                        (jangan diedit tangan)      + landing/img/
```

Kalau kamu menemukan diri sedang mengedit `landing/index.html`, berhenti — edit mockup-nya lalu bangun ulang.
Cron menyalin `landing/index.html` + `landing/img/` ke `promo/`; `mockup.html` sengaja **tidak** ikut.

## Isi folder
| File | Fungsi |
|---|---|
| `mockup.html` | **Sumber desain** (≈128 KB, CSS & JS inline, tanpa framework). `MOCKUP = true` → pembayaran nonaktif, semua data berlabel ILUSTRASI. Dipublikasikan sebagai Artifact untuk presentasi |
| `build-promo.mjs` | Membangkitkan `index.html` dari `mockup.html`. Tanpa dependency, jalan dengan `node` biasa |
| `img/` | 122 file: **100** gambar katalog resep (`pNN.jpg` / `rtl*.jpg`, 600×750 q78 mozjpeg, rata-rata 46 KB) + `rb1-6`/`ra1-6` pasangan before/after hero + `av1-5` avatar testimoni + `tr1-5` foto hasil testimoni |
| `index.html` | **Halaman yang tayang di `/promo`.** Dibangkitkan oleh `build-promo.mjs` — jangan diedit tangan, perubahannya akan tertimpa build berikutnya |

Artifact presentasi (privat, bisa dibagikan): https://claude.ai/artifact/QdRiZFRSZvidkHEguipAqw

## Dua mode build
```bash
node landing/build-promo.mjs           # PRATINJAU (default)
node landing/build-promo.mjs --live    # PRODUKSI
```

Keduanya menulis **`landing/index.html`** dan menerapkan setelan produksi yang sama: `PREVIEW` & `MOCKUP` jadi
`false`, judul produksi, **pembayaran Mayar aktif**, `TRACK_URL = "/api.php"` berikut blok pelacakan lengkap,
notifikasi pesanan dari `api.php?a=recent_orders`, penghitung pengunjung dari `api.php?a=live`. Gambarnya tidak
disalin ke mana-mana — `landing/img/` sudah ikut diangkut cron apa adanya.

Bedanya cuma data ilustrasi:

| | Pratinjau (default) | `--live` |
|---|---|---|
| `TESTIMONIALS` (5 kartu contoh) | tetap tampil | dikosongkan, bagiannya disembunyikan |
| `RATING` (4,9 · 483 ulasan) | tetap tampil | jadi `null` |
| Label pojok **CONTOH** | tetap tampil | markup, CSS, dan skripnya dibuang |

**Yang sekarang ter-commit di `landing/index.html` adalah mode pratinjau**, atas permintaan pemilik repo: pembayaran sudah
bisa dites sungguhan sementara halaman tetap jujur menyatakan dirinya contoh, dan belum dibagikan ke Meta.
**Sebelum iklan diarahkan ke halaman ini, bangun ulang dengan `--live` lalu push.**

## Pengaturan di `mockup.html` (blok `/* ---- ubah di sini ---- */`, sekitar baris 831)
| Konstanta | Nilai sekarang | Arti |
|---|---|---|
| `CHECKOUT.standard` / `.premium` | `https://kitlab.myr.id/pl/resepfoto-standard` / `-premium` | Link pembayaran Mayar |
| `PLANS` | Standard `49900` (coret `150000`) · Premium `79900` (coret `299000`) | Harga tampil. **Harus sama** dengan harga di Mayar |
| `PREVIEW` | `true` | Data pratinjau berlabel. Di-`false`-kan oleh skrip build |
| `MOCKUP` | `true` | Pembayaran nonaktif + banner "versi presentasi". Di-`false`-kan oleh skrip build |
| `TESTIMONIALS` | 5 kartu ILUSTRASI | Dibuang oleh `--live` |
| `RATING` | `{avg: 4.9, count: 483}` ILUSTRASI | Dibuang oleh `--live` |
| `ORDER_FEED_URL` | `""` | Diisi `/api.php?a=recent_orders` oleh skrip build |
| `LIVE_VIEWERS` | `null` | Diganti panggilan `api.php?a=live` asli oleh skrip build |
| `EXAMPLES` | 6 pasang `rbN`/`raN` | Before/after hero, dari foto member asli |
| `RECIPES` | 100 resep | Katalog asli, hasil sinkron dari `api.php?a=prompts` |
| `BESTSELLERS` | 14 id | Resep bertanda `popular`; urutannya **diacak Fisher-Yates tiap halaman dibuka** |
| `WALL`, `AUDIENCE` | 9 + 8 id | "Hasil dari resep kami" dan "Cocok untuk siapa" |

43 resep dipakai di halaman **tanpa satu pun berulang antar bagian** — itu disengaja, jaga kalau menambah bagian baru.

## Aturan bukti sosial (WAJIB — jangan dilanggar)
- **Jangan pernah mengarang** testimoni, rating, jumlah pembeli, notifikasi pesanan, atau jumlah "sedang melihat".
  Semua itu hanya boleh diisi dari **data asli**.
- Data ilustrasi hanya boleh hidup di `mockup.html` dan di build **pratinjau**, dan wajib tetap **berlabel jelas**
  (banner "versi presentasi" + label pojok CONTOH). Itu sebabnya mode pratinjau ada: supaya halaman contoh tidak
  pernah menyamar jadi halaman asli.
- Build `--live` mengosongkan ketiganya. Bagian yang datanya kosong **otomatis disembunyikan** — itu perilaku yang
  benar, jangan diisi angka palsu supaya "tidak kosong".
- Testimoni dengan foto/nama pembeli: harus ada izin tertulis sebelum dipasang. Nama disamarkan dengan pola
  huruf terakhir nama depan jadi `*`, nama belakang jadi `****` — mis. `Andik* ****`.
- Notifikasi pesanan memakai `recent_orders` (pesanan asli 14 hari, nama disamarkan). Penghitung "sedang melihat"
  memakai `presence` asli dan hanya muncul bila ≥ 5 orang aktif atau ≥ 50 pengunjung/24 jam.

## ⚠️ Hak cipta gambar katalog — belum beres, menyangkut iklan berbayar
`CLAUDE.md` di root mencatat bahwa **foto contoh resep `p21`–`p66` berasal dari slide Instagram akun lain**
(watermark sudah dipotong, tapi sumbernya tetap karya orang lain dan beberapa menampilkan figur publik).

Halaman iklan ikut memakainya. Dari 18 foto `pNN` yang tampil di `/promo`, **16 masuk rentang itu**:

```
galeri "Paling laris"   p21 p23 p24 p27 p29 p33 p36 p39 p65
"Hasil dari resep kami" p37 p51 p52 p56 p66
"Cocok untuk siapa"     p44 p60
aman (di luar rentang)  p16 p17
```

Di dalam app berbayar risikonya sudah ada; di **iklan Meta berbayar** risikonya lebih besar, karena:
- jangkauannya publik dan berbayar, jadi jauh lebih mudah ditemukan pemilik aslinya;
- memakai wajah figur publik di materi iklan bisa kena kebijakan Meta dan berujung akun iklan ditangguhkan.

**Sebelum iklan dijalankan**, ganti keenam belas foto itu: generate ulang lewat Admin → AI Gemini → tes generate,
simpan hasilnya, lalu sinkronkan ke `landing/img/` dan bangun ulang `/promo`. 13 gambar `rtl*` (dari upload member
sendiri) dan pasangan hero `rb*`/`ra*` tidak termasuk masalah ini.

## Isi halaman
`#top` hero (6 pasang before/after, slider banding, frame **iPhone 17** berisi alur 3 langkah) → `#masalah` banner
masalah → "Kenapa ResepFoto" (5 ikon + anotasi tulisan tangan) → `#cara` demo 3 langkah → `#galeri` 14 best seller
acak → kalkulator hemat → "Cocok untuk siapa" (8 persona) → "Hasil dari resep kami" (9 resep) → `#harga` dua paket →
label **Satisfaction Guarantee** → `#testimoni` → `#faq` → CTA penutup berkolase → footer `© 2026 ResepFoto`.

**Kalkulator hemat** memakai kurva pangkat, menggantikan eksponensial yang mendatar sejak sekitar foto ke-400:

```
studio(q) = 3.000.000 × (q/1000)^k ,  k = ln(50.000/3.000.000) / ln(1/1000) ≈ 0,5927
```

Foto pertama tepat Rp 50.000, foto ke-1000 tepat Rp 3.000.000 — jadi angka hemat Rp 2.950.100 jatuh pas di posisi
terakhir slider. Kalau mengubah `PLANS`, `PER_PHOTO`, `STUDIO_MAX`, atau `QTY_MAX`, cek lagi ujung slidernya.

**Frame iPhone 17** digambar penuh dengan CSS (tidak ada foto produk): proporsi bodi `715/1496`, Dynamic Island,
tombol samping, alur di-mask lewat `.bezel` → `.screen`.

## Slot aset yang dipanggil dari luar
- **Label CONTOH** → `https://ik.imagekit.io/oziera/contoh.png`, dipanggil lewat URL supaya pemilik repo bisa
  memperbarui desainnya tanpa menyentuh kode. Ada fallback teks kalau gambarnya gagal dimuat. Lebar 104px, dan
  **belum pernah bisa dilihat dari environment agent** (ImageKit diblokir, lihat di bawah).
- **Latar CTA penutup** → skrip mencoba `img/bg-final.jpg`, `.png`, lalu `.webp`. Kalau tidak ada satu pun, hiasan
  CSS bergradien yang dipakai. Filenya **belum pernah diterima**; slotnya sudah siap.

## Pelacakan iklan (bekerja sama dengan `app/api.php`)
Halaman mengirim event ke `TRACK_URL + "?a=lt"` (POST JSON, tanpa CSRF): `view`, `cta`, `checkout`, `pay`, dan
`ping`. Pengunjung dikenali `vid` acak di localStorage; `sid` di sessionStorage; bot diabaikan server. Sumber
diambil dari `utm_source`/`utm_medium`/`utm_campaign`/`utm_content`, `fbclid`, atau domain referrer, disimpan 7 hari.
Semua event menandai `page: "promo"`. Laporan muncul di **Admin → Iklan** (khusus super admin). Batas 300
event/pengunjung/hari.

Link iklan Meta:
```
https://resepfoto.oziera.co.id/promo?utm_source=facebook&utm_medium=paid&utm_campaign=NAMA&utm_content={{ad.name}}
```

## Pembayaran Mayar — yang harus dikerjakan pemilik repo
Kode sudah siap dan sudah diuji; yang tersisa murni setelan dashboard:
1. Harga produk: Standard `49900`, Premium `79900`.
2. **Nama produk wajib memuat kata "Standard" / "Premium"** — mis. `ResepFoto Standard`, `ResepFoto Premium`.
   Slug URL tidak dibaca.
3. Webhook → `https://resepfoto.oziera.co.id/webhook-mayar.php`.
4. Webhook Token dari Mayar → tempel di **Admin → Pesanan**. Tanpa token, pesanan masuk tapi tidak aktif otomatis.

**KOREKSI PENTING — jangan diulangi.** Ambang `Rp 90.000` di `planFromProduct()` (`app/lib.php`) **bukan masalah**
untuk Premium seharga Rp 79.900. Fungsi itu memeriksa nama produk lebih dulu; nominal cuma cadangan kalau nama
produk tidak memuat kata "premium" atau "standard":
```php
if (strpos($p, 'premium') !== false) return 'Premium';
if (strpos($p, 'standard') !== false || strpos($p, 'standar') !== false) return 'Standard';
return $amount >= 90000 ? 'Premium' : 'Standard';   // hanya cadangan
```
Sudah dibuktikan dengan uji lokal (lihat bagian Pengujian). Tidak ada perubahan kode yang diperlukan.

## Go-live checklist
1. `node landing/build-promo.mjs --live` — **wajib**, ini yang membuang testimoni karangan, rating, dan label CONTOH.
2. Cek `CHECKOUT` & `PLANS` cocok dengan produk di Mayar, termasuk **nama produknya**.
3. Webhook Mayar terdaftar dan Webhook Token sudah diisi di Admin → Pesanan.
4. Commit & push ke `main`; tunggu ±5 menit, halaman tayang di `/promo`.
   Verifikasi: `curl -sI https://resepfoto.oziera.co.id/promo/`.
5. Tes: buka `/promo?utm_source=test&utm_campaign=cek`, klik CTA & checkout, pastikan angkanya muncul di
   **Admin → Iklan**.
6. Tes beli sungguhan sekali dengan nominal terkecil; pastikan email akses sampai ke **inbox**, bukan spam.

## Pengujian
```bash
# sintaks skrip inline
node -e "const h=require('fs').readFileSync('landing/mockup.html','utf8');for(const b of h.match(/<script>([\s\S]*?)<\/script>/g)){const c=b.slice(8,-9);if(c.trim().length<50)continue;new Function(c)};console.log('OK')"

# atau pisahkan dulu lalu node --check
sed -n '/^<script>$/,/^<\/script>$/p' landing/index.html | sed '1d;$d' > /tmp/x.js && node --check /tmp/x.js
```

Alur pembayaran + email pernah diuji ujung ke ujung secara lokal: salinan `app/` terisolasi, database kosong,
`sendmail_path` diarahkan ke skrip penangkap sehingga tidak ada email yang benar-benar terkirim. Hasilnya:
webhook `payment.received` dengan `productName: "ResepFoto Premium"` dan `amount: 79900` → pesanan `state=aktif`,
`verified=1`, member dibuat sebagai **Premium**, `emailed=1`, email pembeli dan email admin keduanya benar.
Uji negatif `productName: "Akses Selamanya ResepFoto"` pada nominal sama → terbaca **Standard**, membuktikan
nama produk yang menentukan.

Tes itu memakai `mail()` lokal, jadi ia membuktikan logika paket dan isi pesannya benar — **bukan** pengiriman di
server sungguhan. Di hosting sekarang (Jagoan Hosting) `mail()` justru **selalu gagal**; setelan yang bekerja adalah
SMTP `localhost:25` tanpa autentikasi. Rinciannya di `CLAUDE.md` root, bagian Email & WhatsApp.

Render: cek di lebar 390px dan 520px, tidak boleh ada luber horizontal. Hormati `prefers-reduced-motion`.

## Environment agent: yang diblokir
Sesi Claude Code remote **tidak bisa** menjangkau host berikut (403 pada CONNECT / HTTP 000). Jangan buang waktu
mencoba, dan jangan menjanjikan bisa mengerjakannya:

| Host | Akibatnya |
|---|---|
| `resepfoto.oziera.co.id` | Tidak bisa membaca database/katalog live. Data harus dikirim pemilik repo sebagai lampiran file |
| `kitlab.myr.id`, `mayar.id`, `web.mayar.id` | Tidak bisa menyetel produk/webhook Mayar sama sekali |
| `ik.imagekit.io` | Tidak bisa melihat label CONTOH |

Yang **bisa** dijangkau: `github.com`, `raw.githubusercontent.com`, `fonts.googleapis.com`, registry npm & pypi,
serta Google Drive lewat konektor. Itu jalur transfer file yang berhasil dipakai selama ini.

Catatan penting soal lampiran: **gambar yang ditempel langsung di chat tidak tersimpan sebagai file.** Hanya
lampiran file sungguhan yang mendarat di `/root/.claude/uploads/`. Kalau butuh file dari pemilik repo, minta
dilampirkan sebagai file atau lewat Drive.

## Gotcha yang pernah terjadi
- **Playwright** di container ini: build Chromium-nya 1194 sedangkan playwright mengharapkan 1243. Wajib
  `chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox']})`. Jangan jalankan
  `playwright install`.
- **Screenshot bagian atas tampak kosong** bukan karena bug CSS — `scrollIntoViewIfNeeded` meninggalkan elemen di
  luar viewport sehingga Chromium tidak pernah melukisnya. Paksa scroll instan sebelum capture.
- **Crop otomatis memotong wajah.** `sharp` dengan `position: "attention"` memilih area berbeda tiap gambar. Untuk
  avatar dan hero, pakai `extract()` dengan pecahan eksplisit, lalu periksa hasilnya sebagai contact sheet.
- **Regex saat menyuntik data ke mockup**: pola `const RECIPES = [...]...const BESTSELLERS` dengan `re.S` menelan
  `AUDIENCE` dan `words` yang kebetulan berada di antaranya. Pakai pola berjangkar baris (`^const RECIPES = \[\n.*?^\]`
  dengan `re.M|re.S`), dan selalu assert sebelum menulis.
- **CSP Artifact memblokir gambar lintas domain**, jadi label CONTOH tidak akan pernah terlihat di pratinjau Artifact
  meski URL-nya benar.

## Konvensi
- Satu file HTML mandiri, CSS & JS inline, tanpa framework. Gambar relatif `img/...`.
- Bahasa Indonesia, nada santai & jujur; klaim produk tetap faktual. Frasa yang dipakai sekarang:
  **"resep foto AI siap pake"** (bukan lagi "siap tempel"), "Akses dikirim ke email setelah lunas",
  "Bayar aman via Mayar (QRIS, transfer bank, e-wallet)".
- **Tidak ada garansi.** Blok "Garansi Puas 7 Hari" beserta FAQ-nya sudah dihapus dan diganti label
  "Satisfaction Guarantee". Jangan hidupkan lagi janji garansi tanpa diminta.
- Tidak ada tombol "Chat admin" — `WA_NUMBER` beserta CSS dan handler-nya sudah dihapus.
- Selaras dengan brand app: logo "ResepFoto — Imagine Your Photo", aksen biru, tema terang.
- Semua desain dibangun sebagai CSS/SVG editable, bukan gambar tempel.

## Yang masih terbuka
1. **Ganti 16 foto `p21`–`p66` di halaman iklan** sebelum iklan berbayar jalan — lihat bagian hak cipta di atas.
2. Setelan Mayar (lihat di atas) — menunggu pemilik repo.
3. File gambar latar biru untuk CTA penutup; slot `img/bg-final.*` sudah siap.
4. Label "Satisfaction Guarantee" masih bahasa Inggris — belum diputuskan mau diindonesiakan atau tidak.
5. FAQ "Ini aplikasi atau apa?" masih memakai frasa "resep prompt siap tempel" — belum diseragamkan jadi "siap pake".
6. ~~Auto-WhatsApp setelah pembayaran~~ — sudah beres di `main` lewat Fonnte (`sendAccessWa()`, kolom `orders.wa_sent`).
7. ~~Pindah dari `mail()` ke SMTP~~ — sudah beres di `main`: SMTP `localhost:25`, plus WhatsApp otomatis via Fonnte.
8. ~~Hapus `landing/index.html`~~ — **jangan**. Itu halaman yang tayang di `/promo`; yang dibangkitkan, bukan yang usang.
