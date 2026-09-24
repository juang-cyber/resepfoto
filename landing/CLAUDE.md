# Halaman iklan ResepFoto — panduan untuk Claude Code

> Claude Code membaca file ini otomatis saat bekerja di folder `landing/`, sebagai tambahan `CLAUDE.md` di root repo.
> Terakhir diperbarui: 24 September 2026.

## Ringkasan sepuluh detik
Desain dirawat di **`landing/mockup.html`**. Halaman yang tayang di **`resepfoto.kitlab.id/promo`** tidak diedit
langsung — ia **dibangkitkan** dari mockup oleh `landing/build-promo.mjs`. Jadi alurnya selalu:

```
landing/mockup.html  ──build-promo.mjs─┬─▶  landing/index.html  ──cron cPanel──▶  <docroot>/promo/
     (sumber desain)                    │   (jangan diedit tangan)      + landing/img/
                                        └─▶  app/promo-live/index.html  ──cron (app/.)──▶  <docroot>/promo-live/
                                             (selalu PRODUKSI, gambar dari /promo/img/)
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
| `../app/promo-live/index.html` | **Halaman yang tayang di `/promo-live`** — selalu build PRODUKSI, ikut dibangkitkan tiap `build-promo.mjs` jalan. Rujukan gambarnya `/promo/img/…`. Jangan diedit tangan |

Artifact presentasi (privat, bisa dibagikan): https://claude.ai/artifact/QdRiZFRSZvidkHEguipAqw

## Dua mode build
```bash
node landing/build-promo.mjs           # PRATINJAU (default)
node landing/build-promo.mjs --live    # PRODUKSI
```

Keduanya menulis **`landing/index.html`** dan menerapkan setelan produksi yang sama: `MOCKUP` jadi `false`, judul
produksi, **pembayaran Mayar aktif**, `TRACK_URL = "/api.php"` berikut blok pelacakan lengkap. `PREVIEW` hanya
dimatikan oleh `--live` — di build pratinjau ia tetap `true`, karena itulah yang menyalakan kartu CONTOH.
Gambarnya tidak disalin ke mana-mana — `landing/img/` sudah ikut diangkut cron apa adanya.

Setiap build **juga** menulis `app/promo-live/index.html` dalam mode PRODUKSI, apa pun modenya — isinya identik
dengan build `--live`, hanya `img/…` diganti `/promo/img/…`. Skripnya melempar error kalau masih ada rujukan
`img/` yang tertinggal. Jadi `/promo` boleh tetap demo, dan iklan diarahkan ke `/promo-live/`.

Bedanya **semua bukti sosial**. Pratinjau = halaman DEMO yang terang-terangan mengaku contoh; `--live` = halaman
asli yang hanya boleh memakai data sungguhan:

| | Pratinjau (default) | `--live` |
|---|---|---|
| Notifikasi pesanan | 10 nama CONTOH (`DEMO_NAMES`) | data asli `api.php?a=recent_orders` (pesanan + keranjang) |
| Penghitung pengunjung | tetap **"45 orang sedang melihat halaman ini"** | angka asli `api.php?a=live&page=promo`, ambang 2/2 |
| `TESTIMONIALS` (5 kartu contoh) | tetap tampil | dikosongkan, bagiannya disembunyikan |
| `RATING` (4,9 · 483 ulasan) | tetap tampil | jadi `null` |
| Label pojok **CONTOH** | tetap tampil | markup, CSS, dan skripnya dibuang |
| Pita **"Harga launching — harga akan naik dalam 3 hari"** | tetap tampil | markup & CSS-nya dibuang |

**Pita "naik dalam 3 hari" sengaja hanya di pratinjau** (23 Sep 2026). Pemilik memintanya untuk halaman demo
yang diuji bersama partner. Tenggatnya tidak terikat tanggal dan belum tentu dijalankan, jadi di halaman yang
menarik uang ia menjadi klaim harga yang menyesatkan — risiko akun iklan Meta dan UU Perlindungan Konsumen
Pasal 10. Karena itu `--live` membuangnya bersama data karangan lain, dan skrip build gagal keras kalau masih
tersisa. Kalau pemilik ingin tenggat di produksi, pasang **tanggal yang benar-benar akan ditepati** (atau
kuota sungguhan lewat kupon Mayar), jangan pindahkan pita ini apa adanya ke build `--live`.

Blok **"Cuma tambah Rp 10.000, Premium sudah dapat…"** di bawah kartu paket tampil di **kedua** mode, karena
isinya benar. Angka selisihnya diisi skrip dari `PLANS` (kelas `.js-diff`) — baris lama "Beda Rp 30.000"
yang ditulis tangan sempat basi begitu harga berubah. Isi daftarnya wajib mengikuti tabel "Bandingkan paket".

**Yang sekarang ter-commit di `landing/index.html` adalah mode pratinjau** (17 September 2026, atas permintaan
pemilik repo — mereka ingin halaman terlihat ramai untuk demo). Jadi `/promo` saat ini adalah **halaman demo**:
angkanya karangan, namanya karangan, dan yang menandainya adalah **label pojok CONTOH di kanan bawah** — satu label
untuk seluruh halaman, bukan label per angka. Itu keputusan pemilik repo (17 Sep 2026): labelnya sudah cukup
kelihatan, jadi jangan pasang chip lagi di ulasan, penghitung pengunjung, atau kartu keranjang/pembelian.
Pembayaran Mayar tetap hidup sungguhan supaya bisa dites.

> **Iklan diarahkan ke `/promo-live/`, bukan ke halaman ini** — `/promo-live` selalu build PRODUKSI (24 Sep 2026).
> Kalau iklan tetap mau memakai `/promo`, WAJIB `node landing/build-promo.mjs --live` lalu push dulu. Tanpa itu,
> halaman berbayar akan memasang nama pembeli dan jumlah penonton yang tidak pernah ada.

## Pengaturan di `mockup.html` (blok `/* ---- ubah di sini ---- */`, sekitar baris 831)
| Konstanta | Nilai sekarang | Arti |
|---|---|---|
| `CHECKOUT.standard` / `.premium` | `https://kitlab.myr.id/pl/resepfoto-standard` / `-premium` | Link pembayaran Mayar |
| `PLANS` | Standard `39900` (coret `150000`) · Premium `49900` (coret `299000`) — sejak 23 Sep 2026, sebelumnya 49900 / 79900 | Harga tampil. **Harus sama** dengan harga di Mayar |
| `PREVIEW` | `true` | Data pratinjau berlabel. Di-`false`-kan oleh skrip build |
| `MOCKUP` | `true` | Pembayaran nonaktif + banner "versi presentasi". Di-`false`-kan oleh skrip build |
| `TESTIMONIALS` | 5 kartu ILUSTRASI | Dibuang oleh `--live` |
| `RATING` | `{avg: 4.9, count: 483}` ILUSTRASI | Dibuang oleh `--live` |
| `ORDER_FEED_URL` | `""` | Diisi `/api.php?a=recent_orders` oleh skrip build (pesanan + keranjang) |
| `LIVE_VIEWERS` | `null` | Diganti panggilan `api.php?a=live&page=promo` asli oleh skrip build |
| `EXAMPLES` | 6 pasang `rbN`/`raN` | Before/after hero, dari foto member asli |
| `RECIPES` | 100 resep | Katalog asli, hasil sinkron dari `api.php?a=prompts` |
| `BESTSELLERS` | 14 id | Resep bertanda `popular`; urutannya **diacak Fisher-Yates tiap halaman dibuka** |
| `WALL`, `AUDIENCE` | 9 + 8 id | "Hasil dari resep kami" dan "Cocok untuk siapa" |

