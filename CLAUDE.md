# ResepFoto — panduan proyek untuk Claude Code

> Baca file ini dulu sebelum mengubah apa pun. Detail privat (hosting, lokasi kredensial) ada di `CLAUDE.local.md`
> (tidak di-commit). **Halaman iklan `/promo` dan `/promo-live`: baca `landing/CLAUDE.md`.**
> Terakhir diperbarui: 24 September 2026.

## Apa ini
**ResepFoto** (resepfoto.kitlab.id) — web app berbayar berisi "resep" prompt foto AI yang disalin member ke
Gemini/ChatGPT bersama foto mereka. Bisnis sampingan Juang Mahmud H (Glass Pro Indonesia) bersama Hendrick Kurnia.
Tagline: *Imagine Your Photo*. Bilingual ID/EN. **Produk ini akan dijual** — jaga kualitas kode, keamanan, dan jangan
pernah commit rahasia.

## Struktur repo
```
app/            ← yang di-deploy ke document root website
  index.html    UI member + panel admin (CSS & JS inline, i18n ID/EN, ~160 KB)
  api.php       semua endpoint JSON (sesi, CSRF, routing switch)
  lib.php       SQLite (db() + migrasi otomatis), member, pesanan, email
  ai.php        integrasi Gemini (link referensi, analisis, OCR, tes generate)
  xlsx.php      penulis & pembaca .xlsx tanpa pustaka luar (dipakai tab Konten)
  admin-ext.js      tab Pesanan (Mayar)                   [super admin]
  admin-ai.js       tab AI (key & model Gemini + DeepSeek, mesin utama, log)   [super admin]
  admin-reports.js  tab Pengguna & Iklan (laporan)        [super admin]
  admin-team.js     tab Admin (kelola akun admin)         [super admin]
  admin-cover.js    tab Cover (foto/teks halaman login)   [admin & super]
  admin-voucher.js  tab Voucher (buat kupon di Mayar + pembuat link) [super admin]
  admin-konten.js   tab Konten (ekspor/impor resep lewat Excel)     [super admin]
  webhook-mayar.php webhook pembayaran Mayar
  terima-kasih.html halaman sesudah bayar
  img/          foto contoh resep bawaan (p01…p66.jpg, 4:5) — ikut ter-deploy
  promo-live/   index.html = halaman iklan PRODUKSI di /promo-live. DIBANGKITKAN build-promo.mjs — jangan
                diedit tangan; gambarnya dirujuk ke /promo/img/, tidak punya salinan sendiri
  .htaccess     blokir file sensitif, paksa HTTPS, header keamanan, cache
  .autodeploy   PENANDA WAJIB — cron hanya men-deploy kalau file ini ada. Jangan dihapus
  config.example.php  template config (config.php asli TIDAK di repo)
  data/         seed-prompts.json, seed-en.json, pack2-prompts.json (database .sqlite TIDAK di repo)
  uploads/      hanya .htaccess (file upload TIDAK di repo)
brand/          logo, ikon, og-image
                og-image.jpg = desain kiriman pemilik (18 Sep 2026), 1200x630. JANGAN di-render ulang
                dari brand/source/og.html — file itu desain lama dan akan menimpa versi ini
landing/        index.html → di-deploy otomatis ke /promo (dibangkitkan dari mockup.html oleh
                build-promo.mjs — JANGAN diedit tangan); mockup.html = sumber desain, tidak ikut
                ter-deploy; img/ ikut ke /promo → baca landing/CLAUDE.md
deploy/         rf-deploy.sh lama (versi SSH) — referensi saja, yang aktif adalah cron di cPanel
```
`.gitignore` mengecualikan `app/config.php`, `app/data/*.sqlite*`, `app/data/*.log`, `app/uploads/*` (kecuali
`.htaccess`), dan `.DS_Store`.

## Alur deploy (sudah otomatis)
1. Edit file di `app/`. Untuk halaman iklan: edit `landing/mockup.html` lalu jalankan
   `node landing/build-promo.mjs` yang menulis ulang `landing/index.html` **dan** `app/promo-live/index.html`.
2. `git add -A && git commit -m "..." && git push` (branch `main`).
3. Cron di hosting (tiap 15 menit — `*/15`, dicek di cPanel 24 Sep 2026) menarik `origin/main`, dan **hanya jika commit berubah dan `app/.autodeploy` ada**, menyalin `app/.` ke document root **dan** `landing/index.html` + `landing/img/` ke `promo/`. `config.php`, database, `uploads/` tidak pernah tersentuh; `landing/mockup.html` sengaja tidak ikut.
4. Verifikasi: `curl -s https://resepfoto.kitlab.id/api.php?a=me` → lihat field `"v"`; landing: `curl -sI https://resepfoto.kitlab.id/promo/` dan `/promo-live/`.

Perintah cron sebenarnya ada di **cPanel → Cron Jobs**, bukan di `deploy/rf-deploy.sh` (itu sisa versi SSH lama
yang menyebut 2 menit tanpa guard `.autodeploy` — jangan dipercaya sebagai sumber kebenaran).

**Konvensi rilis:** tiap rilis naikkan penanda versi di route `me` (`'v' => 'admin-N'`) di `api.php`, dan naikkan `?v=`
pada `<script src="admin-*.js?v=N">` di `index.html` untuk file JS yang berubah. HTML/JS sudah `Cache-Control:
no-cache` lewat `.htaccess`, gambar di-cache 30 hari.

## Halaman iklan `/promo` dan `/promo-live`
Tayang di `resepfoto.kitlab.id/promo`, disajikan dari **`landing/index.html`** yang disalin cron.
**Sumber desainnya `landing/mockup.html`** — `landing/index.html` dibangkitkan, jangan diedit tangan:

```bash
node landing/build-promo.mjs           # PRATINJAU — halaman demo: nama contoh, "45 orang sedang melihat",
                                       #   testimoni & rating contoh, semuanya berlabel CONTOH
node landing/build-promo.mjs --live    # PRODUKSI — semua data karangan dibuang, diganti data asli;
                                       #   WAJIB sebelum dipasang di iklan
```

Yang ter-commit sekarang adalah **mode pratinjau** (17 Sep 2026): `/promo` sengaja jadi **halaman demo** — 10 nama
karangan di notifikasi, "45 orang sedang melihat", testimoni & rating contoh. Penandanya **label pojok CONTOH di
kanan bawah**, satu untuk seluruh halaman — jangan tambah chip per angka, itu sudah dicoba dan diminta dibuang.
Pembayaran Mayar tetap hidup supaya bisa dites.
**Iklan diarahkan ke `/promo-live/`, bukan `/promo`** (lihat di bawah). Kalau suatu saat iklan memakai `/promo`, wajib
bangun ulang dengan `--live` dulu — itu yang membuang semua data karangan dan menggantinya dengan data asli. Detail lengkap, aturan bukti sosial, dan checklist go-live ada di
**`landing/CLAUDE.md`** — baca itu sebelum menyentuh apa pun soal iklan.

**`/promo-live` (sejak 24 Sep 2026)** — setiap `node landing/build-promo.mjs` juga menulis
`app/promo-live/index.html` dalam mode **PRODUKSI**, apa pun mode `/promo`. Isinya sama persis dengan build
`--live`; bedanya hanya rujukan gambar `img/…` diarahkan ke `/promo/img/` (satu salinan gambar untuk dua halaman).
Cron menyalinnya bersama `app/.`. Jadi `/promo` boleh tetap halaman demo, sementara **iklan (Meta, ChatGPT Ads)
diarahkan ke `https://resepfoto.kitlab.id/promo-live/`**. Pelacakannya tetap `page: "promo"`, jadi Admin → Iklan dan
umpan keranjang asli menggabungkan kedua halaman — bedakan lewat UTM.

## Etalase internasional (imagine.kitlab.id) — Tahap 1, 26 Sep 2026
Satu folder, satu kode, **satu database** untuk semua etalase. Domain `imagine.kitlab.id` dibuat di cPanel
(Domains → Create A New Domain) dengan document root **`/resepfoto.kitlab.id`** — folder yang sama, jadi cron deploy
tidak perlu diubah. DNS kitlab.id ada di nameserver Jagoan, SSL memakai sertifikat AutoSSL `*.kitlab.id`.
- **`.htaccess`**: host `imagine.*` → `/` dan `/index.html` dilayani **`app/site.php`**, `/site.webmanifest` →
  `site.php?f=manifest`, dan `/promo`, `/promo-live` dialihkan ke `/` (landing masih berbahasa Indonesia).
