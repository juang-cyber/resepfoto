/* ResepFoto — tab "Voucher": katalog kode voucher + pembuat link pembayaran. Hanya super admin.
 *
 * PENTING — baca sebelum mengubah file ini:
 * Panel ini TIDAK memotong harga dan TIDAK membuat kupon. Potongan dihitung dan
 * divalidasi oleh Mayar lewat parameter ?coupon= pada link pembayaran. Yang disimpan
 * di sini hanya catatan supaya pemilik punya satu tempat melihat kode yang beredar,
 * plus pembuat link siap salin (tempat salah ketik paling sering terjadi).
 * Konsekuensinya: menghapus baris di sini tidak mematikan kupon di Mayar.
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
const PCTS = [10, 20, 30, 40, 50, 60, 70, 80, 90];

const css = document.createElement("style");
css.textContent = `
#seg-voucher[hidden]{display:none}
.vou-warn{border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:14px;padding:12px 14px;margin:0 0 14px;font-size:13px;line-height:1.55;color:var(--muted)}
.vou-warn b{color:var(--ink)}
.vou-form{border:1px solid var(--line);border-radius:18px;padding:14px;display:grid;gap:12px;margin-bottom:14px}
.vou-form[hidden]{display:none}
.vou-pcts{display:flex;flex-wrap:wrap;gap:6px}
.vou-pcts button{min-width:52px;height:36px;border-radius:12px;border:1px solid var(--line);background:var(--surface);font-weight:700;font-size:13px;color:var(--ink)}
.vou-pcts button[aria-pressed="true"]{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.vou-code{font-family:var(--mono);text-transform:uppercase}
.vou-row{border:1px solid var(--line);border-radius:16px;padding:12px 14px;margin-bottom:10px}
.vou-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.vou-badge{font-family:var(--mono);font-weight:700;font-size:14px}
.vou-pct{background:var(--accent);color:var(--accent-ink);border-radius:999px;padding:2px 10px;font-size:12px;font-weight:700}
.vou-off{background:var(--line);color:var(--muted);border-radius:999px;padding:2px 10px;font-size:12px;font-weight:700}
.vou-note{font-size:12px;color:var(--muted);margin:6px 0 0}
.vou-links{display:grid;gap:6px;margin-top:10px}
.vou-links button{text-align:left;border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:8px 10px;font-size:12px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vou-acts{display:flex;gap:6px;margin-top:10px}
.vou-empty{color:var(--muted);font-size:13px;padding:8px 2px}
`;
document.head.appendChild(css);

let data = null, busy = false, editing = null, pct = 20;
const box = APP.addAdminTab({ id: "voucher", label: "Voucher", onShow: () => load(), superOnly: true });

function syncTabVisibility(){
  const btn = document.getElementById("seg-voucher");
  if (btn) btn.hidden = !isSuper();
  if (!isSuper() && state.seg === "voucher") state.seg = "prompts";
}
document.addEventListener("rf:admin-render", syncTabVisibility);

function linkFor(code, plan){ return CHECKOUT[plan] + "?coupon=" + encodeURIComponent(code); }
function waTextFor(v){
  return `Halo Kak! ✨\nKhusus buat Kakak, ada potongan ${v.pct}% untuk ResepFoto.\n\n`
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
    box.innerHTML = `<p class="vou-empty">Memuat…</p>`;
    try { data = await api("vouchers"); }
    catch (e){ box.innerHTML = `<p class="vou-empty">${esc(e.message)}</p>`; busy = false; return; }
    busy = false;
  }
  render();
}

function render(){
  const list = data.vouchers || [];
  const baris = list.map(v => `
    <div class="vou-row">
      <div class="vou-head">
        <span class="vou-badge">${esc(v.code)}</span>
        <span class="${v.active ? "vou-pct" : "vou-off"}">${v.pct}%${v.active ? "" : " · nonaktif"}</span>
      </div>
      ${v.note ? `<p class="vou-note">${esc(v.note)}</p>` : ""}
      <div class="vou-links">
        <button type="button" data-link="${esc(linkFor(v.code, "Standard"))}">Salin link Standard</button>
        <button type="button" data-link="${esc(linkFor(v.code, "Premium"))}">Salin link Premium</button>
        <button type="button" data-wa="${esc(v.code)}">Salin teks WhatsApp siap kirim</button>
      </div>
      <div class="vou-acts">
        <button class="btn btn-ghost" type="button" data-edit="${esc(v.code)}">Ubah</button>
        <button class="btn btn-ghost" type="button" data-del="${esc(v.code)}">Hapus</button>
      </div>
    </div>`).join("");

  box.innerHTML = `
    <div class="vou-warn">
      <b>Panel ini tidak memotong harga.</b> Kuponnya milik Mayar — potongan dihitung dan
      diperiksa di halaman pembayaran Mayar. Menambah kode di sini <b>tidak membuat</b> kupon,
      menghapusnya <b>tidak mematikan</b> kupon.<br><br>
      Jadi setiap kode harus dibuat dua kali: di <b>Mayar → Diskon dan Kupon</b> (tipe persentase,
      reusable, isi <b>batas pemakaian</b> dan <b>tanggal kedaluwarsa</b>), lalu dicatat di sini.
      Mematikan kampanye juga dua langkah: nonaktifkan di sini <b>dan</b> di Mayar.
    </div>
    <button class="btn btn-primary" type="button" id="vou-add">Tambah voucher</button>
    <div class="vou-form" id="vou-form" hidden>
      <div class="field"><label for="vou-code">Kode voucher</label>
        <input class="input vou-code" id="vou-code" maxlength="24" placeholder="mis. HEMAT20-K7QX" autocomplete="off"></div>
      <div class="field"><label>Potongan</label>
        <div class="vou-pcts" id="vou-pcts">${PCTS.map(p => `<button type="button" data-pct="${p}" aria-pressed="false">${p}%</button>`).join("")}</div></div>
      <div class="field"><label for="vou-note">Catatan (untuk Anda sendiri)</label>
        <input class="input" id="vou-note" maxlength="160" placeholder="mis. kolaborasi @akunfoto, berlaku sampai 30 Sep"></div>
      <label class="switch"><input type="checkbox" id="vou-active" checked> <span>Aktif</span></label>
      <p class="vou-note" id="vou-error" hidden></p>
      <div class="vou-acts">
        <button class="btn btn-primary" type="button" id="vou-save">Simpan</button>
        <button class="btn btn-ghost" type="button" id="vou-cancel">Batal</button>
      </div>
    </div>
    ${baris || `<p class="vou-empty">Belum ada voucher yang dicatat.</p>`}`;

  wire();
}

function setPct(p){
  pct = p;
  box.querySelectorAll("[data-pct]").forEach(b => b.setAttribute("aria-pressed", Number(b.dataset.pct) === p ? "true" : "false"));
}

function openForm(v){
  editing = v ? v.code : null;
  const f = $("#vou-form", box); f.hidden = false;
  $("#vou-code", box).value = v ? v.code : "";
  $("#vou-code", box).disabled = !!v;
  $("#vou-note", box).value = v ? v.note : "";
  $("#vou-active", box).checked = v ? v.active : true;
  setPct(v ? v.pct : 20);
  $("#vou-error", box).hidden = true;
  $("#vou-code", box).focus();
}

function wire(){
  setPct(pct);
  $("#vou-add", box).onclick = () => openForm(null);
  $("#vou-cancel", box).onclick = () => { $("#vou-form", box).hidden = true; editing = null; };
  box.querySelectorAll("[data-pct]").forEach(b => b.onclick = () => setPct(Number(b.dataset.pct)));

  box.querySelectorAll("[data-link]").forEach(b => b.onclick = () => { copyText(b.dataset.link); toast("Link disalin"); });
  box.querySelectorAll("[data-wa]").forEach(b => b.onclick = () => {
    const v = (data.vouchers || []).find(x => x.code === b.dataset.wa);
    if (v){ copyText(waTextFor(v)); toast("Teks WhatsApp disalin"); }
  });
  box.querySelectorAll("[data-edit]").forEach(b => b.onclick = () => {
    const v = (data.vouchers || []).find(x => x.code === b.dataset.edit);
    if (v) openForm(v);
  });
  box.querySelectorAll("[data-del]").forEach(b => b.onclick = () => armDelete(b, async () => {
    try { await api("voucher_delete", { code: b.dataset.del }); toast("Dihapus dari daftar — kuponnya di Mayar masih hidup"); data = null; load(); }
    catch (e){ toast(e.message, true); }
  }));

  $("#vou-save", box).onclick = async () => {
    const err = $("#vou-error", box); err.hidden = true;
    const payload = {
      code: $("#vou-code", box).value.trim().toUpperCase(),
      pct, note: $("#vou-note", box).value.trim(),
      active: $("#vou-active", box).checked ? 1 : ""
    };
    if (!editing) payload.isNew = 1;
    try {
      await api("voucher_save", payload);
      toast(editing ? "Voucher diperbarui" : "Voucher dicatat — jangan lupa buat kuponnya di Mayar");
      editing = null; data = null; await load();
    } catch (e){ err.textContent = e.message; err.hidden = false; }
  };
}
})();
