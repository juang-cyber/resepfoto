# ResepFoto · Imagine Your Photo

Resep prompt foto siap salin untuk Gemini & ChatGPT.

## Struktur
| Folder | Isi | Online? |
|---|---|---|
| `app/` | Aplikasi member + admin (PHP 7.4+ & SQLite), webhook Mayar, halaman terima kasih | Ya, auto-deploy ke resepfoto.oziera.co.id |
| `landing/index.html` | Halaman jualan. Data bukti sosial diatur di bagian `bukti sosial` di script (`PREVIEW`, `TESTIMONIALS`, `RATING`, `ORDER_FEED_URL`, `LIVE_VIEWERS`) | Belum (tidak ikut auto-deploy) |
| `landing/mockup.html` | Mockup presentasi, pembayaran nonaktif | Tidak untuk publik |
| `brand/` | Logo, ikon, gambar share + skrip pembuatnya | – |

## Auto-deploy (GitHub Actions)
1. cPanel → **FTP Accounts** → buat akun FTP khusus dengan direktori `resepfoto.oziera.co.id`.
2. GitHub repo → **Settings → Secrets and variables → Actions** → tambah:
   - `FTP_SERVER` (contoh: `ftp.oziera.co.id`)
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
3. Setiap push ke `main` yang mengubah folder `app/` akan otomatis ter-upload (hanya file yang berubah).

Yang **tidak** pernah ter-upload / tidak boleh di-commit: `app/config.php`, database `app/data/*.sqlite`, folder `app/uploads/`.
Upload pertama FTP-Deploy-Action akan mengirim semua file di `app/` (kecuali yang dikecualikan); `config.php` dan database yang sudah ada di server tetap aman.

## Setup server baru
Salin `app/config.example.php` → `config.php` di server lalu isi hash admin & nama database acak.

## Admin: AI Gemini, laporan
- **AI Gemini**: isi API key dari Google AI Studio di Admin → AI Gemini. Model default `gemini-3.8-flash` (analisa & link referensi) dan `gemini-3.1-flash-image` (tes generate). Key disimpan di database server, tidak di kode.
- **Tambah resep**: mode *Link referensi* (link share Gemini/ChatGPT) atau *Upload sendiri* (klik area gambar lalu Ctrl+V / pilih file + tempel prompt → "Isi otomatis dengan Gemini"). Di bawah form ada *Hasil tes internal* (upload/tempel hasil atau generate dengan Gemini).
- **Pengguna**: laporan member, pemakaian app (buka/salin/favorit), resep terpopuler, pesanan.
- **Iklan**: laporan landing page (pengunjung, corong, kampanye UTM, perangkat), input biaya iklan harian, ROAS/CPA, pembuat link UTM. Aktif setelah landing page di-deploy dan `TRACK_URL = "/api.php"`.

## Catatan
- Setelah upload `lib.php` baru, migrasi database berjalan otomatis (kolom English untuk resep diisi dari `data/seed-en.json`).
- Endpoint publik `api.php?a=recent_orders` mengembalikan pesanan asli 14 hari terakhir dengan nama disamarkan (untuk notifikasi di landing page).
