/* ResepFoto — tab "Voucher": sembilan tingkat diskon (10%..90%). Hanya super admin.
 *
 * PENTING — baca sebelum mengubah file ini:
 * Ada dua jalur, dan bedanya menentukan apa yang boleh dijanjikan ke pengguna.
 *
 *   1. "Buat kupon di Mayar" (dianjurkan) memanggil API Mayar dan benar-benar
 *      membuat kuponnya, lengkap dengan kuota dan tanggal kedaluwarsa.
 *   2. "Catat kode yang sudah ada" hanya menyimpan kode ke katalog lokal. Tidak
 *      membuat apa pun di Mayar.
 *
 * Dalam kedua kasus, potongan harga dan sisa kuota ditegakkan MAYAR saat checkout,
 * karena pembayaran terjadi di domain Mayar. Panel ini antarmukanya, bukan penegaknya.
 * API Mayar tidak punya endpoint hapus/ubah: "Lepas dari panel" hanya membersihkan
 * catatan lokal — kuponnya di Mayar tetap hidup sampai dimatikan lewat dashboard.
 */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP) return;
const { api, toast, esc, copyText, armDelete, state } = APP;
const $ = (s, r = document) => r.querySelector(s);
const isSuper = () => !!(state.user && state.user.adminRole === "super_admin");

/* Link pembayaran Mayar — harus sama dengan CHECKOUT di landing/mockup.html */
const CHECKOUT = {
  Standard: "https://kitlab.myr.id/pl/resepfoto-standard",
  Premium:  "https://kitlab.myr.id/pl/resepfoto-premium"
};

const css = document.createElement("style");
css.textContent = `
#seg-voucher[hidden]{display:none}
.vou-warn{border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:14px;padding:12px 14px;margin:0 0 14px;font-size:13px;line-height:1.55;color:var(--muted)}
.vou-warn b{color:var(--ink)}
.vou-tier{border:1px solid var(--line);border-radius:16px;padding:12px 14px;margin-bottom:10px}
.vou-tier.on{border-color:var(--accent)}
.vou-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.vou-pct{background:var(--accent);color:var(--accent-ink);border-radius:12px;padding:4px 12px;font-size:14px;font-weight:800;min-width:56px;text-align:center}
.vou-tier:not(.on) .vou-pct{background:var(--line);color:var(--muted)}
.vou-state{font-size:12px;color:var(--muted);flex:1}
.vou-toggle{height:32px;padding:0 12px;font-size:12px;font-weight:700;flex:none}
.vou-body{margin-top:10px;display:grid;gap:8px}
.vou-body[hidden]{display:none}
.vou-code{font-family:var(--mono);text-transform:uppercase;letter-spacing:.04em}
.vou-links{display:grid;gap:6px}
.vou-links button{text-align:left;border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:8px 10px;font-size:12px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vou-acts{display:flex;gap:6px;flex-wrap:wrap}
.vou-err{font-size:12px;color:var(--bad);margin:0}
.vou-hint{font-size:12px;color:var(--muted);margin:0}
.vou-hint.risk{color:var(--bad);font-weight:600}
.vou-meta{display:flex;gap:6px;flex-wrap:wrap}
.vou-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
@media (max-width:420px){.vou-two{grid-template-columns:1fr}}
.vou-manual summary{cursor:pointer;font-size:12px;color:var(--muted);font-weight:600}
.vou-manual{border-top:1px dashed var(--line);padding-top:8px;display:grid;gap:8px}
`;
document.head.appendChild(css);

let data = null, busy = false, buka = null;
const box = APP.addAdminTab({ id: "voucher", label: "Voucher", onShow: () => load(), superOnly: true });

function syncTabVisibility(){
  const btn = document.getElementById("seg-voucher");
  if (btn) btn.hidden = !isSuper();
  if (!isSuper() && state.seg === "voucher") state.seg = "prompts";
}
window.addEventListener("rf:admin-render", syncTabVisibility);

function linkFor(code, plan){ return CHECKOUT[plan] + "?coupon=" + encodeURIComponent(code); }
function waTextFor(v){
  return `Halo Kak! ✨\nKhusus buat Kakak, ada potongan *${v.pct}%* untuk ResepFoto.\n\n`
    + `Kode voucher: *${v.code}*\n\n`
    + `Tinggal buka link ini, potongannya otomatis terpasang di halaman pembayaran:\n`
    + `${linkFor(v.code, "Premium")}\n\nSelamat berkreasi! \u{1F4F8}`;
}
/* Tanggal default: 30 hari dari sekarang. Kupon diskon besar sebaiknya berumur pendek. */
function tanggalDefault(){
  const d = new Date(Date.now() + 30 * 864e5);
  return d.toISOString().slice(0, 10);
}
const tanggalTampil = s => s ? new Date(s + "T00:00:00").toLocaleDateString("id-ID", {day: "numeric", month: "short", year: "numeric"}) : "-";
const waktuTampil = s => s ? new Date(s).toLocaleString("id-ID", {day: "numeric", month: "short", hour: "2-digit", minute: "2-digit"}) : "-";