- **`site.php`** membaca `index.html`, mengganti blok di antara `<!--site:head-->…<!--/site:head-->` dengan meta
  English, dan memasang `<html lang="en" data-site="imagine" data-langs="en" data-brand="Imagine" data-title=…>`.
  Host lain → `index.html` apa adanya (diuji byte-per-byte). **Jangan hapus penanda site:head.**
- **`index.html`** membaca atribut itu jadi `SITE`. Kalau `id` tidak ada di `data-langs` (`HAS_ID = false`):
  bahasa dikunci English, `t()` tidak jatuh ke kamus Indonesia, `loc()` tidak jatuh ke kolom Indonesia, pilihan
  bahasa & tautan /promo disembunyikan (`applySite()`), "ResepFoto" di kamus diganti nama brand, logo memakai
  simbol `#im-lockup` / `#im-lockup-t`, dan **hanya resep `enReady`** yang dimuat (judul EN ada, dan prompt-nya
  tidak berbahasa Indonesia atau sudah punya `prompt_en`). Teks khusus Indonesia per etalase ada di `SITE_TEXT`.
- **Akun berlaku di semua etalase** (keputusan pemilik): tabel `members` dipakai bersama, login sama. Catatan:
  aturan satu-sesi berarti login di imagine pada perangkat yang sama menendang sesi di resepfoto.
- **`prompts.prompt_en`** (kolom baru): prompt versi Inggris, diisi **hanya** kalau prompt utama berbahasa
  Indonesia (`looksIndonesian()` di `lib.php`). Prompt utama tidak pernah diubah, jadi ResepFoto tetap sama.
  API mengirim `promptEn` + `promptId`; tampilan English (termasuk ResepFoto mode EN) memakai `promptOf()`.
  `prompt_save` mempertahankan `prompt_en` lama kalau klien tidak mengirimnya (INSERT OR REPLACE menulis ulang baris).
  Excel ekspor/impor **belum** memuat `prompt_en` (impor tidak menyentuhnya).
- **Admin → AI → kartu "Versi English"**: `en_status` (hitungan) + `en_fill` (4 resep per putaran, diulang panel).
  Mengisi HANYA kolom EN yang kosong (judul, deskripsi, tips, kategori, `prompt_en`); kategori dikenal diisi dari
  `CAT_EN_MAP` tanpa AI; id karangan AI diabaikan. Dicek dengan DeepSeek asli: 9 prompt Indonesia di data lokal
  diterjemahkan setia (instruksi, urutan, bahkan huruf kapital terjaga) dalam 3 putaran.
- **Belum**: pembayaran & landing EN (sengaja ditunda pemilik), og:image English, Excel `prompt_en`.
- **Tahap 2 (rencana)**: `imagine.kitlab.id/th` — bahasa Thai bawaan + EN, kolom `*_th`, kamus TH, terjemahan AI
  dengan cek penutur Thai. Pemilik minta kualitas Thai-nya benar-benar bagus.

## Pembayaran Mayar
Alur: pembeli klik paket di `/promo` → checkout Mayar → Mayar POST ke `app/webhook-mayar.php` → `fulfillOrder()`
membuat member, mengirim email akses ke pembeli, dan email notifikasi ke admin.

Paket ditentukan `planFromProduct()` di `app/lib.php`. **KOREKSI PENTING — jangan diulangi:** ambang `Rp 90.000`
di fungsi itu **bukan masalah** untuk Premium seharga Rp 49.900 (dulu 79.900), karena nama produk diperiksa lebih dulu:
```php
if (strpos($p, 'premium') !== false) return 'Premium';
if (strpos($p, 'standard') !== false || strpos($p, 'standar') !== false) return 'Standard';
return $amount >= 90000 ? 'Premium' : 'Standard';   // hanya cadangan
```
Sudah dibuktikan dengan uji lokal ujung ke ujung (`ResepFoto Premium` @ 79900 → Premium; `Akses Selamanya ResepFoto`
@ 79900 → Standard). Yang perlu dipastikan hanya **nama produk di dashboard Mayar memuat kata "Standard"/"Premium"** —
slug URL tidak dibaca. Tidak ada perubahan kode yang diperlukan.

Webhook memverifikasi `mayar_webhook_token` dengan `hash_equals` terhadap header / `$_GET['token']` / `$j['token']`.
Tanpa token cocok, pesanan tetap tercatat tapi hanya `notifyAdmin()` yang jalan — aktivasi manual lewat Admin → Pesanan.

## Arsitektur
- **Backend:** PHP — **tulis kompatibel PHP 7.4** (tanpa `match`, `str_starts_with`, enum, readonly, dsb.) meski
  server sekarang PHP 8.x. SQLite lewat PDO (`lib.php::db()`), WAL, migrasi kolom otomatis di `db()`
  (cek `PRAGMA table_info` lalu `ALTER TABLE`).
- **Auth:** sesi PHP (`rf_sess`, httponly, SameSite=Lax, 30 hari). Login pakai username + kode akses (bcrypt).
  Rate limit 10 percobaan/15 menit per IP. Semua POST wajib header `X-CSRF` (nilai dari `me`). Header `X-Lang: id|en`
  menentukan bahasa pesan error (`tr()` di `api.php`).
- **Peran:**
  - `super_admin` — akun `admin` bawaan (hash di `config.php`, bisa di-override setting `admin_hash` lewat UI) +
    member dengan `role='super_admin'`. Semua tab & endpoint.
  - `admin` — member dengan `role='admin'`. Hanya tab **Resep, Member, Cover** (tab lain disembunyikan via
    `superOnly`, dan endpoint-nya ditolak server dengan `requireSuperAdmin()`).
  - `member` — lihat/salin resep, atur foto profil sendiri. **Jatah katalog per paket** ada di `lib.php`
    (`planQuota()`, `allowedPromptIds()`, `freezeStandardIds()`):
    - `Premium` dan paket lain: **bebas**, semua resep termasuk yang ditambahkan kemudian.
    - `Standard` **pembeli baru** (sejak 18 Sep 2026): koleksinya **dibekukan saat mendaftar** dan dikunci
      selamanya — `STANDARD_CAP` = 100 resep, komposisi `STANDARD_MIX` = 7 best seller + 5 Tren Viral
      (keduanya diambil dari resep yang **terakhir diunggah** saat itu) + sisanya resep reguler **acak**.
      Daftar id-nya disimpan di `members.allow_ids` (JSON) dan **tidak pernah dihitung ulang**: koleksinya tidak
      bertambah dan tidak berkurang, jadi resep baru — termasuk tren viral baru — hanya mengalir ke Premium.
      Acak + beku memang tidak bisa dihitung ulang tiap permintaan; itu sebabnya harus disimpan.
    - `Standard` **member lama** (`allow_ids` NULL): tetap memakai aturan sebelumnya (10 best seller +
      10 Tren Viral + semua reguler). Sengaja tidak diubah supaya akses yang sudah dibeli tidak berkurang.
    - `Trial`: 3 best seller + 1 Tren Viral + 6 reguler, dihitung dinamis (gratis, tidak perlu dibekukan).
    - Naik ke Premium lewat `fulfillOrder()` **melepas** `plan_cap` dan `allow_ids`.
    - **Jangan** menghitung ulang jatah Standard dari katalog terkini. Kalau jatah dibatasi lalu dipilih dengan
      `pickStable()` ber-seed, setiap resep baru ikut diundi ulang dan bisa MENGGESER resep yang sudah dimiliki
      member — akses yang kemarin ada, besok hilang. Itu pernah terjadi dan ditangkap uji regresi di
      `test/uji-pesan-voucher.sh`.
    Resep di luar jatah tetap tampil sebagai thumbnail bertanda gembok, tapi `prompt`/`tips` **tidak pernah
    dikirim** ke klien — dikosongkan di `rowToPrompt()`. Pemilihannya deterministik (jatah kurasi dari `ord`,
    jatah acak Trial di-seed username) supaya katalog tidak berubah tiap halaman dimuat.
  - **"Tren viral" = KATEGORI `Tren Viral` (`VIRAL_CAT`), bukan tanda terpisah** seperti `popular`. Ember jatah di
    atas memakai `cat === VIRAL_CAT`, termasuk jatah dinamis member Standard lama (10 Tren Viral + semua reguler).
    Checkbox "Tren viral" di studio (26 Sep 2026) karena itu hanya **jalan pintas kategori**: dicentang → kategori
    jadi Tren Viral (kategori sebelumnya diingat), dilepas → kembali. **Jangan** mengubahnya jadi kolom/flag
    terpisah tanpa menghitung ulang aturan jatah: resep reguler yang ditandai viral akan pindah ember dan bisa
    MENGHILANGKAN akses member Standard lama.
  - `currentUser()` mengembalikan `role` (`admin`|`member`) + `adminRole` (`super_admin`|`admin`|``).
