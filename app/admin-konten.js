/* ResepFoto — tab "Konten": ekspor & impor resep lewat file Excel. Hanya super admin.
 *
 * PENTING — baca sebelum mengubah file ini:
 * File Excel ini memegang SELURUH katalog sekaligus, jadi aturannya dibuat ketat
 * di server (lihat blok prompts_import di api.php) dan diulang di sini supaya
 * yang dijanjikan UI sama persis dengan yang dikerjakan server:
 *
 *   1. Impor TIDAK PERNAH MENGHAPUS. Resep yang tidak ada di file dibiarkan.
 *   2. Baris dicocokkan lewat kolom id. id yang tidak dikenal DITOLAK, bukan
 *      dibuatkan resep baru. Resep baru dibuat dengan mengosongkan kolom id.
 *   3. tanggal_unggah resep lama diabaikan — kolom itu ikut jatah Trial.
 *   4. gambar kosong = pertahankan gambar lama.
 *
 * Tombol "Terapkan" sengaja hanya hidup sesudah file yang SAMA diperiksa dulu.
 * Ganti file = pratinjau hangus, harus periksa ulang.
 *
 * Atribut data-* diberi awalan kon- supaya tidak disambar penangkap klik
 * tingkat-dokumen di index.html (data-open, data-edit-prompt, dan kawan-kawan).
 */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP) return;
const { api, toast, esc, state } = APP;
const $ = (s, r = document) => r.querySelector(s);
const isSuper = () => !!(state.user && state.user.adminRole === "super_admin");

const css = document.createElement("style");
css.textContent = `
#seg-konten[hidden]{display:none}
.kon-warn{border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:14px;padding:12px 14px;margin:0 0 14px;font-size:13px;line-height:1.55;color:var(--muted)}
.kon-warn b{color:var(--ink)}
.kon-warn ul{margin:8px 0 0;padding-left:18px;display:grid;gap:4px}
.kon-card{border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px}
.kon-card h3{margin:0 0 4px;font-size:15px}
.kon-card p{margin:0 0 10px;font-size:12px;color:var(--muted);line-height:1.55}
.kon-acts{display:flex;gap:8px;flex-wrap:wrap}
.kon-file{display:block;width:100%;border:1px dashed var(--line);border-radius:12px;padding:10px;font-size:12px;color:var(--muted);background:var(--surface)}
.kon-file::file-selector-button{margin-right:10px;border:0;border-radius:9px;padding:7px 12px;font-size:12px;font-weight:700;background:var(--line);color:var(--ink);cursor:pointer}
.kon-res{margin-top:12px;border-top:1px dashed var(--line);padding-top:12px;display:grid;gap:8px}
.kon-res[hidden]{display:none}
.kon-sum{display:flex;gap:6px;flex-wrap:wrap}
.kon-num{border:1px solid var(--line);border-radius:11px;padding:5px 10px;font-size:12px;color:var(--muted)}
.kon-num b{color:var(--ink);font-size:14px}
.kon-num.add b{color:var(--ok,#2a9d5c)}
.kon-num.bad b{color:var(--bad)}
.kon-err{margin:0;padding:0;list-style:none;display:grid;gap:4px;max-height:220px;overflow:auto}
.kon-err li{font-size:12px;color:var(--bad);border:1px solid var(--line);border-radius:10px;padding:6px 9px;line-height:1.5}
.kon-err li b{font-family:var(--mono)}
.kon-note{font-size:12px;color:var(--muted);margin:0;line-height:1.55}
.kon-note.risk{color:var(--bad);font-weight:600}
.kon-cols{font-size:11px;color:var(--muted);font-family:var(--mono);line-height:1.7;word-break:break-word;margin:0}
.kon-card details summary{cursor:pointer;font-size:12px;color:var(--muted);font-weight:600}
.kon-card details{margin-top:8px}
`;
document.head.appendChild(css);

const KOLOM = ["id", "urutan", "kategori", "kategori_en", "judul", "judul_en", "deskripsi", "deskripsi_en",
  "prompt", "tips", "tips_en", "alat", "best_seller", "english_saja", "gambar",
  "status_qc", "hasil", "penulis", "tanggal_unggah", "tanggal_ubah"];