async function load(){
  syncTabVisibility();
  if (!isSuper()){ box.innerHTML = ""; return; }
  if (busy) return;
  if (!data){
    busy = true;
    box.innerHTML = `<p class="vou-state">Memuat…</p>`;
    try { data = await api("vouchers"); }
    catch (e){ box.innerHTML = `<p class="vou-state">${esc(e.message)}</p>`; busy = false; return; }
    busy = false;
  }
  render();
}

function render(){
  const tiers = data.vouchers || [];
  const terisi = tiers.filter(v => v.filled).length;
  const punyaKey = !!data.hasMayarKey;

  box.innerHTML = `
    <div class="vou-warn">
      <b>Kupon dibuat dan ditegakkan oleh Mayar.</b> Panel ini bisa membuatnya lewat API —
      termasuk kuota dan tanggal kedaluwarsa — tapi yang memotong harga dan menghitung sisa
      kuota tetap Mayar di halaman pembayaran. Aplikasi baru tahu setelah webhook masuk.<br><br>
      API Mayar tidak punya perintah hapus atau ubah. <b>"Lepas dari panel" hanya membersihkan
      catatan di sini</b> — kuponnya masih hidup sampai kamu matikan di Mayar → Diskon dan Kupon.
    </div>

    <div class="set-card" style="margin-bottom:14px">
      <div class="set-row"><h3>API key Mayar</h3><span class="pill ${punyaKey ? "ok" : "warn"}">${punyaKey ? "Tersimpan" : "Belum diisi"}</span></div>
      ${punyaKey ? "" : `<div class="warnbox">Tanpa key ini panel hanya bisa mencatat kode, tidak bisa membuat kupon. Ambil di <b>Mayar → Integrasi → API Keys &amp; Token</b>, pilih <b>Read &amp; Write</b>, lalu tempel di bawah.</div>`}
      <div class="field"><label for="vou-key">API key</label>
        <input class="input" id="vou-key" type="password" autocomplete="off"
               placeholder="${punyaKey ? "•••••••• (tersimpan, isi untuk mengganti)" : "tempel API key Read & Write"}"></div>
      <div class="vou-acts">
        <button class="btn btn-primary" type="button" id="vou-key-save">Simpan key</button>
        ${punyaKey ? `<button class="btn btn-ghost" type="button" id="vou-key-clear">Hapus key</button>` : ""}
      </div>
    </div>

    <p class="vou-state" style="margin:0 0 12px">${terisi} dari ${tiers.length} tingkat sudah punya kode. Klik tingkat untuk mengisi.</p>
    ${tiers.map(v => kartu(v, punyaKey)).join("")}`;

  wire();
}

function kartu(v, punyaKey){
  const terbuka = buka === v.pct;
  const status = v.filled
    ? `Kode: <b style="font-family:var(--mono);color:var(--ink)">${esc(v.code)}</b>`
      + (v.diMayar ? "" : " · <i>hanya catatan</i>")
      + (v.active ? "" : " · nonaktif")
    : "Belum ada kode";

  return `
  <div class="vou-tier${v.active ? " on" : ""}">
    <div class="vou-top">
      <span class="vou-pct">${v.pct}%</span>
      <span class="vou-state">${status}</span>
      <button class="btn btn-ghost vou-toggle" type="button" data-vou-open="${v.pct}">${terbuka ? "Tutup" : (v.filled ? "Lihat" : "Buat")}</button>
    </div>
    <div class="vou-body"${terbuka ? "" : " hidden"}>
      ${v.diMayar ? sudahDibuat(v) : formBuat(v, punyaKey)}
    </div>
  </div>`;
}