- **Frontend:** SPA vanilla JS di `index.html`. Modul admin terpisah menempel lewat `window.RFAPP` (`api, toast, esc,
  copyText, openSheet, closeSheet, isAdmin, fmtDate, state, renderAll, t, lang, setLang, addAdminTab, openPromptForm,
  cats, armDelete, imgSrc, applyCover`). Tab admin baru: `APP.addAdminTab({id, label, onShow, superOnly})` →
  mengembalikan panel; event `rf:admin-render` dipancarkan tiap render admin.
- **i18n:** kamus ID & EN di `index.html` (`t(key, vars)`), markup pakai `data-i18n`, `data-i18n-ph`, `data-i18n-aria`.
  Resep punya kolom `_en` (cat/title/descr/tips); kalau kosong tampil versi ID. Kategori diambil dinamis dari
  data (`cats()`); nama Inggrisnya dari `cat_en` tiap resep, dengan cadangan peta `CAT_EN`. Saat ini 9:
  Foto Jadul, Jalan-jalan, Keluarga, Momen Spesial, Profesional, Tren Viral, Editorial, Gaya Jalanan,
  Kartun & Ilustrasi.
- **Gambar:** semua upload divalidasi `getimagesize` lalu **di-encode ulang via GD** (resep 4:5 960×1200, avatar 1:1
  400×400, tes maks 1600px). Path disimpan relatif `uploads/xxx.jpg`. Hapus lewat `delUpload()`. Foto contoh bawaan ada di `app/img/`
  (`img/pNN.jpg`, ikut repo); `imgSrc()` hanya menerima pola `^(img|uploads)/[\w.-]+$`.
- **AI (Gemini):** `ai.php`. Model teks `gemini-3.8-flash` (default, bisa diganti di setting), model gambar
  `gemini-3.1-flash-image`. Fungsi: `recipeFromUpload()`, `recipeFromImagePrompt()` (OCR prompt dari screenshot),
  `generateTestImage()`. Mode **link referensi** (share link Gemini/ChatGPT) **sudah dihapus** (17 Sep 2026) —
  hampir tidak pernah dipakai; jangan dikira sama dengan mode Link Instagram di bawah. Error aman-ditampilkan
  dilempar sebagai `RfError` → HTTP 422. Log ke tabel `ai_log` (maks 300 baris; baris DeepSeek diberi catatan `DeepSeek …`).
- **AI (DeepSeek) + mode Link Instagram (26 Sep 2026):** studio resep punya mode ketiga — admin menempel link
  postingan Instagram (`/p/`, `/reel/`, `/tv/`, boleh ada `?utm…&stkn=…`), endpoint `ai_reference` →
  `recipeFromInstagram()` mengisi SEMUA kolom. Alurnya:
  1. `instagramPost()` meminta halaman postingan dengan UA **Googlebot**, cadangannya **facebookexternalhit**.
     Browser biasa dilempar ke halaman login, tapi crawler diberi data postingan lengkap di
     `<script type="application/json">` (objek ber-`code` = kode postingan, berisi `caption.text`, `user.username`,
     `carousel_media[].image_versions2.candidates[]`). Kalau data itu tidak ada, jatuh ke tag `og:` (caption bisa
     terpotong, gambar hanya slide pertama). **Cara ini tidak resmi** — kalau Instagram mengubahnya, pesan errornya
     mengarahkan admin ke mode "Prompt dari gambar". Jalur resminya (belum dibuat) Instagram Graph API
     Business Discovery, yang butuh app Meta + token akun IG bisnis.
  2. **Komentar TIDAK bisa diambil** tanpa login (`/api/v1/media/{pk}/comments/` dan `/comments/` → 302 ke login,
     dicek 26 Sep 2026). Karena itu ada kolom "Teks tambahan" untuk menempel komentar yang berisi prompt.
  3. `igDownload()` mengunduh maks 10 slide ke `uploads/tmp_*.jpg`, **hanya** dari host `*.cdninstagram.com` /
     `*.fbcdn.net` lewat https (URL dari halaman orang lain = masukan tak tepercaya; jangan longgarkan).
  4. `aiVisionJson()` mengirim instruksi + caption + slide berlabel `SLIDE n` ke mesin utama (`refEngine()`,
     setting `ai_ref_engine`, bawaan **DeepSeek**); kalau gagal atau key-nya kosong, otomatis mesin satunya.
     DeepSeek: format OpenAI `POST /chat/completions`, model bawaan **`deepseek-flash`** (bisa membaca gambar lewat
     `image_url` data URI), `thinking: {type: disabled}`, `response_format: json_object`. Dicek dengan postingan
     asli 26 Sep 2026: prompt yang tertulis di slide 5 terbaca kata per kata dalam ±4 detik (±6.300 token input
     untuk 6 slide).
  5. AI juga mengembalikan `promptSlide`, `exampleSlide` (slide terbaik sebagai contoh hasil), `promptSource`
     (`image|caption|extra`), dan `multiple` (jumlah prompt di postingan — yang diambil hanya satu).
  **Banyak link sekaligus (wizard):** kolom link menerima banyak link (satu per baris; duplikat disaring lewat
  kode postingan, maks 20). Semua dibaca di latar belakang, **2 sekaligus** (`WIZ_PARALLEL`), lalu direview satu
  per satu: bar nomor berwarna per status, Sebelumnya/Berikutnya/Lewati/Ambil ulang, dan tombol simpan jadi
  "Simpan & lanjut". Isian tiap link dipotret (`snapForm`/`restoreForm`) supaya pindah-pindah tidak menghilangkan
  suntingan. Token `wiz.run` membatalkan hasil lama saat wizard dihentikan/dimulai ulang. Menutup sheet membuang
  wizard (belum ada peringatan).
  Slide **tidak** langsung dipasang. Studio menampilkan kotak **review slide** (grid, bertanda "Saran AI" dan
  "Berisi prompt"), lalu admin memilih salah satu dari dua jalan:
  - **Pakai apa adanya** → slide jadi gambar contoh lewat jalur `image_temp`. Itu foto milik akun sumber
    (lihat backlog hak cipta).
  - **Generate versi sendiri** → endpoint `ai_thumb` → `generateThumbnail()` di model gambar Gemini
    (`imageConfig.aspectRatio = 4:5`; kalau model menolak `imageConfig`, diulang tanpa rasio — `cropTo45()` tetap
    merapikan saat disimpan). Sumber wajah dipilih admin: **Foto wajah kita** (bawaan; slide hanya jadi acuan
    pose/cahaya/suasana, instruksinya melarang menyalin orang, teks, watermark) atau **Wajah dari slide**
    (keputusan pemilik 26 Sep 2026: boleh, tapi UI menampilkan peringatan izin). Foto wajah baru disimpan ke
    tabel **`faces`** (`uploads/f_*.jpg`) jadi pustaka wajah; daftar `faces` juga memuat foto input Tes generate
    lama (`prompt_tests.input_image`, tidak bisa dihapus dari sini). Foto yang ditempel/dipilih (boleh banyak
    sekaligus) langsung diunggah lewat `face_add`, tanpa perlu generate dulu.
    **Model bawaan** = 8 karakter **AI fiktif** di `app/img/models/` (`models.json` berisi file + label "AI · …"),
    dibuat 26 Sep 2026 dengan Gemini dan instruksi "orang fiktif, tidak mirip tokoh publik". `uploadPath()` hanya
    menerima `uploads/…` dan `img/models/…` sebagai wajah/referensi. **Aturan:** repo ini PUBLIK, jadi foto orang
    sungguhan (tim/model yang sudah setuju) masuk lewat upload ke server, **jangan pernah di-commit**. Wajah tokoh
    publik (presiden, menteri, artis) **tidak dipakai** sama sekali: UU Hak Cipta Pasal 12 melarang penggunaan
    komersial potret seseorang tanpa persetujuan tertulis, dan deepfake tokoh politik berisiko untuk akun iklan
    Meta. Permintaan itu pernah datang 26 Sep 2026 dan ditolak; yang dipakai sebagai gantinya model AI fiktif. Hasil generate = `uploads/tmp_*.jpg`, bisa
    diulang, dan dipilih dari grid hasil. Dicek dengan Gemini asli 26 Sep 2026: ±11 detik, 928×1152, watermark
    sumber hilang.

