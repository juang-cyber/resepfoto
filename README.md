# ResepFoto · Imagine Your Photo

Resep prompt foto siap salin untuk Gemini & ChatGPT.

## Struktur
| Folder | Isi | Online? |
|---|---|---|
| `app/` | Aplikasi member + admin (PHP 7.4+ & SQLite), webhook Mayar, halaman terima kasih | Ya, auto-deploy ke resepfoto.oziera.co.id |
| `landing/index.html` | **Halaman iklan.** Dibangkitkan oleh `landing/build-promo.mjs` — jangan diedit langsung | Ya, auto-deploy ke resepfoto.oziera.co.id/promo |
| `landing/mockup.html` | **Sumber desain** halaman iklan, sekaligus versi presentasi (pembayaran nonaktif, berlabel CONTOH) | Tidak untuk publik, tidak ikut deploy |
| `landing/build-promo.mjs` | Skrip build halaman iklan (tanpa dependency) | – |
| `brand/` | Logo, ikon, gambar share + skrip pembuatnya | – |

## Auto-deploy (server menarik dari GitHub)
Cron di cPanel jalan tiap **5 menit** (perintahnya ada di cPanel → Cron Jobs, bukan di `deploy/rf-deploy.sh`
yang tinggal jadi referensi versi SSH lama):
1. `git fetch` + `git reset --hard origin/main` di `~/repositories/resepfoto` (clone HTTPS, repo publik).
2. Kalau commit berubah **dan** `app/.autodeploy` ada:
   - isi `app/` disalin ke `~/resepfoto.oziera.co.id/`;
   - `landing/index.html` + `landing/img/` disalin ke `~/resepfoto.oziera.co.id/promo/`.
3. Riwayat deploy: `~/rf-deploy/deploy.log`, keluaran mentah: `~/rf-deploy/cron.log`.

Jadi cukup push ke `main`, website ter-update dalam ±5 menit. Tidak ada password yang disimpan di GitHub.
Yang **tidak** pernah disentuh: `config.php`, database `data/*.sqlite`, folder `uploads/` (tidak ada di repo).
`landing/mockup.html` sengaja tidak ikut ter-deploy.

## Halaman iklan `/promo`
Desainnya dirawat di `landing/mockup.html`, **bukan** di `landing/index.html`. Sesudah mengubah mockup, bangun ulang:

```bash
node landing/build-promo.mjs           # PRATINJAU -> landing/index.html
node landing/build-promo.mjs --live    # PRODUKSI, wajib sebelum dipasang di iklan
```

Dua-duanya menyalakan pembayaran Mayar, pelacakan iklan (`TRACK_URL = "/api.php"`), notifikasi pesanan, dan
penghitung pengunjung dari data asli. Bedanya cuma data ilustrasi:

| | Pratinjau (default) | `--live` |
|---|---|---|
| Testimoni contoh | tampil | dibuang |
| Rating 4,9 · 483 ulasan | tampil | dibuang |
| Label **CONTOH** di pojok | tampil | dibuang |

Pratinjau untuk dites sendiri dan dibagikan ke tim — halaman jujur menyatakan dirinya contoh.
**Sebelum iklan diarahkan ke halaman ini, bangun ulang dengan `--live`.** Bagian testimoni otomatis disembunyikan
selama `TESTIMONIALS` kosong — isi hanya dengan ulasan asli yang sudah diizinkan pembelinya. Aturan selengkapnya di
`landing/CLAUDE.md`.

Link iklan Meta:

```
https://resepfoto.oziera.co.id/promo?utm_source=facebook&utm_medium=paid&utm_campaign=NAMA&utm_content={{ad.name}}
```

## Pembayaran Mayar
Pembeli klik paket di `/promo` → checkout Mayar → Mayar POST ke `webhook-mayar.php` → member dibuat otomatis,
email akses dikirim ke pembeli (dan WhatsApp kalau token Fonnte diisi), notifikasi dikirim ke admin.

Yang perlu disiapkan di dashboard Mayar:
1. Harga produk: Standard `49900`, Premium `79900`.
2. **Nama produk wajib memuat kata "Standard" / "Premium"** — nama inilah yang menentukan paket pembeli, bukan
   nominalnya. Slug URL tidak dibaca.