let sibuk = false;
let diperiksa = null;   // {nama, ukuran, waktu} file yang pratinjaunya masih berlaku
const box = APP.addAdminTab({ id: "konten", label: "Konten", onShow: () => render(), superOnly: true });

function syncTabVisibility(){
  const btn = document.getElementById("seg-konten");
  if (btn) btn.hidden = !isSuper();
  if (!isSuper() && state.seg === "konten") state.seg = "prompts";
}
window.addEventListener("rf:admin-render", syncTabVisibility);

function fileTerpilih(){
  const inp = $("#kon-file");
  return inp && inp.files && inp.files[0] ? inp.files[0] : null;
}
/** Pratinjau hanya berlaku untuk file yang persis sama. */
function pratinjauBerlaku(){
  const f = fileTerpilih();
  return !!(f && diperiksa && diperiksa.nama === f.name && diperiksa.ukuran === f.size && diperiksa.waktu === f.lastModified);
}

function render(){
  const n = (state.prompts || []).length;
  box.innerHTML = `
    <div class="kon-warn">
      <b>Ekspor &amp; impor resep lewat Excel.</b> Unduh seluruh katalog, sunting massal di Excel, lalu unggah balik.
      Yang perlu diingat:
      <ul>
        <li><b>Impor tidak pernah menghapus.</b> Resep yang tidak ada di file dibiarkan apa adanya.</li>
        <li>Baris dicocokkan lewat kolom <b>id</b>. Biarkan id apa adanya untuk menyunting; <b>kosongkan id</b> untuk resep baru.</li>
        <li>Kolom <b>tanggal_unggah</b> resep lama diabaikan — tanggal itu ikut menentukan jatah katalog member.</li>
        <li>Kolom <b>gambar</b> dikosongkan berarti gambar lama dipertahankan. Gambar baru tetap diunggah lewat tab Resep.</li>
      </ul>
    </div>

    <div class="kon-card">
      <h3>Ekspor</h3>
      <p>Unduh <b>${n}</b> resep sebagai satu file Excel berisi ${KOLOM.length} kolom, termasuk tanggal unggah tiap resep.</p>
      <div class="kon-acts"><button class="btn btn-primary" type="button" data-kon-export>Unduh Excel</button></div>
      <details>
        <summary>Lihat daftar kolom</summary>
        <p class="kon-cols">${KOLOM.map(k => esc(k)).join(" · ")}</p>
        <p class="kon-note">Tanggal ditulis sebagai teks ISO (contoh <b>2026-09-19T06:35:00+00:00</b>), bukan tanggal Excel,
          supaya isinya tidak bergeser saat bolak-balik ekspor–impor.</p>
      </details>
    </div>

    <div class="kon-card">
      <h3>Impor</h3>
      <p>Pilih file hasil ekspor yang sudah disunting. Periksa dulu untuk melihat dampaknya — belum ada yang berubah
        sampai kamu menekan Terapkan.</p>
      <input class="kon-file" type="file" id="kon-file" accept=".xlsx,.csv" aria-label="File Excel untuk diimpor">
      <div class="kon-acts" style="margin-top:10px">
        <button class="btn btn-primary" type="button" data-kon-check disabled>Periksa dulu</button>
        <button class="btn btn-danger" type="button" data-kon-apply hidden>Terapkan perubahan</button>
      </div>
      <div class="kon-res" id="kon-res" hidden></div>
    </div>`;

  $("#kon-file").addEventListener("change", () => {
    diperiksa = null;                       // ganti file = pratinjau hangus
    $("#kon-res").hidden = true;
    segarkanTombol();
  });
  box.querySelector("[data-kon-export]").addEventListener("click", unduh);
  box.querySelector("[data-kon-check]").addEventListener("click", () => jalankan(true));
  box.querySelector("[data-kon-apply]").addEventListener("click", () => jalankan(false));
  segarkanTombol();
}

function segarkanTombol(){
  const cek = box.querySelector("[data-kon-check]");
  const ter = box.querySelector("[data-kon-apply]");
  if (cek) cek.disabled = sibuk || !fileTerpilih();
  if (ter){
    ter.hidden = !pratinjauBerlaku() || !diperiksa || !(diperiksa.tambah || diperiksa.perbarui);
    ter.disabled = sibuk;
  }
}