## Data
Tabel: `prompts, prompts_trash, members, attempts, settings, orders, webhook_log, ai_log, prompt_tests, faces, events,
lt_events, presence, ad_spend, vouchers`. Kolom `prompts.prompt_en` = prompt Inggris untuk etalase imagine.
Kolom penting `members`: `username, name, code_hash, code_hint, plan, expires, active, email, phone, role, avatar, session_token, plan_cap, allow_ids`.
Kolom penting `prompts`: `id, ord, cat, title, descr, popular, tools, prompt, tips, image, created_at,
updated_at, cat_en, title_en, descr_en, tips_en, created_by, qc_status, result_status, en_only`.
Kolom `vouchers`: `pct (PK), code, codes, note, active, quota, expires, kind, mayar_id, mayar_ids, synced_at, updated_at` —
`codes` adalah JSON daftar semua alias satu tingkat dan `mayar_ids` JSON daftar id diskonnya (satu diskon per
alias) dan `used` jumlah pemakaian (-1 = belum pernah dibaca dari Mayar);
`code`/`mayar_id` tetap ada sebagai yang utama, dipakai baris lama —
`mayar_id` terisi hanya untuk kupon yang dibuat lewat API, dan itulah pembeda "dibuat di Mayar" vs "sekadar dicatat".
Kolom `orders` untuk pengiriman: `emailed, wa_sent, email_err, wa_err, reminded_at, reminder_count` —
dua kolom `*_err` menyimpan sebab kegagalan tiap kanal, dan kosong berarti kanal itu berhasil.

`qc_status` = `''|lolos|review|gagal`, `result_status` = `''|cocok|kurang` — dipakai menyaring di panel admin,
tidak pernah tampil ke member. Resep yang dihapus pindah ke tabel **`prompts_trash`** (barisnya disimpan utuh
sebagai JSON); gambar dan galeri tesnya baru benar-benar dibuang saat sampah dikosongkan.
Kunci `settings`: `admin_hash, admin_avatar, admin_email, mail_from, mayar_webhook_token, auto_without_token, mayar_coupon_shape, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, fonnte_token, admin_wa, meta_pixel_id, mayar_api_key, gemini_api_key, gemini_model, gemini_image_model, deepseek_api_key, deepseek_model, ai_ref_engine, cover_title, cover_sub, cover_title_en, cover_sub_en, cover_chip, cover_img1..3, msg_paid_subject, msg_paid, msg_pending_subject, msg_pending`.

**Satu template, tiga kanal.** Teks pesan ke pembeli ditulis SEKALI di Admin -> Pesanan -> "Teks pesan ke
pembeli", dengan format WhatsApp (`*tebal*`, `_miring_`, daftar bernomor). Dari satu template itu `lib.php`
menghasilkan tiga keluaran: `accessWa()` (apa adanya), `accessHtml()` (tanda formatnya jadi `<strong>`/`<em>`/`<ol>`,
dibungkus `emailShell()` berlogo), dan `accessMessage()` (tanda formatnya dibuang — dipakai email versi teks dan
tombol salin di panel). Trio yang sama ada untuk pengingat: `pendingWa/pendingHtml/pendingMessage`. **Jangan
membuat teks pesan langsung di JavaScript** — panel memakai field `message` dari server supaya tidak pernah beda.
Placeholder: `{nama} {nama_depan} {paket} {username} {kode} {situs} {nominal} {link_bayar}`.

**Email HTML.** `mailHeaders($from, $boundary)` memakai `multipart/alternative` kalau boundary diisi, dan
`mimeBody()` merakit bagian teks + HTML. `MAIL FROM` dan `-f` sengaja tidak disentuh — itu yang membuat SPF lolos.
Logo email: `app/brand/email-logo.png` (PNG, karena Gmail tidak merender SVG).

**Pengingat sebelum bayar — OTOMATIS sejak 19 Sep 2026.** Semua pengaman dan pencatatannya tinggal di satu
fungsi, `autoRemind()` di `lib.php`, yang dipakai **dua** pemanggil: tombol `remind` di `order_action` dan
event `payment.reminder` dari webhook Mayar. Satu fungsi supaya perilakunya tidak mungkin berbeda.
Pengamannya: maksimal 2 kali per pesanan, jeda minimal 24 jam, dan **penanda ditulis sebelum pengiriman**
(`orders.reminded_at`, `orders.reminder_count`) — itu yang membuat webhook kembar atau klik ganda tidak
menghasilkan dua pesan. Sekali jalan mengirim email **dan** WhatsApp.

Sebelumnya ini sengaja manual, dengan alasan event pengingat Mayar terpicu 29 menit sesudah checkout gagal dan
tidak bisa diatur, jadi otomatis dinilai berisiko spam. Pemilik mengubah keputusan itu: pembeli yang menunggu
tidak boleh bergantung pada admin yang kebetulan sedang membuka panel. Pengaman di atas yang menahan spamnya.
**Jangan kembalikan jadi manual tanpa membicarakannya.**

`webhook-mayar.php` karena itu melayani dua event, bukan satu. Perhatikan: event pengingat **tidak boleh**
dianggap lunas — `status` sering kosong di payload itu, sementara aturan "status kosong = lunas" berlaku untuk
`payment.received`. Variabel `$isRemind` yang memaksa `$paid = false`.

**Voucher — panel ini TIDAK memotong harga.** Kuponnya milik Mayar; potongan dan sisa kuota dihitung serta
ditegakkan di halaman pembayaran Mayar lewat parameter `?coupon=KODE` pada link. Pembayaran terjadi di domain
Mayar dan aplikasi ini baru tahu setelah webhook masuk, jadi kuota **tidak boleh** disimpan sebagai penentu di
sini. Tabel `vouchers` berisi **sepuluh template tingkat diskon** (10%..90% plus 95% untuk uji, di-seed
otomatis di `db()` dari konstanta `VOUCHER_TIERS`) — bukan daftar bebas. Kuncinya `pct`, bukan `code`; satu kode
hanya boleh menempel di satu tingkat.

**Tingkat dibaca dari DUA ANGKA TERAKHIR kode** (`voucherTierFromCode()` di `lib.php`, dan salinan aturan yang
sama di `admin-voucher.js`): `HEMAT30`, `DISKON30`, `PROMO30` semuanya tingkat 30%. Klien tidak menentukan
persennya — `pct` yang dikirim UI hanya dicocokkan, dan ditolak kalau berbeda. Kode yang tidak berakhiran salah
satu tingkat (mis. `HEMAT100`, `RF2026`) ditolak. Satu tingkat boleh punya **beberapa alias**, dan tiap alias
menjadi **satu diskon tersendiri di Mayar** — bukan satu diskon berisi banyak kode, karena bentuk yang diterima
Mayar hanya memuat satu kode per permintaan. Id-nya dikumpulkan di `vouchers.mayar_ids`, dan `voucher_sync`
membaca semuanya: tingkat dianggap aktif hanya kalau **semua** aliasnya aktif, karena satu alias mati sudah
cukup membuat link yang sudah beredar berhenti bekerja. Yang disimpan ke `vouchers.codes` adalah kode yang
**diakui Mayar lewat responsnya**, bukan yang kita kirim.

