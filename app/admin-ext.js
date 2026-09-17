/* ResepFoto — tab "Pesanan" (Mayar) di panel admin */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP) return;
const { api, toast, esc, copyText, fmtDate } = APP;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const rp = n => "Rp " + Number(n || 0).toLocaleString("id-ID");
const when = iso => iso ? new Date(iso).toLocaleString("id-ID", {day: "numeric", month: "short", hour: "2-digit", minute: "2-digit"}) : "-";
const ico = id => `<svg class="ico"><use href="#i-${id}"/></svg>`;

const css = document.createElement("style");
css.textContent = `
.ord-card{border-radius:18px;background:var(--surface-2);padding:12px;display:grid;gap:8px}
.ord-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.ord-top strong{display:block;font-size:14px}
.ord-top small{color:var(--muted);font-size:12px;word-break:break-all}
.ord-amt{font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
.ord-actions{display:flex;flex-wrap:wrap;gap:6px}
.ord-actions button{height:34px;padding:0 12px;border-radius:10px;font-size:12px;font-weight:700;background:var(--surface);border:1px solid var(--line);color:var(--ink)}
.ord-actions button.main{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.ord-actions button.warn{color:var(--bad)}
.set-card{border-radius:20px;border:1px solid var(--line);padding:14px;display:grid;gap:12px;margin-bottom:12px}
.set-card h3{font-size:15px;margin:0}
.set-row{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px}
.urlbox{font-family:var(--mono);font-size:11.5px;background:var(--surface-2);border-radius:10px;padding:8px 10px;word-break:break-all;flex:1}
.log{font-size:11.5px;color:var(--ink2);display:grid;gap:6px;max-height:220px;overflow:auto}
.log div{background:var(--surface-2);border-radius:10px;padding:7px 9px}
.log b{color:var(--ink)}
.warnbox{font-size:12px;border-radius:12px;padding:10px 12px;background:var(--warn-soft);color:var(--warn);font-weight:600}
`;
document.head.appendChild(css);

// tab + container (lewat registry tab admin di index.html)
let data = null, busy = false;
const box = APP.addAdminTab({id: "orders", label: "Pesanan", onShow: () => load()});