async function unduh(){
  if (sibuk) return;
  sibuk = true; segarkanTombol();
  try {
    const res = await fetch("api.php?a=prompts_export", { credentials: "same-origin", headers: { "X-Lang": APP.lang } });
    const ct = res.headers.get("Content-Type") || "";
    if (!res.ok || ct.indexOf("application/json") !== -1){
      let pesan = "Ekspor gagal.";
      try { const d = await res.json(); if (d && d.error) pesan = d.error; } catch (e) {}
      throw new Error(pesan);
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "resepfoto-resep-" + new Date().toISOString().slice(0, 10) + ".xlsx";
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
    toast("File Excel diunduh");
  } catch (e){ toast(e.message || "Ekspor gagal", true); }
  sibuk = false; segarkanTombol();
}

async function jalankan(dry){
  if (sibuk) return;
  const f = fileTerpilih();
  if (!f) return toast("Pilih dulu filenya", true);
  if (!dry && !pratinjauBerlaku()) return toast("Periksa dulu filenya", true);
  sibuk = true; segarkanTombol();
  try {
    const fd = new FormData();
    fd.append("file", f);
    fd.append("dryRun", dry ? "1" : "0");
    const r = await api("prompts_import", fd, true);
    if (dry){
      diperiksa = { nama: f.name, ukuran: f.size, waktu: f.lastModified, tambah: r.tambah, perbarui: r.perbarui };
    } else {
      diperiksa = null;
      toast("Impor selesai: " + r.tambah + " ditambah, " + r.perbarui + " diperbarui");
      await muatUlang();
      // render ulang tab ini juga: jumlah resep di kartu Ekspor ikut berubah,
      // dan sekalian mengosongkan pilihan file. renderAll() tidak menyentuh tab
      // tambahan, jadi harus dipanggil sendiri.
      render();
    }
    tampilkanHasil(r, dry);
  } catch (e){ toast(e.message || "Impor gagal", true); }
  sibuk = false; segarkanTombol();
}

/** Sesudah impor sungguhan, katalog di layar harus ikut berubah. */
async function muatUlang(){
  // loadData() milik index.html sekaligus menyegarkan prompts, authors, dan render.
  try { await APP.loadData(); } catch (e) {}
}

function tampilkanHasil(r, dry){
  const el = $("#kon-res");
  if (!el) return;
  const num = (cls, n, label) => `<span class="kon-num ${cls}"><b>${n}</b> ${esc(label)}</span>`;
  const abai = [];
  if (r.abaiTanggal) abai.push(r.abaiTanggal + " baris tanggal_unggah");
  if (r.abaiPenulis) abai.push(r.abaiPenulis + " baris penulis");
  el.hidden = false;
  el.innerHTML =
    `<p class="kon-note">${dry ? "<b>Pratinjau</b> — belum ada yang berubah." : "<b>Sudah diterapkan.</b>"}</p>
     <div class="kon-sum">
       ${num("add", r.tambah, "ditambah")}
       ${num("", r.perbarui, "diperbarui")}
       ${num(r.lewat ? "bad" : "", r.lewat, "dilewati")}
     </div>
     ${abai.length ? `<p class="kon-note">Diabaikan sesuai aturan: ${esc(abai.join(", "))}. Nilai lama dipertahankan.</p>` : ""}
     ${r.galat && r.galat.length ? `<p class="kon-note risk">Baris yang dilewati (nomor baris mengikuti Excel):</p>
       <ul class="kon-err">${r.galat.map(g => `<li><b>Baris ${g.baris}</b> — ${esc(g.pesan)}</li>`).join("")}</ul>
       ${r.lewat > r.galat.length ? `<p class="kon-note">…dan ${r.lewat - r.galat.length} baris lain.</p>` : ""}` : ""}
     ${dry && (r.tambah || r.perbarui) ? `<p class="kon-note">Tekan <b>Terapkan perubahan</b> kalau angka di atas sudah sesuai.</p>` : ""}
     ${dry && !r.tambah && !r.perbarui ? `<p class="kon-note risk">Tidak ada baris yang bisa diterapkan.</p>` : ""}`;
}
})();