**KEJADIAN NYATA (19 Sep 2026) — jangan ulangi.** Tab Voucher menampilkan `HEMAT90` "aktif", tapi dashboard
Mayar → Diskon dan Kupon **kosong sama sekali**. Kodenya cuma dicatat lewat `voucher_save`, tidak pernah dibuat
di Mayar. Halaman bayar menolaknya dengan *"Gagal! Kode diskon ini tidak ditemukan."* dan harga tetap penuh —
jadi **pembelinya tahu**, yang tidak tahu justru panel kita: statusnya tetap "aktif". Ingat: **"aktif" di panel
hanya berarti "tercatat"**. Bukti bahwa sebuah kupon sungguh ada cuma dua: `diMayar: true` (punya `mayar_id`),
atau kelihatan di dashboard Mayar.

**Bentuk payload `POST /coupon/create` DICARI, bukan ditebak.** Dokumentasi Mayar bertentangan dengan dirinya
sendiri: contoh curl di <https://docs.mayar.id/api-reference/discount/create> menulis `discount` sebagai **objek**
dengan `coupon` (objek) dan `products` **sejajar** di tingkat atas, sementara daftar field di halaman yang sama
menyebut `discount` "array of object" dan menaruh `coupon` **di dalamnya**. Percobaan 19 Sep 2026 dengan bentuk
contoh curl + `coupon` array dijawab **"Validation Error"**.

Karena itu `mayarCreateCoupon()` mencoba empat bentuk berurutan (`MAYAR_SHAPES`) dan berhenti di yang pertama
diterima. Ini aman: permintaan yang **ditolak tidak membuat apa pun**, dan pencarian berhenti begitu satu
berhasil — jadi paling banyak satu diskon terbentuk. Bentuk yang menang disimpan di setting
**`mayar_coupon_shape`** supaya pembuatan berikutnya langsung tepat; **kosongkan setting itu kalau Mayar
mengubah API-nya**. Kalau sebuah permintaan dijawab 2xx tapi kodenya tidak ada di jawaban, prosesnya berhenti
saat itu juga dan melapor — diskon tanpa kode adalah sampah yang **tidak bisa dihapus**.

`products: []` berarti **berlaku untuk semua produk** — responsnya membalas `discountProductType: "all"`.
`mayarApi()` meneruskan sisa objek jawaban Mayar (maks 400 karakter) ke pesan error, karena tanpa itu satu
tebakan bentuk payload berarti satu siklus deploy penuh.

**KODE KUPON MAYAR UNIK LINTAS SELURUH MERCHANT, bukan hanya akun ini.** Terbukti 19 Sep 2026: `DISKON80`
ditolak *"Already used"* padahal belum pernah kita buat, sementara `RFHEMAT80` langsung lolos. Jadi kata umum
(`DISKON90`, `PROMO50`, `HEMAT10`) hampir pasti sudah diambil toko lain di platform yang sama. Pakai awalan
khas — **`RF`** — dan tetap akhiri dengan dua angka tingkat: `RFHEMAT90`, `RFDISKON90`, `RFPROMO90`.
`mayarCouponSebab()` menerjemahkan "Already used" jadi kalimat yang menyebutkan hal ini, karena pesan aslinya
menyesatkan: terdengar seperti kuotanya habis.

**Pembuatan kupon AMAN DIULANG.** Sebelum membuat, `mayarCreateCoupon()` mencari kampanye bernama sama lewat
`GET /hl/v2/coupons?search=` (`mayarFindCoupon()`); kalau ketemu, id-nya **diangkat** dan tidak ada diskon
kembar dibuat. Ini penting justru karena tidak ada endpoint hapus: diskon yang terlanjur terbentuk tapi gagal
tercatat di panel (koneksi putus, alias berikutnya ditolak) akan menggantung selamanya, dan satu-satunya jalan
pulang adalah mencarinya kembali **berdasarkan nama**. Karena itu `mayarCouponName()` (`ResepFoto {pct}% - {KODE}`)
tidak boleh diubah sembarangan — nama lama tidak akan ketemu lagi.

**Sebagian alias gagal itu normal, dan yang berhasil WAJIB tersimpan.** Kalau alias kedua ditolak setelah yang
pertama terbentuk, `mayarCouponGagal()` mengembalikan yang sudah jadi beserta `warning`, dan `voucher_create`
tetap menyimpannya lalu meneruskan peringatannya ke panel. Melempar error biasa di titik itu **menghilangkan id
diskon yang sudah terbentuk** — itu pernah terjadi 19 Sep 2026 dan meninggalkan dua diskon yatim.

**Jawaban daftar v2: `data` adalah LARIK LANGSUNG, bukan `data.coupons[]`.** Dokumentasi menjanjikan
`{"data":{"coupons":[...]}}`; server sungguhan mengirim `{"data":[...]}`. Bedanya mematikan karena gagalnya
diam-diam — HTTP 200, tidak ada error, daftar cuma terbaca kosong, dan pengangkatan diskon yatim gagal tanpa
sebab yang kelihatan. `mayarCouponRows()` menerima ketiga bentuk yang masuk akal. Kalau ada yang aneh lagi,
buka `api.php?a=mayar_coupons&q=ResepFoto` (super admin) — endpoint itu menampilkan jawaban mentah Mayar,
dan dibuat justru karena menebak bentuk lewat siklus deploy 10 menit sangat mahal.

**JANGAN pakai "Sekali pakai per kode" (`type: onetime`).** Terbukti 19 Sep 2026: dengan tipe itu Mayar
membuat kampanye diskonnya tapi **tidak memasang kodenya sama sekali** — halaman bayar menolak dengan
"Kode diskon ini tidak ditemukan", dan kampanye kosongnya tidak bisa dihapus, hanya dimatikan lewat dashboard.
Sembilan kupon `reusable` berhasil, satu `onetime` gagal begini. Centangnya sekarang mati secara bawaan dan
diberi peringatan; pembatasan pemakaian tetap ada lewat `totalCoupons` (kuota).

**Adopsi wajib membuktikan kodenya menempel, bukan cuma namanya cocok.** `mayarFindCoupon()` memeriksa
`coupons[].code` di jawaban daftar. Tanpa itu, kampanye kosong hasil kasus di atas akan "diangkat" ke panel
dan dipajang sebagai kupon sah, padahal pembeli akan ditolak di halaman bayar — kegagalan yang jauh lebih
buruk daripada sekadar gagal membuat.

**Penamaan kupon yang dipakai sekarang (19 Sep 2026):** satu alias per tingkat, `RFHEMAT10` … `RFHEMAT80`,
ditambah `HEMAT90` yang lebih dulu dibuat. Semuanya kuota 100, `reusable`, berlaku sampai 19 Sep 2027, dan
berlaku untuk semua produk. Ingat tiap alias = satu diskon berkuota sendiri: menambah tiga alias ke satu
tingkat berarti tiga kali lipat penukaran, bukan berbagi kuota yang sama.

**Jumlah pemakaian BISA dibaca**, lewat `totalUsage` di `GET /hl/v2/coupons` (`mayarCouponUsage()`, dipanggil
dari `voucher_sync`). Endpoint detail v1 tidak punya angka itu — dulu panel menulis "Mayar tidak melaporkan
berapa kali kupon dipakai", dan itu keliru. Catatan: daftar kupon ada di **v2** (`MAYAR_API_V2`), endpoint lain
tetap v1; `mayarApi()` menerima parameter keempat `$v2` untuk itu.

Ada **dua jalur**, dan bedanya harus jelas saat menulis UI atau dokumentasi:
- `voucher_create` (dianjurkan) **membuat kupon sungguhan di Mayar** lewat `POST /hl/v1/coupon/create`, lengkap
  dengan kuota (`totalCoupons`) dan tanggal kedaluwarsa. Id diskon yang dikembalikan disimpan di `vouchers.mayar_id`
  — wajib, karena endpoint detail memakai **id**, bukan kode. `voucher_sync` membacanya lewat `GET /hl/v1/coupon/{id}`.
- `voucher_save` hanya **mencatat** kode yang sudah dibuat manual di dashboard. Dipertahankan untuk kode lama.

Aturan yang tetap berlaku:
- **API Mayar tidak punya endpoint hapus atau ubah.** `voucher_delete` hanya mengosongkan catatan lokal;
  kuponnya di Mayar tetap hidup. Mematikan kampanye tetap **dua langkah**: lepas di panel DAN nonaktifkan di Mayar.
