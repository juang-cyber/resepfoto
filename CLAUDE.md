# ResepFoto — panduan proyek untuk Claude Code

> Baca file ini dulu sebelum mengubah apa pun. Detail privat (hosting, lokasi kredensial) ada di `CLAUDE.local.md`
> (tidak di-commit). **Halaman iklan `/promo`: baca `landing/CLAUDE.md`.**
> Terakhir diperbarui: 17 September 2026.

## Apa ini
**ResepFoto** (resepfoto.oziera.co.id) — web app berbayar berisi "resep" prompt foto AI yang disalin member ke
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
  admin-ext.js      tab Pesanan (Mayar)                   [super admin]
  admin-ai.js       tab AI Gemini (API key, model, log)   [super admin]
  admin-reports.js  tab Pengguna & Iklan (laporan)        [super admin]
  admin-team.js     tab Admin (kelola akun admin)         [super admin]
  admin-cover.js    tab Cover (foto/teks halaman login)   [admin & super]
  webhook-mayar.php webhook pembayaran Mayar
  terima-kasih.html halaman sesudah bayar
  img/          foto contoh resep bawaan (p01…p66.jpg, 4:5) — ikut ter-deploy
  .htaccess     blokir file sensitif, paksa HTTPS, header keamanan, cache
  .autodeploy   PENANDA WAJIB — cron hanya men-deploy kalau file ini ada. Jangan dihapus
  config.example.php  template config (config.php asli TIDAK di repo)
  data/         seed-prompts.json, seed-en.json, pack2-prompts.json (database .sqlite TIDAK di repo)
  uploads/      hanya .htaccess (file upload TIDAK di repo)
brand/          logo, ikon, og-image
landing/        index.html → di-deploy otomatis ke /promo (dibangkitkan dari mockup.html oleh
                build-promo.mjs — JANGAN diedit tangan); mockup.html = sumber desain, tidak ikut
                ter-deploy; img/ ikut ke /promo → baca landing/CLAUDE.md
