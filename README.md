# ResepFoto · Imagine Your Photo

Resep prompt foto siap salin untuk Gemini & ChatGPT.

## Struktur
| Folder | Isi | Online? |
|---|---|---|
| `app/` | Aplikasi member + admin (PHP 7.4+ & SQLite), webhook Mayar, halaman terima kasih | Ya, auto-deploy ke resepfoto.oziera.co.id |
| `landing/index.html` | Halaman jualan. Data bukti sosial diatur di bagian `bukti sosial` di script (`PREVIEW`, `TESTIMONIALS`, `RATING`, `ORDER_FEED_URL`, `LIVE_VIEWERS`) | Belum (tidak ikut auto-deploy) |
| `landing/mockup.html` | Mockup presentasi, pembayaran nonaktif | Tidak untuk publik |
| `brand/` | Logo, ikon, gambar share + skrip pembuatnya | – |

## Auto-deploy (server menarik dari GitHub)
Server cPanel menjalankan `deploy/rf-deploy.sh` lewat cron tiap 2 menit:
1. `git fetch` branch `main` memakai deploy key `~/.ssh/rf_github` (terdaftar di GitHub → Settings → Deploy keys).
2. Kalau ada commit baru, isi folder `app/` disalin ke `~/resepfoto.oziera.co.id/`.
3. Riwayat deploy: `~/rf-deploy/deploy.log`.

Jadi cukup push ke `main`, website ter-update dalam ±2 menit. Tidak ada password yang disimpan di GitHub.
Yang **tidak** pernah disentuh: `config.php`, database `data/*.sqlite`, folder `uploads/` (tidak ada di repo).

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