- Butuh **API key Read & Write** (setting `mayar_api_key`, diisi dari tab Voucher). Tanpa key, tombol buat mati
  dan panel turun jadi katalog saja.
- Kode voucher pasti menyebar — rem satu-satunya ada di batas pemakaian & kedaluwarsa di Mayar.
- **Tidak ada endpoint pengecek kode yang bisa dijangkau pembeli**, dan jangan dibuat: itu akan jadi mesin
  penebak kupon. Konsekuensinya total di halaman kita tidak berubah; kolom voucher diberi kalimat penjelas.
  (Mayar sendiri punya `POST /hl/v1/coupon/validate` yang butuh `paymentLinkId` + `couponCode` dan membalas
  `valid`. Boleh dipakai suatu saat untuk tombol "Periksa kode" **khusus super admin** — belum dibuat.)
- Halaman kita tidak pernah menghitung atau menampilkan harga terdiskon (`#sum-total` tidak disentuh), supaya
  tidak pernah terjadi angka di halaman berbeda dengan yang ditagih Mayar.

**Email & WhatsApp.** `sendMail()` di `lib.php` memakai SMTP kalau `smtp_host` diisi, kalau tidak `mail()` dengan
envelope sender (`-f`) supaya Return-Path sejajar dengan `From` — itu syarat SPF/DMARC lolos. Header wajib
(`Date`, `Message-ID`, `MIME-Version`, body base64) dibuat di `mailHeaders()`; tanpa itu email dinilai spam
walau SPF & DKIM sudah benar.

> **Hosting sekarang (Jagoan Hosting) memblokir fungsi `mail()` PHP** — `mail()` selalu mengembalikan `false`
> tanpa menulis error. Setelan yang bekerja dan sedang dipakai: `smtp_host=localhost`, `smtp_port=25`,
> `smtp_secure=none`, `smtp_user`/`smtp_pass` kosong (Exim lokal menerima submission tanpa autentikasi lalu
> merelai keluar). SPF sudah memuat IP server dan cPanel menandatangani DKIM `default._domainkey`, jadi
> autentikasi tetap lolos. Kalau pindah hosting, cek ulang lewat tab Pesanan → **Kirim email tes**.

`waSend()` mengirim lewat Fonnte (`fonnte_token`). Nomor admin (`admin_wa`) harus nomor **lain** dari nomor
device Fonnte — pesan dari device ke nomornya sendiri sering tidak sampai. WA ke pembeli hanya terkirim kalau
payload webhook Mayar memuat `customerMobile`. `waNumber()` menormalkan `08…`, `+62…`, `62…`, dan yang
berspasi/berstrip jadi satu bentuk, jadi format apa pun dari pembeli aman di sisi kita.

**KEGAGALAN KIRIM WAJIB MENINGGALKAN SEBAB.** `fulfillOrder()` memanggil `deliverAccess()`, yang mencoba email
dan WhatsApp **terpisah** — satu gagal tidak membatalkan yang lain — lalu menyimpan alasannya di
`orders.email_err` dan `orders.wa_err`, dan merangkum keduanya ke `orders.note`. Tab Pesanan menampilkannya
merah di bawah tiap pesanan.

Kejadian 19 Sep 2026 yang melahirkan aturan ini: pembeli membayar, membernya terbuat, tapi **tidak menerima
email maupun WA**, dan panel cuma menulis "belum terkirim" tanpa satu pun petunjuk. Penyebabnya `sendMail()`
dan `waSend()` sama-sama menelan error jadi `true`/`false` belaka. Kalau menambah kanal baru (Telegram, SMS),
ikuti pola yang sama: pakai varian `…OrFail()` yang melempar, tangkap di pemanggil, simpan sebabnya.

**Penulis resep (`created_by`).** Diisi otomatis dengan username admin yang menyimpan lewat `prompt_save`, dan dipertahankan saat resep diedit admin lain. Resep lama diisi sekali lewat `backfillPromptAuthors()` (ditandai kunci `backfill_created_by` di `settings`): resep dari paket resep atas nama super admin bawaan, sisanya atas nama `LEGACY_PROMPT_AUTHOR`, yang dicocokkan ke akun admin yang ada lewat `resolveAuthorUsername()` supaya foto profilnya ikut terpakai. Endpoint `prompts` hanya menyertakan `createdBy` dan peta `authors` (nama + foto) untuk admin — member biasa tidak melihatnya.

**Menambah resep massal — paket resep.** `seed-prompts.json` hanya jalan saat tabel `prompts` masih kosong, jadi
database yang sudah dipakai tidak bisa diisi lewat situ. Gunakan **paket resep**: file `data/pack*-prompts.json`
berformat `{"version": "...", "prompts": [...]}` yang diimpor sekali oleh `importPromptPacks()` di `lib.php`.
Daftarkan nama filenya di konstanta `PROMPT_PACKS`, simpan gambar contohnya di `app/img/`, lalu push ke `main`.
Impornya `INSERT OR IGNORE` (resep yang sudah diedit admin tidak tertimpa), dibungkus transaksi + `try/catch`,
`ord` menyambung dari `MAX(ord)`, dan ditandai selesai lewat kunci `pack_<version>` di tabel `settings`.
Ganti `version` kalau paket yang sama perlu diimpor ulang. Resep baru memakai `created_at` saat impor, jadi member
paket **Standard** yang mendaftar sebelum itu tidak otomatis melihatnya.

**Ekspor & impor resep lewat Excel — tab Konten** (`admin-konten.js`, endpoint `prompts_export` / `prompts_import`,
penulis-pembaca `.xlsx` sendiri di `app/xlsx.php` karena hosting tidak punya Composer). Satu lembar, 20 kolom:
`id, urutan, kategori, kategori_en, judul, judul_en, deskripsi, deskripsi_en, prompt, tips, tips_en, alat,
best_seller, english_saja, gambar, status_qc, hasil, penulis, tanggal_unggah, tanggal_ubah`.

Enam aturan ini **sengaja** dan jangan dilonggarkan tanpa alasan kuat — semuanya dikunci
`test/uji-konten-excel.sh`:
1. **Impor tidak pernah menghapus.** Resep yang tidak ada di file dibiarkan. Hapus tetap lewat tombol per resep
   (yang memindahkannya ke `prompts_trash`).
2. Baris dicocokkan lewat **id**. id tidak dikenal **ditolak**, bukan dibuatkan resep baru — satu typo jangan
   sampai melahirkan resep sampah. Resep baru dibuat dengan **mengosongkan** kolom id.
3. **`tanggal_unggah` resep lama diabaikan** (dilaporkan ke admin, tidak diam-diam). Kolom itu dipakai
   `allowedPromptIds()` lewat `ORDER BY created_at DESC`, jadi mengubahnya bisa menggeser katalog member Trial.
   Untuk resep baru, tanggalnya dipakai kalau diisi.
4. **`penulis` resep lama dipertahankan**, mengikuti aturan `created_by` yang sudah ada. Resep baru diatasnamakan
   admin yang mengimpor.
5. **`gambar` kosong = gambar lama dipertahankan.** Path harus cocok `^(img|uploads)/[\w.-]+$` **dan** filenya ada
   di server; gambar baru tetap diunggah lewat tab Resep. Resep baru wajib punya gambar yang sudah ada.
6. Selain pengecualian di atas, **kolom yang ada di file bersifat menentukan** — sel kosong berarti nilainya
   memang dikosongkan. Itu yang membuat ekspor → sunting → impor bisa ditebak.

Selalu ada **pratinjau** (`dryRun=1`) yang melaporkan tambah/perbarui/dilewati beserta nomor baris Excel-nya;
penerapan dibungkus transaksi, jadi satu baris gagal berarti tidak ada satu pun yang berubah. Tanggal ditulis
sebagai **teks ISO**, bukan tanggal Excel, supaya tidak bergeser sehari saat bolak-balik.