43 resep dipakai di halaman **tanpa satu pun berulang antar bagian** — itu disengaja, jaga kalau menambah bagian baru.

## Aturan bukti sosial (WAJIB — jangan dilanggar)
- **Jangan pernah mengarang** testimoni, rating, jumlah pembeli, notifikasi pesanan, atau jumlah "sedang melihat".
  Semua itu hanya boleh diisi dari **data asli**.
- Data ilustrasi hanya boleh hidup di `mockup.html` dan di build **pratinjau**, dan wajib tetap **berlabel jelas**
  lewat label pojok CONTOH di kanan bawah. Itu sebabnya mode pratinjau ada: supaya halaman contoh tidak pernah
  menyamar jadi halaman asli. Konstanta `demoTag` sengaja dibiarkan string kosong — pernah dicoba merender chip
  "CONTOH" di tiap satuan data, tapi pemilik repo memintanya dibuang karena label pojoknya sudah cukup kelihatan.
  Kalau suatu saat perlu dihidupkan lagi, cukup isi `demoTag`; semua titik pemasangannya sudah ada.
- Nama karangan hidup di satu tempat saja, konstanta `DEMO_NAMES` (10 nama bergaya `And** *****`), dan angka
  pengunjung karangan dipatok **45**. Keduanya hanya dirender di cabang `MOCKUP` atau `PREVIEW`, jadi tidak pernah
  ikut ke build `--live`.
