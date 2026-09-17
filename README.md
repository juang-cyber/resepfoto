# ResepFoto · Imagine Your Photo

Resep prompt foto siap salin untuk Gemini & ChatGPT — web app berbayar dengan panel admin lengkap.
Live: **https://resepfoto.oziera.co.id** · halaman iklan: **/promo** · versi `admin-6` (cek `api.php?a=me` → `"v"`).

> Mengembangkan dengan Claude Code? Baca **`CLAUDE.md`** (arsitektur, endpoint, aturan kerja) dan
> **`landing/CLAUDE.md`** (halaman iklan `/promo` — aturan bukti sosial, cara build, checklist go-live).

## Struktur
| Folder | Isi | Online? |
|---|---|---|
| `app/` | Aplikasi member + panel admin (PHP 7.4+ & SQLite), webhook Mayar, halaman terima kasih | **Ya** — auto-deploy ke resepfoto.oziera.co.id |
| `app/promo/` | **Halaman iklan.** Dibangkitkan oleh `landing/build-promo.mjs` — jangan diedit langsung | **Ya** → resepfoto.oziera.co.id/promo |
| `landing/mockup.html` | **Sumber desain** halaman iklan, sekaligus versi presentasi (pembayaran nonaktif, berlabel CONTOH) | Tidak untuk publik |
| `landing/build-promo.mjs` | Skrip build halaman iklan (tanpa dependency) | – |
| `landing/index.html` | Halaman jualan versi lama, sudah digantikan `app/promo/` | Tidak dipakai |
| `brand/` | Logo, ikon, gambar share | – |
| `deploy/rf-deploy.sh` | Skrip yang dipakai cron di server | – |

## Fitur
**Member:** login username + kode akses · katalog resep (kategori, cari, favorit, paling laris) · detail + salin
prompt · tombol Buka Gemini/ChatGPT (membuka aplikasinya di Android/iPhone) · panduan & FAQ · foto profil dengan
editor crop 1:1 · bahasa Indonesia/English · tema terang/gelap.

**Panel admin:**
- **Resep** — cari & filter kategori; studio resep 3 mode: *Link referensi* (share link Gemini/ChatGPT),
  *Upload sendiri*, *Prompt dari gambar* (OCR) — semuanya diisi otomatis oleh Gemini; galeri hasil tes internal.
- **Member** — tambah/ubah, paket & masa aktif, kode akses, foto.
- **Cover** — ganti 3 foto & teks halaman login.
- **Pesanan** — pesanan Mayar, aktivasi & email akses otomatis.
- **AI Gemini** — API key, model, statistik & log.
- **Pengguna** — laporan member & pemakaian app.
- **Iklan** — corong halaman `/promo`, kampanye UTM, biaya iklan, ROAS/CPA, pembuat link UTM.
- **Admin** — akun admin/super admin, ganti kode akses admin utama.

**Peran:** super admin (semua tab) · admin (Resep, Member, Cover) · member.

## Auto-deploy
1. Push ke branch `main`.
2. Cron cPanel menjalankan `deploy/rf-deploy.sh` tiap 2 menit: `git fetch` memakai deploy key `~/.ssh/rf_github`
   (terdaftar di GitHub → Settings → Deploy keys), dan kalau ada commit baru, menyalin **seluruh isi** `app/` ke
   `~/resepfoto.oziera.co.id/`.
3. Riwayat: `~/rf-deploy/deploy.log` di server.

Jadi cukup push ke `main`, website ter-update dalam ±2 menit. Tidak ada password yang disimpan di GitHub.
Karena skrip menyalin seluruh isi `app/`, folder `app/promo/` ikut tayang tanpa perlu mengubah cron.
Yang **tidak pernah** disentuh: `config.php`, database `data/*.sqlite`, folder `uploads/`.
HTML & JS dikirim dengan `Cache-Control: no-cache`, jadi pengunjung langsung melihat versi terbaru.

## Halaman iklan `/promo`
Desainnya dirawat di `landing/mockup.html`, **bukan** di `app/promo/index.html`. Setelah mengubah mockup,
bangun ulang halamannya:

```bash
node landing/build-promo.mjs           # PRATINJAU  -> app/promo/index.html + app/promo/img/
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
**Sebelum iklan diarahkan ke halaman ini, bangun ulang dengan `--live`.**
Bagian testimoni otomatis disembunyikan selama `TESTIMONIALS` kosong — isi hanya dengan ulasan asli yang sudah
diizinkan pembelinya. Aturan selengkapnya di `landing/CLAUDE.md`.

Link iklan Meta:

```
https://resepfoto.oziera.co.id/promo?utm_source=facebook&utm_medium=paid&utm_campaign=NAMA&utm_content={{ad.name}}
```

## Pembayaran Mayar
Pembeli klik paket di `/promo` → checkout Mayar → Mayar POST ke `webhook-mayar.php` → member dibuat otomatis,
email akses dikirim ke pembeli, notifikasi dikirim ke admin.

Yang perlu disiapkan di dashboard Mayar:
1. Harga produk: Standard `49900`, Premium `79900`.
2. **Nama produk wajib memuat kata "Standard" / "Premium"** — nama inilah yang menentukan paket pembeli, bukan
   nominalnya. Slug URL tidak dibaca.
3. Webhook → `https://resepfoto.oziera.co.id/webhook-mayar.php`.
4. Webhook Token dari Mayar → tempel di **Admin → Pesanan**. Tanpa token, pesanan tercatat tapi harus diaktifkan
   manual.

## Setup server baru
1. Salin isi `app/` ke document root (PHP 7.4+ dengan PDO SQLite, GD, cURL).
2. Salin `app/config.example.php` → `config.php`, isi `ADMIN_HASH` (hasil `password_hash`) dan nama database acak
   (`DB_FILE`).
3. Buka situs — database dan resep contoh dibuat otomatis.
4. Login `admin`, lalu ikuti checklist di bawah.

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

## Checklist serah-terima
1. Ganti kode akses admin utama (Admin → *Ganti kode akses admin utama*).
2. Hapus member contoh `demo`.
3. Isi API key Gemini (AI Gemini) — key `AIza…` dari aistudio.google.com.
4. Isi token webhook Mayar + email admin (Pesanan); daftarkan `https://<domain>/webhook-mayar.php` di Mayar.
5. Ganti foto & teks cover login (Cover).
6. Halaman iklan: jalankan `node landing/build-promo.mjs --live` dan ikuti checklist di `landing/CLAUDE.md`.

## Catatan teknis
- Migrasi database berjalan otomatis saat `lib.php` baru dipakai (kolom baru ditambahkan tanpa menghapus data).
- Semua gambar upload divalidasi dan di-encode ulang di server.
- `api.php?a=recent_orders` (publik) mengembalikan pesanan asli 14 hari terakhir dengan nama disamarkan, untuk
  notifikasi di halaman iklan.
- Gemini default: `gemini-3.8-flash` (analisis, link, OCR) dan `gemini-3.1-flash-image` (tes generate) — bisa diganti
  di AI Gemini. API key diambil dari aistudio.google.com dan **disimpan di database server, tidak di kode**.
- Repo ini belum punya CI. Cek sintaks dijalankan manual (lihat `CLAUDE.md` → Pengujian lokal).