## Endpoint `api.php?a=…` (auth)
| Level | Endpoint |
|---|---|
| publik | `me` (juga mengembalikan `cover` & `v`), `login`, `logout`, `recent_orders` (pesanan asli + aktivitas keranjang asli, keduanya anonim), `lt` (tracking halaman iklan), `live` (`?page=` opsional), `pixel` (Meta Pixel ID untuk `/promo`) |
| user | `prompts`, `track`, `avatar_save` (admin boleh isi `username` untuk member lain) |
| admin | `prompt_save`, `prompt_review` (ubah status QC/hasil dari daftar), `prompt_cover_from_test` (hasil tes jadi gambar contoh), `prompt_delete` (→ tempat sampah), `trash`, `trash_restore`, `trash_purge`, `members`, `member_save` (FormData, boleh `avatar`/`clearAvatar`), `member_delete`, `cover_save`, `ai_analyze`, `ai_ocr`, `ai_reference` (link Instagram → resep), `ai_thumb` (thumbnail sendiri dari slide referensi), `faces`, `face_add`, `face_delete`, `en_status`, `en_fill` (versi English, lihat Etalase internasional), `prompt_tests`, `prompt_test_add/generate/update/delete` |
| super | `admins`, `admin_save`, `admin_delete`, `admin_change_code`, `orders`, `order_action` (`approve/resend/newcode/remind/reject`), `settings_save`, `test_email`, `test_wa`, `ai_settings`, `ai_settings_save`, `ai_test` (`engine: gemini|deepseek`), `report_users`, `report_ads`, `ad_spend_save`, `vouchers`, `voucher_create` (buat kupon di Mayar), `voucher_sync` (baca status dari Mayar), `voucher_save` (catat kode saja), `voucher_delete`, `mayar_coupons` (lihat daftar kupon Mayar apa adanya — alat diagnosis), `prompts_export` (unduh katalog sebagai .xlsx), `prompts_import` (impor balik; `dryRun=1` = pratinjau) |

## Fitur yang sudah ada (jangan dibuat ulang)
Login & katalog resep (kategori, cari, favorit, populer), detail resep + salin prompt (tombol pil), tombol Buka Gemini/ChatGPT dengan deep-link app (Android `intent://` + fallback Play Store; iOS Universal Link + tautan App Store), section **Tren viral** di bawah best seller, **slider ukuran thumbnail** 1–5 kolom, panduan & FAQ, profil (foto: upload → **editor crop 1:1** → simpan; klik foto → lightbox), bahasa & tema.
Panel admin: Resep (toolbar cari + chip kategori + **filter review**: status QC, penilaian hasil, penulis, tanpa deskripsi, belum ada EN, English saja; tombol status cepat per baris; **tempat sampah** dengan pulihkan/hapus permanen), studio resep dengan 3 mode (upload sendiri / **prompt dari gambar—OCR** / **link Instagram**—DeepSeek, cadangan Gemini; banyak link = wizard; review slide + thumbnail sendiri; checkbox Tren viral = jalan pintas kategori) dan galeri tes internal yang hasilnya bisa **dijadikan gambar contoh resep**; Member (foto, paket, masa aktif, kode akses); Pesanan Mayar + email akses + **WhatsApp otomatis via Fonnte** (pengaturan SMTP & Fonnte ada di tab Pesanan, lengkap dengan tombol kirim tes); AI Gemini; Pengguna & Iklan (laporan, UTM, biaya iklan, ROAS); Admin (akun admin/super admin, ganti kode akses admin utama); Cover (3 foto + teks halaman login); **Konten** (ekspor/impor resep lewat Excel).
Halaman iklan `/promo` dengan pelacakan corong lengkap, penghitung pengunjung aktif, dan notifikasi aktivitas
(pesanan + keranjang) — **semuanya dari data asli**, lihat `landing/CLAUDE.md` bagian bukti sosial.
**Meta Pixel** opsional di `/promo`: ID-nya diisi di Admin → Iklan (kunci `meta_pixel_id`), halaman membacanya lewat
`api.php?a=pixel`. Kolom kosong = tidak ada satu pun skrip Meta dimuat. `Purchase` **dikirim oleh Mayar** lewat
fitur bawaannya (Pixel ID di tab TRACK tiap produk + token Server Side Tracking di Pengaturan → Kustomisasi), sejak
23 Sep 2026. **Jangan buat Conversions API sendiri di `webhook-mayar.php`** — Purchase jadi terhitung dua kali, dan
event tanpa user agent pembeli dibuang Meta. Alasan lengkap & cara ganti token (kedaluwarsa 60 hari): `landing/CLAUDE.md`.

## Aturan kerja
1. **Escape semua data dinamis** di HTML dengan `esc()`; teks pakai `textContent`. Jangan pernah `innerHTML` nilai
   user tanpa `esc()`.
2. **PHP 7.4-kompatibel.** Cek dengan `php -l`.
3. **Tidak ada rahasia di repo** (API key, token, kode akses, hash). Semua rahasia hidup di `config.php` atau tabel
   `settings` di server.
4. Jangan ubah nama/lokasi `config.php`, `data/`, `uploads/`. Jangan hapus `app/.autodeploy`.
5. Endpoint baru: pilih `requireUser / requireAdmin / requireSuperAdmin` secara sadar; tambahkan terjemahan EN untuk
   pesan error baru di peta `tr()`.
6. Tab admin baru: buat `admin-xxx.js` mengikuti pola yang ada, daftarkan `<script src="admin-xxx.js?v=1" defer>` di
   akhir `index.html`, pakai `superOnly:true` bila khusus super admin.
   **Atribut `data-*` yang SUDAH DIPESAN aplikasi** — jangan dipakai di tab baru, karena ada penangkap klik
   tingkat-dokumen di `index.html` yang menyambarnya lebih dulu: `data-open` (membuka detail resep, index.html:1500),
   `data-cat`, `data-close`, `data-fav`, `data-go`, `data-tab`, `data-tool`, `data-lang`, `data-theme-set`,
   `data-qc`, `data-rs`, `data-edit-prompt`, `data-edit-member`, `data-del-prompt`.
   Pernah terjadi: tab Voucher memakai `data-open` untuk tombol "Isi kode", dan menekannya justru membuka halaman
   detail resep — tombolnya seolah rusak padahal handler-nya benar. Beri awalan nama tab pada atribut sendiri
   (mis. `data-vou-save`) supaya tidak mungkin bentrok.
7. Setelah mengubah `index.html`, jalankan cek sintaks skrip inline — file ini besar dan mudah salah kurung.
8. Perubahan UI: pertahankan gaya yang ada (radius besar, `var(--accent)`, pill, sheet bawah). Cek di lebar 390px dan
   mode gelap.
9. **Jangan pernah mengarang bukti sosial** — testimoni, rating, jumlah pembeli, notifikasi pesanan, jumlah pengunjung.
   Aturan lengkapnya di `landing/CLAUDE.md`, dan berlaku juga di app.

## Pengujian lokal
```bash
# 1) salinan terisolasi + DB bersih (jangan sentuh data asli)
R=$(pwd) && T=/tmp/rf-test && rm -rf $T && mkdir -p $T && cp -a app/. $T/ && cd $T   # jalankan dari root repo
rm -rf data && mkdir data && cp "$R"/app/data/seed-*.json data/
cp config.example.php config.php   # lalu isi ADMIN_HASH (password_hash) & DB_FILE
php -S 127.0.0.1:8090

# 2) alur API pakai curl (cookie jar + CSRF)
J=/tmp/cj; CSRF=$(curl -s -c $J 'http://127.0.0.1:8090/api.php?a=me' | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["csrf"];')
curl -s -b $J -c $J -H "X-CSRF: $CSRF" -H 'Content-Type: application/json' -X POST 'http://127.0.0.1:8090/api.php?a=login' -d '{"username":"admin","code":"KODE"}'

# 3) tes webhook + email TANPA mengirim email sungguhan
#    tulis skrip penangkap lalu jalankan PHP dengan sendmail_path diarahkan ke situ:
php -d sendmail_path="/path/ke/catch-mail.sh" -S 127.0.0.1:8090
#    lalu POST payload payment.received ke webhook-mayar.php dan periksa mailbox tangkapan

# 4) sintaks
php -l app/api.php && php -l app/lib.php && php -l app/ai.php
for f in app/admin-*.js; do node --check $f; done
node -e "const h=require('fs').readFileSync('app/index.html','utf8');for(const b of h.match(/<script>([\s\S]*?)<\/script>/g)){const c=b.slice(8,-9);if(c.trim().length<50)continue;new Function(c)};console.log('inline OK')"
```
Tes Gemini butuh API key sungguhan; `RF_GEMINI_BASE` env bisa mengarahkan ke mock server.