async function load(){
  if (busy) return; busy = true;
  if (!data) box.innerHTML = `<div class="empty">Memuat pesanan…</div>`;
  try { data = await api("orders"); render(); }
  catch (e) { box.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
  finally { busy = false; }
}

const STATE = {
  aktif: ["ok", "Aktif"], lunas: ["accent", "Lunas"], perlu_cek: ["warn", "Perlu dicek"],
  belum_lunas: ["", "Belum lunas"], ditolak: ["bad", "Ditolak"]
};

function render(){
  const s = data.settings, orders = data.orders, log = data.log;
  const pending = orders.filter(o => o.state === "perlu_cek" || o.state === "lunas").length;
  box.innerHTML = `
    <div class="set-card">
      <div class="set-row"><h3>Koneksi Mayar</h3><span class="pill ${s.hasToken ? "ok" : "warn"}">${s.hasToken ? "Token tersimpan" : "Token belum diisi"}</span></div>
      <div class="set-row"><span class="urlbox" id="wh-url">${esc(s.webhookUrl)}</span><button class="sq" id="wh-copy" aria-label="Salin URL webhook">${ico("copy")}</button></div>
      ${s.hasToken ? "" : `<div class="warnbox">Pesanan belum bisa aktif otomatis. Salin <b>Webhook Token</b> dari Mayar (Integrasi → API Keys &amp; Token) lalu tempel di bawah.</div>`}
      <div class="field"><label for="set-token">Webhook Token Mayar</label><input class="input" id="set-token" type="password" autocomplete="off" placeholder="${s.hasToken ? "•••••••• (tersimpan, isi untuk mengganti)" : "tempel token dari Mayar"}"></div>
      <div class="two">
        <div class="field"><label for="set-admin">Email notifikasi admin</label><input class="input" id="set-admin" type="email" value="${esc(s.adminEmail)}" placeholder="kamu@email.com"></div>
        <div class="field"><label for="set-from">Email pengirim</label><input class="input" id="set-from" type="email" value="${esc(s.mailFrom)}"></div>
      </div>
      <label class="check"><input type="checkbox" id="set-auto" ${s.autoWithoutToken ? "checked" : ""}>Aktifkan otomatis walau token belum diisi (tidak disarankan)</label>
      <div class="ord-actions">
        <button class="main" id="set-save">Simpan pengaturan</button>
        <button id="set-test">Kirim email tes</button>
        ${s.hasToken ? `<button class="warn" id="set-clear">Hapus token</button>` : ""}
      </div>
    </div>

    <div class="set-row" style="margin:6px 2px 10px"><h3 style="margin:0;font-size:15px">Pesanan ${pending ? `<span class="pill warn">${pending} perlu tindakan</span>` : ""}</h3><button class="sq" id="ord-refresh" aria-label="Muat ulang">↻</button></div>
    <div class="list" id="ord-list">
      ${orders.length ? orders.map(card).join("") : `<div class="empty"><strong>Belum ada pesanan</strong><span>Pesanan dari Mayar akan muncul di sini otomatis.</span></div>`}
    </div>

    <details class="set-card" style="margin-top:12px">
      <summary style="cursor:pointer;font-weight:700">Riwayat webhook (${log.length})</summary>
      <div class="log">${log.length ? log.map(l => `<div><b>${esc(when(l.ts))}</b> · ${esc(l.event)} · <span class="pill ${Number(l.verified) ? "ok" : "warn"}">${esc(l.note)}</span><br>Header: ${esc(l.headers)}</div>`).join("") : "Belum ada webhook masuk. Tekan TEST URL di Mayar untuk mencoba."}</div>
    </details>`;
  bind();
}

function card(o){
  const [cls, label] = STATE[o.state] || ["", o.state];
  const act = [];
  if (o.state === "perlu_cek" || o.state === "lunas" || o.state === "belum_lunas") act.push(`<button class="main" data-act="approve" data-id="${esc(o.id)}">Aktifkan & kirim akses</button>`);
  if (o.state === "aktif"){
    act.push(`<button class="main" data-act="copy" data-id="${esc(o.id)}">Salin pesan WA</button>`);
    if (o.phone) act.push(`<button data-act="wa" data-id="${esc(o.id)}">Buka WA</button>`);
    act.push(`<button data-act="resend" data-id="${esc(o.id)}">Kirim ulang email</button>`);
    act.push(`<button data-act="newcode" data-id="${esc(o.id)}">Buat kode baru</button>`);
  }
  if (o.state === "perlu_cek" || o.state === "belum_lunas") act.push(`<button class="warn" data-act="reject" data-id="${esc(o.id)}">Tolak</button>`);
  return `<div class="ord-card">
    <div class="ord-top"><div><strong>${esc(o.name || "(tanpa nama)")} · ${esc(o.plan)}</strong><small>${esc(o.email)}${o.phone ? " · " + esc(o.phone) : ""}</small></div><span class="ord-amt">${rp(o.amount)}</span></div>
    <div class="row" style="gap:6px;flex-wrap:wrap"><span class="pill ${cls}">${label}</span>${o.verified ? `<span class="pill ok">Terverifikasi</span>` : ""}${o.username ? `<span class="pill">@${esc(o.username)}</span>` : ""}${o.state === "aktif" ? `<span class="pill ${o.emailed ? "ok" : "warn"}">${o.emailed ? "Email terkirim" : "Email belum terkirim"}</span>` : ""}<span class="pill">${esc(when(o.createdAt))}</span></div>
    ${o.note ? `<small class="muted" style="font-size:12px">${esc(o.note)}</small>` : ""}
    ${o.state === "perlu_cek" ? `<small class="muted" style="font-size:12px">Cocokkan dulu dengan transaksi di dashboard Mayar sebelum mengaktifkan.</small>` : ""}
    <div class="ord-actions">${act.join("")}</div>
  </div>`;
}

function msgFor(o){
  const first = (o.name || "Kak").split(" ")[0];
  return `Halo ${first}! Terima kasih sudah membeli ResepFoto ${o.plan}.\n\nLink: ${location.origin}/\nUsername: ${o.username}\nKode akses: ${o.code}\nPaket: ${o.plan} (akses selamanya)\n\nSimpan pesan ini ya. Selamat mencoba!`;
}
function waLink(o){
  let p = String(o.phone || "").replace(/[^0-9]/g, "");
  if (p.startsWith("0")) p = "62" + p.slice(1);
  return `https://wa.me/${p}?text=${encodeURIComponent(msgFor(o))}`;
}

function bind(){
  $("#wh-copy").onclick = async () => toast(await copyText(data.settings.webhookUrl) ? "URL webhook tersalin" : "Gagal menyalin", false);
  $("#ord-refresh").onclick = () => load();
  $("#set-save").onclick = async () => {
    try {
      await api("settings_save", {token: $("#set-token").value, adminEmail: $("#set-admin").value.trim(), mailFrom: $("#set-from").value.trim(), autoWithoutToken: $("#set-auto").checked});
      toast("Pengaturan disimpan"); data = null; load();
    } catch (e) { toast(e.message, true); }
  };
  $("#set-test").onclick = async () => { try { await api("test_email", {}); toast("Email tes terkirim"); } catch (e) { toast(e.message, true); } };
  const clr = $("#set-clear");
  if (clr) clr.onclick = async () => { try { await api("settings_save", {clearToken: true, adminEmail: $("#set-admin").value.trim(), mailFrom: $("#set-from").value.trim(), autoWithoutToken: $("#set-auto").checked}); toast("Token dihapus"); data = null; load(); } catch (e) { toast(e.message, true); } };
  $("#ord-list").onclick = async ev => {
    const b = ev.target.closest("[data-act]"); if (!b) return;
    const o = data.orders.find(x => x.id === b.dataset.id); if (!o) return;
    const act = b.dataset.act;
    if (act === "copy"){ toast(await copyText(msgFor(o)) ? "Pesan tersalin. Tempel di WhatsApp" : "Gagal menyalin", false); return; }
    if (act === "wa"){ window.open(waLink(o), "_blank", "noopener"); return; }
    if ((act === "reject" || act === "newcode") && !b.dataset.armed){
      b.dataset.armed = "1"; const t = b.textContent; b.textContent = "Yakin?";
      setTimeout(() => { if (b.isConnected){ delete b.dataset.armed; b.textContent = t; } }, 2600);
      return;
    }
    b.disabled = true;
    try {
      const r = await api("order_action", {id: o.id, action: act});
      const i = data.orders.findIndex(x => x.id === o.id); data.orders[i] = r.order;
      render();
      toast({approve: "Akses aktif & email dikirim", resend: "Email dikirim ulang", newcode: "Kode baru dibuat", reject: "Pesanan ditolak"}[act] || "Beres");
      if (act === "approve" || act === "newcode") APP.renderAll && api("members").then(m => { APP.state.members = m.members; APP.renderAll(); }).catch(() => {});
    } catch (e) { toast(e.message, true); b.disabled = false; }
  };
}
})();
