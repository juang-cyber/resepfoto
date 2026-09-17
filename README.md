# ResepFoto · Imagine Your Photo

Resep prompt foto siap salin untuk Gemini & ChatGPT.

## Struktur
| Folder | Isi | Online? |
|---|---|---|
| `app/` | Aplikasi member + admin (PHP 7.4+ & SQLite), webhook Mayar, halaman terima kasih | Ya, auto-deploy ke resepfoto.oziera.co.id |
| `app/promo/` | **Halaman iklan**, dibuat oleh `landing/build-promo.mjs`. Jangan diedit langsung | Ya → resepfoto.oziera.co.id/promo |
| `landing/mockup.html` | Sumber desain halaman iklan + versi presentasi (pembayaran nonaktif, berlabel CONTOH) | Tidak untuk publik |
| `landing/index.html` | Halaman jualan versi lama, digantikan `app/promo/` | Tidak dipakai |
| `brand/` | Logo, ikon, gambar share + skrip pembuatnya | – |

## Auto-deploy (server menarik dari GitHub)
Server cPanel menjalankan `deploy/rf-deploy.sh` lewat cron tiap 2 menit:
1. `git fetch` branch `main` memakai deploy key `~/.ssh/rf_github` (terdaftar di GitHub → Settings → Deploy keys).
2. Kalau ada commit baru, isi folder `app/` disalin ke `~/resepfoto.oziera.co.id/`.
3. Riwayat deploy: `~/rf-deploy/deploy.log`.

Jadi cukup push ke `main`, website ter-update dalam ±2 menit. Tidak ada password yang disimpan di GitHub.
Karena skrip menyalin **seluruh isi** `app/`, folder `app/promo/` ikut tayang di `resepfoto.oziera.co.id/promo` tanpa perlu mengubah cron.

### Halaman iklan `/promo`
Desainnya dirawat di `landing/mockup.html`. Setelah mengubahnya, bangun ulang halamannya:

```bash
node landing/build-promo.mjs           # PRATINJAU  -> app/promo/index.html + app/promo/img/
node landing/build-promo.mjs --live    # PRODUKSI, jalankan sebelum dipasang di iklan
```

Dua-duanya menyalakan pelacakan iklan (`TRACK_URL = "/api.php"`), pembayaran Mayar, notifikasi pesanan, dan
penghitung pengunjung dari data asli. Bedanya cuma data ilustrasi:

| | Pratinjau (default) | `--live` |
|---|---|---|
| Testimoni contoh | tampil | dibuang |
| Rating 4.9 · 483 ulasan | tampil | dibuang |
| Label **CONTOH** di foto | tampil | dibuang |

Pratinjau untuk dites sendiri dan dibagikan ke tim — halaman jujur menyatakan dirinya contoh.
**Sebelum iklan diarahkan ke halaman ini, bangun ulang dengan `--live`.**
Bagian testimoni otomatis disembunyikan selama `TESTIMONIALS` kosong — isi hanya dengan ulasan asli yang sudah diizinkan pembelinya.

Link iklan Meta:

```
https://resepfoto.oziera.co.id/promo?utm_source=facebook&utm_medium=paid&utm_campaign=NAMA&utm_content={{ad.name}}
```
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