**Skrip tes yang ada:**
```bash
bash test/uji-pesan-voucher.sh   # template pesan 3 kanal, email multipart, pengingat,
                                 #   jatah Standard, voucher_save/voucher_delete (lokal)
bash test/uji-voucher-mayar.sh   # voucher_create + voucher_sync lewat Mayar TIRUAN
bash test/uji-link-instagram.sh  # mode Link Instagram + setelan DeepSeek lewat Instagram/DeepSeek/Gemini
                                 #   + thumbnail sendiri (ai_thumb, pustaka wajah) lewat server TIRUAN
                                 #   (RF_IG_BASE, RF_DEEPSEEK_BASE, RF_GEMINI_BASE) — 86 cek
bash test/uji-etalase-imagine.sh # etalase imagine: site.php per host, prompt_en, en_fill — 35 cek
bash test/uji-konten-excel.sh    # ekspor/impor resep lewat Excel (tab Konten)
```
`uji-voucher-mayar.sh` menjalankan server tiruan dan mengarahkan aplikasi ke situ lewat
`RF_MAYAR_BASE` (pola yang sama dengan `RF_GEMINI_BASE`). Yang dibuktikan: bentuk permintaan
`POST /coupon/create` (persentase, `totalCoupons`, `expiredAt`, kode, onetime), `mayar_id`
tersimpan, `voucher_sync` membaca lewat **id** bukan kode dan memakai angka dari jawaban Mayar,
penolakan 401 dijelaskan sebagai key kurang hak, `voucher_delete` tidak memanggil Mayar, dan
semua validasi masukan terjadi **sebelum** Mayar dihubungi. Tes pertamanya memastikan tanpa
env itu basisnya tetap `api.mayar.id` — override ini **hanya** alat uji, bukan setelan produksi.

> Yang **tidak** bisa dibuktikan tes tiruan, dan tetap wajib dicoba sekali di panel sungguhan:
> kupon benar-benar muncul di dashboard Mayar, dan link `?coupon=` benar-benar memotong harga
> di halaman pembayaran. Keduanya ditegakkan di domain Mayar, di luar jangkauan kode ini.

## Dua jenis sesi Claude Code — jangan tertukar
- **Sesi remote/cloud** (yang membuat PR #1, #3, #4): `resepfoto.kitlab.id`, `kitlab.myr.id`, `mayar.id`,
  `web.mayar.id`, dan `ik.imagekit.io` **tidak bisa dijangkau** (403 pada CONNECT / HTTP 000). Agent di sana tidak bisa
  membaca database live, menyetel Mayar, atau melihat aset ImageKit — minta pemilik repo mengirim data sebagai
  lampiran file. Gambar yang ditempel di chat tidak tersimpan sebagai file; hanya lampiran yang mendarat di
  `/root/.claude/uploads/`. Yang bisa dijangkau: `github.com`, `raw.githubusercontent.com`, `fonts.googleapis.com`,
  registry npm & pypi.
- **Sesi lokal di PC pemilik** (desktop app dengan browser pane): semua host di atas **bisa** dijangkau, termasuk
  dashboard Mayar, Fonnte, dan cPanel bila pemilik sudah login di browser pane. Yang tetap harus dijalankan pemilik
  dari terminalnya: merge PR (`gh pr ready N` lalu `gh pr merge N --merge --repo juang-cyber/resepfoto`) dan
  menempelkan token/kredensial ke kolom mana pun.

## Gotcha yang pernah terjadi
- Browser sempat men-cache `index.html` lama → sekarang `.htaccess` memberi `no-cache` untuk `.html`/`.js`. Kalau
  tampilan "tidak berubah", cek dulu `api.php?a=me` → `v`.
- `raw.githubusercontent.com` bisa tertunda beberapa menit setelah push.
- Sheet (`openSheet/closeSheet`) hanya melacak satu sheet; editor crop sengaja punya backdrop sendiri (`#crop-back`,
  z-index 40/41) agar bisa tampil di atas sheet member.
- `saveMember()` harus menerima semua kolom; kalau menambah kolom baru, perbarui `saveMember`, `publicMember`, migrasi
  di `db()`, **dan** setiap pemanggil yang membangun array manual (mis. `admin_save`) — bug avatar terhapus pernah
  terjadi karena ini.
- Playwright di container agent: wajib `executablePath: '/opt/pw-browsers/chromium'` + `--no-sandbox`. Jangan
  jalankan `playwright install`.
- **Akhiran baris wajib LF**, ditegakkan `.gitattributes` (`* text=auto eol=lf`). Tapi file yang pernah
  disimpan ulang oleh editor di Windows bisa menyimpang jadi CRLF di salinan kerja **tanpa** ikut ter-commit —
  blob di repo tetap LF, dan Git cuma berbisik "CRLF will be replaced by LF" saat `add`. Gejalanya: penyuntingan
  otomatis yang mencocokkan beberapa baris sekaligus gagal di satu file saja tanpa sebab yang jelas (terjadi
  19 Sep 2026 di `webhook-mayar.php`). Obatnya **normalkan filenya kembali ke LF**, jangan menyesuaikan pola
  pencarian ke CRLF — itu memperbaiki gejala sambil membiarkan salinan kerja terus menyimpang.
- Halaman masuk: kalimat "Belum punya akses?" adalah **tautan ke `/promo`** (kelas `.login-promo`). Kalau
  teksnya diubah lewat kamus i18n, pastikan elemennya tetap `<a href="/promo">` — `data-i18n` hanya mengganti
  teks, bukan membangun ulang tautannya.
- **Gambar contoh p23 & p24 bersilang dengan sengaja** (24 Sep 2026). Isi filenya tertukar sejak impor:
  `p23.jpg` = selfie Spider-Man, `p24.jpg` = lift mewah. Perbaikannya menukar **rujukan** — database lewat
  `fixSwappedImages()`, paket `pack2-prompts.json`, dan daftar resep di `landing/mockup.html` — bukan isi file,
  karena gambar di-cache browser 30 hari: menukar isi file di bawah nama yang sama tetap menampilkan gambar lama
  yang salah ke siapa pun yang pernah membukanya. **Jangan "merapikan" dengan menukar balik salah satunya.**
  Pelajaran umumnya: gambar yang sudah tayang **diganti dengan nama file baru**, jangan ditimpa.

## Backlog / ide
- Cover login: dukung video/animasi ringan; pratinjau langsung di tab Cover.
- Halaman panduan pembeli (PDF/HTML) yang bisa diunduh dari panel admin.
- **Ganti foto contoh resep p21–p66.** Foto itu berasal dari slide Instagram akun lain (watermark sudah dipotong,
  tapi sumbernya tetap karya orang lain dan beberapa menampilkan figur publik). Sebelum produk dijual, generate
  ulang lewat tab AI Gemini → tes generate, lalu ganti gambarnya per resep dari panel admin.
  **Ini juga menyangkut halaman iklan** — 16 di antaranya tampil di `/promo`, lihat `landing/CLAUDE.md`.
- Tes otomatis Playwright di CI (GitHub Actions) sebelum deploy. Repo ini **belum punya CI sama sekali**.
- Isi bukti sosial asli di `landing/mockup.html` (`TESTIMONIALS`, `RATING`) lalu bangun ulang dengan `--live`.

## Checklist serah-terima ke pembeli
1. Ganti kode akses admin utama (tab Admin → "Ganti kode akses admin utama").
2. Hapus member contoh `demo` (tab Member).
3. Isi API key Gemini (tab AI, key `AIza…` dari aistudio.google.com) dan DeepSeek (key `sk-…` dari
   platform.deepseek.com) — DeepSeek dipakai mode Link Instagram.
4. Isi token webhook Mayar + email admin (tab Pesanan); daftarkan URL webhook di Mayar.
5. Isi token Fonnte + nomor WhatsApp admin (tab Pesanan → WhatsApp otomatis), lalu tekan "Kirim WA tes".
6. Ganti foto & teks cover login (tab Cover).
7. Halaman iklan: jalankan `node landing/build-promo.mjs --live` — lihat checklist di `landing/CLAUDE.md`.
8. Pindahkan repo GitHub ke akun pembeli dan perbarui URL repo di cron hosting (lihat `CLAUDE.local.md`).