/* Tingkat yang kuponnya sudah dibuat lewat API: tinggal dipakai dan dipantau. */
function sudahDibuat(v){
  return `
    <div class="vou-meta">
      <span class="pill ok">Dibuat di Mayar</span>
      <span class="pill">Kuota ${v.quota}</span>
      <span class="pill">Berlaku sampai ${esc(tanggalTampil(v.expires))}</span>
      <span class="pill">${v.kind === "onetime" ? "Sekali pakai" : "Berulang"}</span>
    </div>
    ${v.note ? `<p class="vou-hint">${esc(v.note)}</p>` : ""}
    <p class="vou-hint">Status terakhir dibaca ${esc(waktuTampil(v.syncedAt))}. Mayar tidak melaporkan
       berapa kali kupon sudah dipakai — yang bisa dibaca hanya kuota total dan status aktif.</p>
    <div class="vou-links">
      <button type="button" data-vou-link="${esc(linkFor(v.code, "Standard"))}">Salin link Standard (potongan ${v.pct}%)</button>
      <button type="button" data-vou-link="${esc(linkFor(v.code, "Premium"))}">Salin link Premium (potongan ${v.pct}%)</button>
      <button type="button" data-vou-wa="${v.pct}">Salin teks WhatsApp siap kirim</button>
    </div>
    <p class="vou-err" id="ve-${v.pct}" hidden></p>
    <div class="vou-acts">
      <button class="btn btn-ghost" type="button" data-vou-sync="${v.pct}">Perbarui status dari Mayar</button>
      <button class="btn btn-ghost" type="button" data-vou-clear="${v.pct}">Lepas dari panel</button>
    </div>`;
}

/* Tingkat kosong: buat lewat API, atau catat kode yang sudah dibuat manual. */
function formBuat(v, punyaKey){
  return `
    <div class="field"><label for="vc-${v.pct}">Kode voucher</label>
      <input class="input vou-code" id="vc-${v.pct}" maxlength="24" value="${esc(v.code)}"
             placeholder="mis. HEMAT${v.pct}-K7QX" autocomplete="off" spellcheck="false"></div>
    <p class="vou-hint" id="vh-${v.pct}" hidden></p>
    <div class="vou-two">
      <div class="field"><label for="vq-${v.pct}">Kuota pemakaian</label>
        <input class="input" id="vq-${v.pct}" type="number" min="1" max="100000" value="${v.quota || 10}"></div>
      <div class="field"><label for="vx-${v.pct}">Berlaku sampai</label>
        <input class="input" id="vx-${v.pct}" type="date" value="${esc(v.expires || tanggalDefault())}"></div>
    </div>
    <label class="check"><input type="checkbox" id="vo-${v.pct}" checked> Sekali pakai per kode</label>
    <div class="field"><label for="vn-${v.pct}">Catatan (untuk Anda sendiri)</label>
      <input class="input" id="vn-${v.pct}" maxlength="160" value="${esc(v.note)}"
             placeholder="mis. kolaborasi @akunfoto"></div>
    <p class="vou-err" id="ve-${v.pct}" hidden></p>
    <div class="vou-acts">
      <button class="btn btn-primary" type="button" data-vou-create="${v.pct}" ${punyaKey ? "" : "disabled"}>Buat kupon di Mayar</button>
    </div>
    ${punyaKey ? "" : `<p class="vou-hint">Isi API key di atas dulu untuk mengaktifkan tombol ini.</p>`}
    <details class="vou-manual">
      <summary>Atau catat kode yang sudah dibuat manual di Mayar</summary>
      <p class="vou-hint">Ini tidak membuat apa pun — hanya menyimpan kodenya supaya muncul di daftar
         dan bisa dibuatkan link. Kuota &amp; kedaluwarsa tetap diatur di dashboard Mayar.</p>
      <label class="check"><input type="checkbox" id="va-${v.pct}"${v.active ? " checked" : ""}> Aktif</label>
      <div class="vou-acts">
        <button class="btn btn-ghost" type="button" data-vou-save="${v.pct}">Catat kode saja</button>
        ${v.filled ? `<button class="btn btn-ghost" type="button" data-vou-clear="${v.pct}">Kosongkan</button>` : ""}
      </div>
    </details>`;
}

/* Angka di dalam kode dibaca sebagai persen — HEMAT90-K7QX berarti 90%.
   Kalau angkanya tidak cocok dengan tingkatnya, itu hampir selalu salah ketik dan
   pembeli akan dapat potongan yang berbeda dari yang tertulis di kodenya.
   Hanya angka yang memang salah satu tingkat (10..90) yang dianggap sebagai persen,
   supaya "RF2026-K7QX" tidak ikut diributkan. */
const TINGKAT = [10, 20, 30, 40, 50, 60, 70, 80, 90];
function persenDiKode(kode){
  return (String(kode).match(/\d+/g) || []).map(Number).filter(n => TINGKAT.indexOf(n) >= 0);
}
function cekKode(pct){
  const el = $("#vc-" + pct, box), hint = $("#vh-" + pct, box);
  if (!el || !hint) return;
  const angka = persenDiKode(el.value);
  const kuota = Number(($("#vq-" + pct, box) || {}).value || 0);
  const pesan = [];
  if (angka.length && angka.indexOf(pct) < 0) {
    pesan.push(`Kode memuat angka <b>${esc(String(angka[0]))}</b> tapi tingkat ini <b>${pct}%</b> — pembeli akan dapat ${pct}%, bukan ${esc(String(angka[0]))}%.`);
  }
  if (pct >= 50 && kuota > 20) pesan.push(`Potongan ${pct}% dengan kuota ${kuota} berisiko: kode berpola mudah ditebak dan sering disebar ulang.`);
  hint.innerHTML = pesan.join("<br>");
  hint.hidden = pesan.length === 0;
  hint.classList.toggle("risk", pesan.length > 0);
}