deploy/         rf-deploy.sh lama (versi SSH) — referensi saja, yang aktif adalah cron di cPanel
```
`.gitignore` mengecualikan `app/config.php`, `app/data/*.sqlite*`, `app/data/*.log`, `app/uploads/*` (kecuali
`.htaccess`), dan `.DS_Store`.

## Alur deploy (sudah otomatis)
1. Edit file di `app/`. Untuk halaman iklan: edit `landing/mockup.html` lalu jalankan
   `node landing/build-promo.mjs` yang menulis ulang `landing/index.html`.
2. `git add -A && git commit -m "..." && git push` (branch `main`).
3. Cron di hosting (tiap 5 menit) menarik `origin/main`, dan **hanya jika commit berubah dan `app/.autodeploy` ada**, menyalin `app/.` ke document root **dan** `landing/index.html` + `landing/img/` ke `promo/`. `config.php`, database, `uploads/` tidak pernah tersentuh; `landing/mockup.html` sengaja tidak ikut.
4. Verifikasi: `curl -s https://resepfoto.oziera.co.id/api.php?a=me` → lihat field `"v"`; landing: `curl -sI https://resepfoto.oziera.co.id/promo/`.

Perintah cron sebenarnya ada di **cPanel → Cron Jobs**, bukan di `deploy/rf-deploy.sh` (itu sisa versi SSH lama
yang menyebut 2 menit tanpa guard `.autodeploy` — jangan dipercaya sebagai sumber kebenaran).

**Konvensi rilis:** tiap rilis naikkan penanda versi di route `me` (`'v' => 'admin-N'`) di `api.php`, dan naikkan `?v=`
pada `<script src="admin-*.js?v=N">` di `index.html` untuk file JS yang berubah. HTML/JS sudah `Cache-Control:
no-cache` lewat `.htaccess`, gambar di-cache 30 hari.

## Halaman iklan `/promo`
Tayang di `resepfoto.oziera.co.id/promo`, disajikan dari **`landing/index.html`** yang disalin cron.
**Sumber desainnya `landing/mockup.html`** — `landing/index.html` dibangkitkan, jangan diedit tangan:

```bash
node landing/build-promo.mjs           # PRATINJAU — testimoni contoh, rating, dan label CONTOH tetap tampil
node landing/build-promo.mjs --live    # PRODUKSI — ketiganya dibuang; WAJIB sebelum dipasang di iklan
```

Yang ter-commit sekarang adalah **mode pratinjau**: pembayaran Mayar hidup sungguhan supaya bisa dites, tapi halaman
tetap berlabel contoh dan belum dibagikan ke Meta. Detail lengkap, aturan bukti sosial, dan checklist go-live ada di
**`landing/CLAUDE.md`** — baca itu sebelum menyentuh apa pun soal iklan.

## Pembayaran Mayar
Alur: pembeli klik paket di `/promo` → checkout Mayar → Mayar POST ke `app/webhook-mayar.php` → `fulfillOrder()`
membuat member, mengirim email akses ke pembeli, dan email notifikasi ke admin.

Paket ditentukan `planFromProduct()` di `app/lib.php`. **KOREKSI PENTING — jangan diulangi:** ambang `Rp 90.000`
di fungsi itu **bukan masalah** untuk Premium seharga Rp 79.900, karena nama produk diperiksa lebih dulu:
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
  - `member` — lihat/salin resep, atur foto profil sendiri. Paket `Standard` hanya melihat resep yang ada saat akun
    dibuat.
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
  `gemini-3.1-flash-image`. Fungsi: `recipeFromLink()` (share link Gemini/ChatGPT + url_context),
  `recipeFromUpload()`, `recipeFromImagePrompt()` (OCR prompt dari screenshot), `generateTestImage()`. Error
  aman-ditampilkan dilempar sebagai `RfError` → HTTP 422. Log ke tabel `ai_log` (maks 300 baris).

## Data
Tabel: `prompts, members, attempts, settings, orders, webhook_log, ai_log, prompt_tests, events, lt_events, presence,
ad_spend`.
Kolom penting `members`: `username, name, code_hash, code_hint, plan, expires, active, email, phone, role, avatar`.
Kunci `settings`: `admin_hash, admin_avatar, admin_email, mail_from, mayar_webhook_token, auto_without_token, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, fonnte_token, admin_wa, gemini_api_key, gemini_model, gemini_image_model, cover_title, cover_sub, cover_title_en, cover_sub_en, cover_chip, cover_img1..3`.

**Email & WhatsApp.** `sendMail()` di `lib.php` memakai SMTP kalau `smtp_host` diisi, kalau tidak `mail()` dengan
envelope sender (`-f`) supaya Return-Path sejajar dengan `From` — itu syarat SPF/DMARC lolos. Header wajib
(`Date`, `Message-ID`, `MIME-Version`, body base64) dibuat di `mailHeaders()`; tanpa itu email dinilai spam
walau SPF & DKIM sudah benar.

> **Hosting sekarang (Jagoan Hosting) memblokir fungsi `mail()` PHP** — `mail()` selalu mengembalikan `false`
> tanpa menulis error. Setelan yang bekerja dan sedang dipakai: `smtp_host=localhost`, `smtp_port=25`,
> `smtp_secure=none`, `smtp_user`/`smtp_pass` kosong (Exim lokal menerima submission tanpa autentikasi lalu
> merelai keluar). SPF sudah memuat IP server dan cPanel menandatangani DKIM `default._domainkey`, jadi
> autentikasi tetap lolos. Kalau pindah hosting, cek ulang lewat tab Pesanan → **Kirim email tes**.

`waSend()` mengirim lewat Fonnte (`fonnte_token`); `sendAccessWa()` dipanggil dari `fulfillOrder()` sesudah email,
dan hasilnya disimpan di kolom `orders.wa_sent`. Nomor admin (`admin_wa`) harus nomor **lain** dari nomor device
Fonnte — pesan dari device ke nomornya sendiri sering tidak sampai. WA ke pembeli hanya terkirim kalau payload
webhook Mayar memuat `customerMobile`.

**Menambah resep massal — paket resep.** `seed-prompts.json` hanya jalan saat tabel `prompts` masih kosong, jadi
database yang sudah dipakai tidak bisa diisi lewat situ. Gunakan **paket resep**: file `data/pack*-prompts.json`
berformat `{"version": "...", "prompts": [...]}` yang diimpor sekali oleh `importPromptPacks()` di `lib.php`.
Daftarkan nama filenya di konstanta `PROMPT_PACKS`, simpan gambar contohnya di `app/img/`, lalu push ke `main`.
Impornya `INSERT OR IGNORE` (resep yang sudah diedit admin tidak tertimpa), dibungkus transaksi + `try/catch`,
`ord` menyambung dari `MAX(ord)`, dan ditandai selesai lewat kunci `pack_<version>` di tabel `settings`.
Ganti `version` kalau paket yang sama perlu diimpor ulang. Resep baru memakai `created_at` saat impor, jadi member
paket **Standard** yang mendaftar sebelum itu tidak otomatis melihatnya.

## Endpoint `api.php?a=…` (auth)
| Level | Endpoint |
|---|---|
| publik | `me` (juga mengembalikan `cover` & `v`), `login`, `logout`, `recent_orders`, `lt` (tracking halaman iklan), `live` |
| user | `prompts`, `track`, `avatar_save` (admin boleh isi `username` untuk member lain) |
| admin | `prompt_save`, `prompt_delete`, `members`, `member_save` (FormData, boleh `avatar`/`clearAvatar`), `member_delete`, `cover_save`, `ai_link`, `ai_analyze`, `ai_ocr`, `prompt_tests`, `prompt_test_add/generate/update/delete` |
| super | `admins`, `admin_save`, `admin_delete`, `admin_change_code`, `orders`, `order_action`, `settings_save`, `test_email`, `test_wa`, `ai_settings`, `ai_settings_save`, `ai_test`, `report_users`, `report_ads`, `ad_spend_save` |

## Fitur yang sudah ada (jangan dibuat ulang)
Login & katalog resep (kategori, cari, favorit, populer), detail resep + salin prompt (tombol pil), tombol Buka Gemini/ChatGPT dengan deep-link app (Android `intent://` + fallback Play Store; iOS Universal Link + tautan App Store), panduan & FAQ, profil (foto: upload → **editor crop 1:1** → simpan; klik foto → lightbox), bahasa & tema.
Panel admin: Resep (toolbar cari + chip kategori + tag), studio resep dengan 3 mode (link referensi / upload sendiri / **prompt dari gambar—OCR**) dan galeri tes internal; Member (foto, paket, masa aktif, kode akses); Pesanan Mayar + email akses + **WhatsApp otomatis via Fonnte** (pengaturan SMTP & Fonnte ada di tab Pesanan, lengkap dengan tombol kirim tes); AI Gemini; Pengguna & Iklan (laporan, UTM, biaya iklan, ROAS); Admin (akun admin/super admin, ganti kode akses admin utama); Cover (3 foto + teks halaman login).
Halaman iklan `/promo` dengan pelacakan corong lengkap.

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

## Dua jenis sesi Claude Code — jangan tertukar
- **Sesi remote/cloud** (yang membuat PR #1, #3, #4): `resepfoto.oziera.co.id`, `kitlab.myr.id`, `mayar.id`,
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

## Backlog / ide
- Cover login: dukung video/animasi ringan; pratinjau langsung di tab Cover.
- Halaman panduan pembeli (PDF/HTML) yang bisa diunduh dari panel admin.
- **Ganti foto contoh resep p21–p66.** Foto itu berasal dari slide Instagram akun lain (watermark sudah dipotong,
  tapi sumbernya tetap karya orang lain dan beberapa menampilkan figur publik). Sebelum produk dijual, generate
  ulang lewat tab AI Gemini → tes generate, lalu ganti gambarnya per resep dari panel admin.
  **Ini juga menyangkut halaman iklan** — 16 di antaranya tampil di `/promo`, lihat `landing/CLAUDE.md`.
- Ekspor/impor resep lewat panel admin (impor massal sudah ada lewat paket resep di `data/pack*-prompts.json`, tapi belum ada UI-nya).
- Tes otomatis Playwright di CI (GitHub Actions) sebelum deploy. Repo ini **belum punya CI sama sekali**.
- Isi bukti sosial asli di `landing/mockup.html` (`TESTIMONIALS`, `RATING`) lalu bangun ulang dengan `--live`.

## Checklist serah-terima ke pembeli
1. Ganti kode akses admin utama (tab Admin → "Ganti kode akses admin utama").
2. Hapus member contoh `demo` (tab Member).
3. Isi API key Gemini (tab AI Gemini, key `AIza…` dari aistudio.google.com).
4. Isi token webhook Mayar + email admin (tab Pesanan); daftarkan URL webhook di Mayar.
5. Isi token Fonnte + nomor WhatsApp admin (tab Pesanan → WhatsApp otomatis), lalu tekan "Kirim WA tes".
6. Ganti foto & teks cover login (tab Cover).
7. Halaman iklan: jalankan `node landing/build-promo.mjs --live` — lihat checklist di `landing/CLAUDE.md`.
8. Pindahkan repo GitHub ke akun pembeli dan perbarui URL repo di cron hosting (lihat `CLAUDE.local.md`).
