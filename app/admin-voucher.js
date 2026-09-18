/* ResepFoto — tab "Voucher": sembilan template tingkat diskon + pembuat link. Hanya super admin.
 *
 * PENTING — baca sebelum mengubah file ini:
 * Panel ini TIDAK memotong harga dan TIDAK membuat kupon. Potongan dihitung dan
 * divalidasi Mayar lewat parameter ?coupon= pada link pembayaran. Yang ada di sini
 * cuma sembilan template tingkat (10%..90%) yang tinggal diisi kodenya, plus pembuat
 * link siap salin — tempat salah ketik paling sering terjadi.
 * Konsekuensinya: mengosongkan kode di sini tidak mematikan kupon di Mayar.
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
.vou-state{font-size:12px;color:var(--muted)}
.vou-body{margin-top:10px;display:grid;gap:8px}
.vou-body[hidden]{display:none}
.vou-code{font-family:var(--mono);text-transform:uppercase;letter-spacing:.04em}
.vou-links{display:grid;gap:6px}
.vou-links button{text-align:left;border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:8px 10px;font-size:12px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vou-acts{display:flex;gap:6px;flex-wrap:wrap}
.vou-err{font-size:12px;color:var(--bad);margin:0}
`;
document.head.appendChild(css);

let data = null, busy = false, buka = null;
const box = APP.addAdminTab({ id: "voucher", label: "Voucher", onShow: () => load(), superOnly: true });

function syncTabVisibility(){
  const btn = document.getElementById("seg-voucher");
  if (btn) btn.hidden = !isSuper();
  if (!isSuper() && state.seg === "voucher") state.seg = "prompts";
}
document.addEventListener("rf:admin-render", syncTabVisibility);

function linkFor(code, plan){ return CHECKOUT[plan] + "?coupon=" + encodeURIComponent(code); }
function waTextFor(v){
  return `Halo Kak! ✨\nKhusus buat Kakak, ada potongan *${v.pct}%* untuk ResepFoto.\n\n`
    + `Kode voucher: *${v.code}*\n\n`
    + `Tinggal buka link ini, potongannya otomatis terpasang di halaman pembayaran:\n`
    + `${linkFor(v.code, "Premium")}\n\nSelamat berkreasi! \u{1F4F8}`;
}

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

  box.innerHTML = `
    <div class="vou-warn">
      <b>Panel ini tidak memotong harga.</b> Kuponnya milik Mayar — potongan dihitung dan
      diperiksa di halaman pembayaran Mayar. Mengisi kode di sini <b>tidak membuat</b> kupon,
      mengosongkannya <b>tidak mematikan</b> kupon.<br><br>
      Alurnya: buat kuponnya dulu di <b>Mayar → Diskon dan Kupon</b> (tipe persentase, reusable,
      wajib isi <b>batas pemakaian</b> dan <b>tanggal kedaluwarsa</b>), lalu tempel kodenya di
      tingkat yang cocok di bawah ini. Mematikan kampanye juga dua langkah: kosongkan di sini
      <b>dan</b> nonaktifkan di Mayar.
    </div>
    <p class="vou-state" style="margin:0 0 12px">${terisi} dari ${tiers.length} tingkat sudah punya kode. Klik tingkat untuk mengisi.</p>
    ${tiers.map(v => kartu(v)).join("")}`;

  wire();
}

function kartu(v){
  const terbuka = buka === v.pct;
  return `
  <div class="vou-tier${v.active ? " on" : ""}">
    <div class="vou-top" data-open="${v.pct}" role="button" tabindex="0" style="cursor:pointer">
      <span class="vou-pct">${v.pct}%</span>
      <span class="vou-state">${v.filled
        ? `Kode: <b style="font-family:var(--mono);color:var(--ink)">${esc(v.code)}</b>${v.active ? "" : " · nonaktif"}`
        : "Belum ada kode — klik untuk mengisi"}</span>
    </div>
    <div class="vou-body"${terbuka ? "" : " hidden"}>
      <div class="field"><label for="vc-${v.pct}">Kode voucher di Mayar</label>
        <input class="input vou-code" id="vc-${v.pct}" maxlength="24" value="${esc(v.code)}"
               placeholder="mis. HEMAT${v.pct}-K7QX" autocomplete="off" spellcheck="false"></div>
      <div class="field"><label for="vn-${v.pct}">Catatan (untuk Anda sendiri)</label>
        <input class="input" id="vn-${v.pct}" maxlength="160" value="${esc(v.note)}"
               placeholder="mis. kolaborasi @akunfoto, berlaku sampai 30 Sep"></div>
      <label class="switch"><input type="checkbox" id="va-${v.pct}"${v.active ? " checked" : ""}> <span>Aktif</span></label>
      <p class="vou-err" id="ve-${v.pct}" hidden></p>
      ${v.filled ? `
      <div class="vou-links">
        <button type="button" data-link="${esc(linkFor(v.code, "Standard"))}">Salin link Standard (potongan ${v.pct}%)</button>
        <button type="button" data-link="${esc(linkFor(v.code, "Premium"))}">Salin link Premium (potongan ${v.pct}%)</button>
        <button type="button" data-wa="${v.pct}">Salin teks WhatsApp siap kirim</button>
      </div>` : ""}
      <div class="vou-acts">
        <button class="btn btn-primary" type="button" data-save="${v.pct}">Simpan</button>
        ${v.filled ? `<button class="btn btn-ghost" type="button" data-clear="${v.pct}">Kosongkan</button>` : ""}
      </div>
    </div>
  </div>`;
}

function wire(){
  box.querySelectorAll("[data-open]").forEach(el => {
    const bukaTingkat = () => { const p = Number(el.dataset.open); buka = buka === p ? null : p; render(); };
    el.onclick = bukaTingkat;
    el.onkeydown = e => { if (e.key === "Enter" || e.key === " "){ e.preventDefault(); bukaTingkat(); } };
  });
  box.querySelectorAll("[data-link]").forEach(b => b.onclick = () => { copyText(b.dataset.link); toast("Link disalin"); });
  box.querySelectorAll("[data-wa]").forEach(b => b.onclick = () => {
    const v = (data.vouchers || []).find(x => x.pct === Number(b.dataset.wa));
    if (v){ copyText(waTextFor(v)); toast("Teks WhatsApp disalin"); }
  });
  box.querySelectorAll("[data-save]").forEach(b => b.onclick = async () => {
    const p = Number(b.dataset.save);
    const err = $("#ve-" + p, box); err.hidden = true;
    try {
      await api("voucher_save", {
        pct: p,
        code: $("#vc-" + p, box).value.trim().toUpperCase(),
        note: $("#vn-" + p, box).value.trim(),
        active: $("#va-" + p, box).checked ? 1 : ""
      });
      toast($("#vc-" + p, box).value.trim() ? "Tersimpan — pastikan kuponnya sudah ada di Mayar" : "Kode dikosongkan");
      data = null; await load();
    } catch (e){ err.textContent = e.message; err.hidden = false; }
  });
  box.querySelectorAll("[data-clear]").forEach(b => b.onclick = () => armDelete(b, async () => {
    try {
      await api("voucher_delete", { pct: Number(b.dataset.clear) });
      toast("Kode dikosongkan — kuponnya di Mayar masih hidup");
      data = null; load();
    } catch (e){ toast(e.message, true); }
  }));
}
})();