function wire(){
  const keySave = $("#vou-key-save", box);
  if (keySave) keySave.onclick = async () => {
    const el = $("#vou-key", box);
    if (!el.value.trim()) { toast("Isi API key dulu", true); return; }
    try { await api("settings_save", {mayarApiKey: el.value.trim()}); toast("API key Mayar disimpan"); data = null; load(); }
    catch (e){ toast(e.message, true); }
  };
  const keyClear = $("#vou-key-clear", box);
  if (keyClear) keyClear.onclick = () => armDelete(keyClear, async () => {
    try { await api("settings_save", {clearMayarKey: true}); toast("API key dihapus"); data = null; load(); }
    catch (e){ toast(e.message, true); }
  });

  box.querySelectorAll("[data-vou-open]").forEach(el => {
    const bukaTingkat = () => { const p = Number(el.dataset.vouOpen); buka = buka === p ? null : p; render(); };
    el.onclick = bukaTingkat;
    el.onkeydown = e => { if (e.key === "Enter" || e.key === " "){ e.preventDefault(); bukaTingkat(); } };
  });
  box.querySelectorAll("[data-vou-link]").forEach(b => b.onclick = () => { copyText(b.dataset.vouLink); toast("Link disalin"); });
  box.querySelectorAll("[data-vou-wa]").forEach(b => b.onclick = () => {
    const v = (data.vouchers || []).find(x => x.pct === Number(b.dataset.vouWa));
    if (v){ copyText(waTextFor(v)); toast("Teks WhatsApp disalin"); }
  });

  if (buka !== null){
    const kode = $("#vc-" + buka, box), kuota = $("#vq-" + buka, box);
    if (kode) kode.oninput = () => cekKode(buka);
    if (kuota) kuota.oninput = () => cekKode(buka);
    if (kode) cekKode(buka);
  }

  box.querySelectorAll("[data-vou-create]").forEach(b => b.onclick = async () => {
    const p = Number(b.dataset.vouCreate);
    const err = $("#ve-" + p, box); err.hidden = true;
    b.disabled = true; const label = b.textContent; b.textContent = "Membuat di Mayar…";
    try {
      await api("voucher_create", {
        pct: p,
        code: $("#vc-" + p, box).value.trim().toUpperCase(),
        quota: Number($("#vq-" + p, box).value),
        expires: $("#vx-" + p, box).value,
        onetime: $("#vo-" + p, box).checked ? 1 : "",
        note: $("#vn-" + p, box).value.trim()
      });
      toast(`Kupon ${p}% dibuat di Mayar`);
      data = null; buka = p; await load();
    } catch (e){ err.textContent = e.message; err.hidden = false; b.disabled = false; b.textContent = label; }
  });

  box.querySelectorAll("[data-vou-sync]").forEach(b => b.onclick = async () => {
    const p = Number(b.dataset.vouSync);
    const err = $("#ve-" + p, box); err.hidden = true;
    b.disabled = true;
    try { await api("voucher_sync", {pct: p}); toast("Status diperbarui"); data = null; await load(); }
    catch (e){ err.textContent = e.message; err.hidden = false; b.disabled = false; }
  });

  box.querySelectorAll("[data-vou-save]").forEach(b => b.onclick = async () => {
    const p = Number(b.dataset.vouSave);
    const err = $("#ve-" + p, box); err.hidden = true;
    try {
      await api("voucher_save", {
        pct: p,
        code: $("#vc-" + p, box).value.trim().toUpperCase(),
        note: $("#vn-" + p, box).value.trim(),
        active: $("#va-" + p, box).checked ? 1 : ""
      });
      toast($("#vc-" + p, box).value.trim() ? "Tercatat — kuponnya sendiri harus sudah ada di Mayar" : "Kode dikosongkan");
      data = null; await load();
    } catch (e){ err.textContent = e.message; err.hidden = false; }
  });

  box.querySelectorAll("[data-vou-clear]").forEach(b => b.onclick = () => armDelete(b, async () => {
    try {
      await api("voucher_delete", { pct: Number(b.dataset.vouClear) });
      toast("Catatan dilepas — kuponnya di Mayar masih hidup");
      data = null; load();
    } catch (e){ toast(e.message, true); }
  }));
}
})();