- Build `--live` mengosongkan ketiganya. Bagian yang datanya kosong **otomatis disembunyikan** — itu perilaku yang
  benar, jangan diisi angka palsu supaya "tidak kosong".
- Testimoni dengan foto/nama pembeli: harus ada izin tertulis sebelum dipasang. Nama disamarkan dengan pola
  huruf terakhir nama depan jadi `*`, nama belakang jadi `****` — mis. `Andik* ****`.
- Notifikasi memakai `recent_orders`, isinya dua sumber asli yang digabung dan diurutkan dari yang terbaru:
  **pesanan** dari tabel `orders` (14 hari, nama disamarkan → "B****** membeli Paket Premium") dan **keranjang**
  dari `lt_events` (24 jam, satu baris per pengunjung, benar-benar anonim → "Seseorang memasukkan Paket Premium ke
  keranjang"). Peristiwa `pay` dibedakan jadi "menuju pembayaran". Tidak ada nama, kota, atau identitas apa pun
  yang dikarang untuk baris keranjang — memang tidak ada datanya, jadi bunyinya "Seseorang".
- Penghitung "sedang melihat" memakai `presence` asli lewat `api.php?a=live&page=promo`: tampil apa adanya bila
  ≥ 2 orang aktif dalam 60 detik, kalau tidak jatuh ke jumlah pengunjung 24 jam bila ≥ 2. Ambangnya 2 dan bukan 1
  karena angka 1 itu pengunjung yang sedang membaca sendiri. **Angkanya tidak pernah dibulatkan naik atau diberi
  angka minimum** — kalau sepi, pil-nya disembunyikan.

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
- **Label CONTOH** → `https://img.glasspro.co.id/label-contoh.png` (sejak 23 Sep 2026), dipanggil lewat URL supaya
  pemilik repo bisa memperbarui desainnya tanpa menyentuh kode. Lebar tampil 104px. Fallback teksnya **hanya jalan
  kalau gambarnya gagal dimuat** — gambar yang termuat tapi transparan tidak memicunya, dan labelnya lenyap tanpa
  tanda. Jadi tiap kali filenya diganti, periksa bahwa isinya benar-benar terlihat, jangan cuma HTTP 200.
  Dipindah dari `ik.imagekit.io/oziera/contoh.png` karena dua temuan 23 Sep 2026: jaringan Indosat memblokir
  `ik.imagekit.io` (DNS-nya dibelokkan ke halaman Internet Positif, jadi gambarnya tak pernah termuat dan yang
  tampil selalu fallback teks), dan filenya di ImageKit ternyata transparan (alpha maksimum 3/255), jadi pengunjung
  yang tidak diblokir tidak melihat label sama sekali. Saat alamatnya diganti, file di alamat baru masih kosong yang
  sama; pemilik akan mengunggah ulang desainnya.
- **Latar CTA penutup** → skrip mencoba `img/bg-final.jpg`, `.png`, lalu `.webp`. Kalau tidak ada satu pun, hiasan
  CSS bergradien yang dipakai. Filenya **belum pernah diterima**; slotnya sudah siap.

## Pelacakan iklan (bekerja sama dengan `app/api.php`)
Halaman mengirim event ke `TRACK_URL + "?a=lt"` (POST JSON, tanpa CSRF): `view`, `cta`, `checkout`, `pay`, dan
`ping`. Pengunjung dikenali `vid` acak di localStorage; `sid` di sessionStorage; bot diabaikan server. Sumber
diambil dari `utm_source`/`utm_medium`/`utm_campaign`/`utm_content`, `fbclid`, atau domain referrer, disimpan 7 hari.
Semua event menandai `page: "promo"`. Laporan muncul di **Admin → Iklan** (khusus super admin). Batas 300
event/pengunjung/hari.

Link iklan (Meta, ChatGPT Ads) — pakai `/promo-live/` lengkap dengan garis miring, supaya tidak ada redirect:
```
https://resepfoto.kitlab.id/promo-live/?utm_source=facebook&utm_medium=paid&utm_campaign=NAMA&utm_content={{ad.name}}
```

### Meta Pixel
Terpisah dari pelacakan di atas, dan tujuannya beda: yang di atas melapor ke **kamu** (Admin → Iklan), pixel melapor
ke **Meta** supaya algoritma iklannya bisa dioptimalkan ke pembelian, dan supaya audiens retargeting/lookalike bisa
dibangun.

- **ID-nya tidak ditanam di halaman.** Halaman mengambilnya dari `api.php?a=pixel`, yang membaca kunci
  `meta_pixel_id` di tabel `settings`. Diisi dari **Admin → Iklan → Meta Pixel**. Satu Pixel ID berlaku untuk semua
  kampanye di akun iklan yang sama, jadi bikin kampanye baru **tidak** perlu menyentuh kode.
- **Kolom kosong = pixel mati total** — tidak ada permintaan ke `connect.facebook.net` sama sekali. Sudah diuji.
- Peristiwa yang dikirim, dipetakan dari `trk()` yang sudah ada: `PageView` + `ViewContent` saat halaman dibuka,
  `CTAClick` (custom) dari `cta`, `InitiateCheckout` dari `checkout`, `AddPaymentInfo` dari `pay`. `value` &
  `currency` diambil dari `PLANS`, jadi otomatis ikut kalau harga berubah. Tiap peristiwa membawa `eventID` unik.
- **`Purchase` dikirim oleh MAYAR, bukan oleh halaman atau server ini** (sejak 23 Sep 2026). Pembayaran terjadi di
  domain Mayar (`kitlab.myr.id`), jadi pixel halaman ini tidak pernah melihatnya. Yang dipakai adalah fitur bawaan
  Mayar: Pixel ID yang sama diisi di **tab TRACK kedua produk** (ResepFoto Standard & Premium), dan Access Token
  Conversions API di **Pengaturan → Kustomisasi → Server Side Tracking → Meta Tracking Token Access**. Mayar lalu
  mengirim Purchase dari servernya, bahkan kalau pembeli tidak membuka halaman terima kasih Mayar — penting, karena
  Redirect URL membawa pembeli langsung ke `terima-kasih.html` kita.
- **JANGAN membuat CAPI sendiri di `app/webhook-mayar.php`.** Sempat dirancang 23 Sep 2026 lalu dibatalkan, karena:
  (1) Meta mewajibkan `client_user_agent` untuk event website dan membuang/tidak memakai event tanpa itu — webhook
  datang dari server Mayar, jadi kita tidak pernah punya user agent pembeli, sedangkan checkout Mayar punya;
  (2) dua pengirim Purchase untuk pembayaran yang sama = terhitung dua kali, karena event_id kita dan Mayar tidak
  pernah sama. Webhook Mayar memang membawa `pixelFbp`/`pixelFbc`, tapi tetap tanpa user agent.
- Access Token Conversions API hanya hidup di dashboard Mayar — **tidak** di repo, `settings`, atau
  `api.php?a=pixel`. Menurut Mayar token itu **kedaluwarsa setelah 60 hari**: kalau Purchase berhenti muncul di
  Events Manager, buat token baru di Events Manager → pixel → Settings → Conversions API, lalu tempel ulang di Mayar.
- Pixel itu pelacak pihak ketiga. Kebijakan Meta dan UU PDP minta pemberitahuan ke pengunjung; `/promo` belum punya
  halaman kebijakan privasi.

## Pembayaran Mayar — setelan dashboard
Kode sudah siap dan sudah diuji. Setelan dashboard **sudah terpasang 17 Sep 2026**; daftar ini untuk pengecekan ulang
kalau produk atau harga diganti:
1. Harga produk: Standard `39900`, Premium `49900` (sejak 23 Sep 2026).
2. **Nama produk wajib memuat kata "Standard" / "Premium"** — mis. `ResepFoto Standard`, `ResepFoto Premium`.
   Slug URL tidak dibaca.
3. Webhook → `https://resepfoto.kitlab.id/webhook-mayar.php`.
4. Webhook Token dari Mayar → tempel di **Admin → Pesanan**. Tanpa token, pesanan masuk tapi tidak aktif otomatis.
5. **Redirect URL** tiap link bayar → `https://resepfoto.kitlab.id/terima-kasih.html`. Sampai 23 Sep 2026 isinya
   masih `resepfoto.oziera.co.id/…` — domain lama yang sudah tidak ada (NXDOMAIN), jadi sejak pindah domain
   18 Sep pembeli mendarat di halaman error setelah bayar. **Kalau domain pindah lagi, ganti ini juga.**
6. **Meta Pixel ID** di tab TRACK kedua produk, dan token **Server Side Tracking** di Pengaturan → Kustomisasi —
   lihat bagian Meta Pixel di atas. Tokennya kedaluwarsa tiap 60 hari.

**KOREKSI PENTING — jangan diulangi.** Ambang `Rp 90.000` di `planFromProduct()` (`app/lib.php`) **bukan masalah**
untuk Premium seharga Rp 49.900 (dulu 79.900 — sama saja). Fungsi itu memeriksa nama produk lebih dulu; nominal cuma cadangan kalau nama
produk tidak memuat kata "premium" atau "standard":
```php
if (strpos($p, 'premium') !== false) return 'Premium';
if (strpos($p, 'standard') !== false || strpos($p, 'standar') !== false) return 'Standard';
return $amount >= 90000 ? 'Premium' : 'Standard';   // hanya cadangan
```
Sudah dibuktikan dengan uji lokal (lihat bagian Pengujian). Tidak ada perubahan kode yang diperlukan.

## Go-live checklist
1. Arahkan iklan ke **`/promo-live/`** — build PRODUKSI yang ikut dibangkitkan tiap build: tanpa 10 nama karangan,
   angka "45 orang sedang melihat", testimoni/rating karangan, pita "harga naik dalam 3 hari", dan label CONTOH.
   `/promo` sendiri masih build pratinjau (halaman demo); kalau iklan mau ke `/promo`, jalankan
   `node landing/build-promo.mjs --live` dulu. Bangun ulang tiap kali `mockup.html` diubah — dua halaman ikut.
2. Cek `CHECKOUT` & `PLANS` cocok dengan produk di Mayar, termasuk **nama produknya**.
3. Webhook Mayar terdaftar dan Webhook Token sudah diisi di Admin → Pesanan.
4. Commit & push ke `main`; tunggu sampai 15 menit (cron `*/15`), halaman tayang di `/promo` dan `/promo-live`.
   Verifikasi: `curl -sI https://resepfoto.kitlab.id/promo-live/`.
5. Tes: buka `/promo?utm_source=test&utm_campaign=cek`, klik CTA & checkout, pastikan angkanya muncul di
   **Admin → Iklan**. Pelacakan jalan di kedua mode. Di build `--live`, klik checkout juga membuat kartu
   "Seseorang memasukkan Paket … ke keranjang" muncul di kunjungan berikutnya — itu peristiwamu sendiri, bukan
   karangan. Di build pratinjau kartunya tetap nama CONTOH, jadi jangan dipakai untuk memverifikasi umpan asli.
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

## Environment agent remote: yang diblokir
Berlaku untuk sesi Claude Code **remote/cloud** saja. Dari sesi lokal di PC pemilik (browser pane) semua host di bawah
bisa dijangkau — dashboard Mayar, Fonnte, dan cPanel sudah pernah dikerjakan dari sana. Sesi remote **tidak bisa**
menjangkau host berikut (403 pada CONNECT / HTTP 000). Jangan buang waktu mencoba, dan jangan menjanjikan bisa
mengerjakannya:

| Host | Akibatnya |
|---|---|
| `resepfoto.kitlab.id` | Tidak bisa membaca database/katalog live. Data harus dikirim pemilik repo sebagai lampiran file |
| `kitlab.myr.id`, `mayar.id`, `web.mayar.id` | Tidak bisa menyetel produk/webhook Mayar sama sekali |

Yang **bisa** dijangkau: `github.com`, `raw.githubusercontent.com`, `fonts.googleapis.com`, registry npm & pypi,
serta Google Drive lewat konektor. Itu jalur transfer file yang berhasil dipakai selama ini.

Catatan penting soal lampiran: **gambar yang ditempel langsung di chat tidak tersimpan sebagai file.** Hanya
lampiran file sungguhan yang mendarat di `/root/.claude/uploads/`. Kalau butuh file dari pemilik repo, minta
dilampirkan sebagai file atau lewat Drive.

## Gotcha yang pernah terjadi
- **Build gagal `viewers: pola tidak ketemu` di Windows** (17 Sep 2026): checkout dengan `core.autocrlf=true`
  mengubah LF→CRLF, sedangkan pola regex skrip memakai `\n`. Sekarang skrip menormalkan ke LF saat membaca dan
  `.gitattributes` memaksa LF di working tree. Kalau muncul lagi: `git config core.autocrlf` lalu
  `git add --renormalize .`. Jangan pernah menyunting `landing/index.html` tangan untuk mengakalinya.
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
0. ~~Harga produk di Mayar diubah ke Standard `39900` / Premium `49900`~~ — sudah beres 23 Sep 2026, dari sesi
   lokal, sebelum PR #11 di-merge. Nama produk tidak diganti.
1. **Ganti 16 foto `p21`–`p66` di halaman iklan** sebelum iklan berbayar jalan — lihat bagian hak cipta di atas.
2. ~~Setelan Mayar~~ — sudah beres 17 Sep 2026: harga 49.900 / 79.900, webhook terdaftar, token terverifikasi
   ("token cocok" di Riwayat webhook).
3. File gambar latar biru untuk CTA penutup; slot `img/bg-final.*` sudah siap.
4. Label "Satisfaction Guarantee" masih bahasa Inggris — belum diputuskan mau diindonesiakan atau tidak.
5. FAQ "Ini aplikasi atau apa?" masih memakai frasa "resep prompt siap tempel" — belum diseragamkan jadi "siap pake".
6. ~~Auto-WhatsApp setelah pembayaran~~ — sudah beres di `main` lewat Fonnte (`sendAccessWa()`, kolom `orders.wa_sent`).
7. ~~Pindah dari `mail()` ke SMTP~~ — sudah beres di `main`: SMTP `localhost:25`, plus WhatsApp otomatis via Fonnte.
8. ~~Hapus `landing/index.html`~~ — **jangan**. Itu halaman yang tayang di `/promo`; yang dibangkitkan, bukan yang usang.