3. Webhook → `https://resepfoto.oziera.co.id/webhook-mayar.php`.
4. Webhook Token dari Mayar → tempel di **Admin → Pesanan**. Tanpa token, pesanan tercatat tapi harus diaktifkan
   manual.

## Setup server baru
Salin `app/config.example.php` → `config.php` di server lalu isi hash admin & nama database acak.

## Menambah resep secara massal (paket resep)
`app/data/seed-prompts.json` hanya dipakai saat tabel `prompts` masih kosong, jadi database yang sudah jalan
tidak bisa diisi lewat situ. Untuk itu ada **paket resep**: file `app/data/pack*-prompts.json` yang diimpor
otomatis satu kali oleh `importPromptPacks()` di `app/lib.php`.

1. Buat file JSON baru di `app/data/`, formatnya `{"version": "...", "prompts": [...]}`. Tiap resep memakai
   field `id, order, cat, title, desc, popular, tools, prompt, tips, image, cat_en, title_en, desc_en, tips_en`.
2. Simpan gambar contohnya di `app/img/` (ikut ter-deploy) dan isi `image` dengan `img/namafile.jpg`.
3. Daftarkan nama filenya di konstanta `PROMPT_PACKS` (`app/lib.php`).
4. Push ke `main`. Saat request pertama sesudah deploy, isinya masuk database.

Impornya aman diulang: pakai `INSERT OR IGNORE` (resep yang id-nya sudah ada — misalnya sudah diedit admin —
tidak tertimpa) dan ditandai selesai lewat kunci `pack_<version>` di tabel `settings`. Ganti `version` kalau
ingin paket yang sama diimpor ulang. Resep baru memakai `created_at` saat impor, jadi member paket
**Standard** yang mendaftar sebelum itu tidak otomatis melihatnya.

## Admin: AI Gemini, laporan
- **AI Gemini**: isi API key dari Google AI Studio di Admin → AI Gemini. Model default `gemini-3.8-flash` (analisa & link referensi) dan `gemini-3.1-flash-image` (tes generate). Key disimpan di database server, tidak di kode.
- **Tambah resep**: mode *Link referensi* (link share Gemini/ChatGPT) atau *Upload sendiri* (klik area gambar lalu Ctrl+V / pilih file + tempel prompt → "Isi otomatis dengan Gemini"). Di bawah form ada *Hasil tes internal* (upload/tempel hasil atau generate dengan Gemini).
- **Pengguna**: laporan member, pemakaian app (buka/salin/favorit), resep terpopuler, pesanan.
- **Iklan**: laporan landing page (pengunjung, corong, kampanye UTM, perangkat), input biaya iklan harian, ROAS/CPA, pembuat link UTM. Aktif setelah landing page di-deploy dan `TRACK_URL = "/api.php"`.

## Admin: email & WhatsApp (tab Pesanan)
- **Email**: host SMTP kosong = fungsi `mail()` PHP dengan envelope sender (`-f`) agar `Return-Path` sejajar
  dengan `From`, plus header `Date`/`Message-ID`/`MIME-Version` — syarat SPF & DMARC lolos. Tombol
  **Kirim email tes** bisa diarahkan ke alamat mana saja.
  **Catatan hosting sekarang:** Jagoan Hosting memblokir `mail()` (selalu `false`, tanpa error). Setelan yang
  dipakai: host `localhost`, port `25`, tanpa enkripsi, pengguna & sandi kosong — Exim lokal menerima lalu
  merelai keluar, dan SPF/DKIM tetap lolos karena email berangkat dari IP server yang sama.
- **WhatsApp (Fonnte)**: isi token perangkat dari Fonnte → Device. Kalau terisi, detail akses otomatis dikirim
  ke WhatsApp pembeli sesudah pembayaran lunas (hasilnya tersimpan di kolom `orders.wa_sent` dan tampil
  sebagai pil "WA terkirim"). Nomor admin dipakai untuk notifikasi penjualan baru.

## Catatan
- Setelah upload `lib.php` baru, migrasi database berjalan otomatis (kolom English untuk resep diisi dari `data/seed-en.json`).
- Endpoint publik `api.php?a=recent_orders` mengembalikan pesanan asli 14 hari terakhir dengan nama disamarkan (untuk notifikasi di landing page).
