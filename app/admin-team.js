/* ResepFoto — tab "Admin": kelola akun admin & super admin. Hanya super admin. */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP) return;
const { api, toast, esc, copyText, armDelete, state } = APP;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const ico = id => `<svg class="ico"><use href="#i-${id}"/></svg>`;
const isSuper = () => !!(state.user && state.user.adminRole === "super_admin");
const origin = location.origin + "/";

function genCode(){
  const A = "ABCDEFGHJKLMNPQRSTUVWXYZ", N = "23456789";
  let s = "RF-"; for (let i = 0; i < 4; i++) s += A[Math.floor(Math.random() * A.length)];
  s += "-"; for (let i = 0; i < 4; i++) s += N[Math.floor(Math.random() * N.length)];
  return s;
}

const css = document.createElement("style");
css.textContent = `
#seg-team[hidden]{display:none}
.adm-team-form{border:1px solid var(--line);border-radius:18px;padding:14px;display:grid;gap:12px;margin-bottom:14px}
.adm-team-form[hidden]{display:none}
.adm-role-seg{display:flex;gap:6px}
.adm-role-seg button{flex:1;height:38px;border-radius:12px;border:1px solid var(--line);background:var(--surface);font-weight:700;font-size:13px;color:var(--ink)}
.adm-role-seg button[aria-pressed="true"]{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.adm-code{font-family:var(--mono)}
.adm-team-note{font-size:12px;color:var(--muted);margin:2px 2px 12px}
`;
document.head.appendChild(css);

let data = null, busy = false, editing = null, role = "admin", pendingCode = null;
const box = APP.addAdminTab({ id: "team", label: "Admin", onShow: () => load(), superOnly: true });

function syncTabVisibility(){
  const btn = document.getElementById("seg-team");
  if (btn) btn.hidden = !isSuper();
  if (!isSuper() && state.seg === "team") state.seg = "prompts";
}
window.addEventListener("rf:admin-render", syncTabVisibility);

async function load(){
  if (!isSuper()){ box.innerHTML = `<div class="empty"><strong>Khusus super admin</strong><span>Hanya super admin yang bisa mengelola akun admin.</span></div>`; return; }
  if (busy) return; busy = true;
  if (!data) box.innerHTML = `<div class="empty">Memuat akun admin…</div>`;
  try { data = await api("admins"); render(); }
  catch (e){ box.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
  finally { busy = false; }
}

const roleBadge = r => r === "super_admin" ? `<span class="pill accent">Super admin</span>` : `<span class="pill">Admin</span>`;

function render(){
  const admins = data.admins || [];
  const codeBox = pendingCode ? `
    <div class="prompt-box" id="team-result" style="margin-bottom:14px">
      <header><span class="eyebrow">Akses admin — tampil sekali</span></header>
      <pre id="team-msg">${esc(pendingCode.msg)}</pre>
      <button class="btn btn-primary btn-sm" type="button" id="team-copy" style="margin-top:8px">${ico("copy")}Salin pesan</button>
    </div>` : "";
  box.innerHTML = `
    <p class="adm-team-note">Admin bisa kelola resep & member. Super admin juga bisa kelola akun admin di sini.</p>
    <div class="row between" style="margin:2px 2px 12px"><h3 style="margin:0;font-size:15px">Akun admin (${admins.length})</h3>
      <button class="btn btn-primary btn-sm" id="team-add">${ico("plus")}Tambah admin</button></div>
    <form class="adm-team-form" id="team-form" hidden>
      <div class="row between"><strong id="team-form-title">Tambah admin</strong><button type="button" class="btn btn-ghost btn-sm" id="team-cancel">Batal</button></div>
      <div class="field"><label for="team-name">Nama</label><input class="input" id="team-name" maxlength="80" placeholder="contoh: Hendrick"></div>
      <div class="two">
        <div class="field"><label for="team-user">Username</label><input class="input" id="team-user" autocapitalize="off" autocomplete="off" placeholder="hendrick"></div>
        <div class="field" id="team-code-wrap"><label for="team-code">Kode akses</label><input class="input adm-code" id="team-code" placeholder="otomatis kalau kosong"></div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="team-gen" style="justify-self:start">${ico("key")}Buatkan kode</button>
      <div><div class="studio-label" style="margin-bottom:6px">Peran</div>
        <div class="adm-role-seg" id="team-role">
          <button type="button" data-role="admin" aria-pressed="true">Admin</button>
          <button type="button" data-role="super_admin" aria-pressed="false">Super admin</button>
        </div>
      </div>
      <label class="check" style="justify-self:start"><input type="checkbox" id="team-active" checked>Akses aktif</label>
      <label class="check" style="justify-self:start" id="team-reset-wrap" hidden><input type="checkbox" id="team-reset">Buat kode akses baru</label>
      <div class="error" id="team-error" hidden></div>
      <button class="btn btn-primary btn-block" type="button" id="team-save">Simpan admin</button>
    </form>
    ${codeBox}
    <div class="list">${admins.map(cardHtml).join("")}</div>`;
  bind();
}

function cardHtml(a){
  const acts = [];
  if (!a.builtin){
    acts.push(`<button class="sq" data-edit="${esc(a.username)}" aria-label="Ubah ${esc(a.name)}">${ico("edit")}</button>`);
    if (!a.self) acts.push(`<button class="sq danger" data-del="${esc(a.username)}" aria-label="Hapus ${esc(a.name)}">${ico("trash")}</button>`);
  }
  const st = a.builtin ? `<span class="pill ok">Bawaan</span>` : (a.active ? `<span class="pill ok">Aktif</span>` : `<span class="pill bad">Nonaktif</span>`);
  return `<div class="li">
    <div class="avatar" style="flex:none">${esc((a.name || "?")[0].toUpperCase())}</div>
    <div class="grow"><strong>${esc(a.name)}${a.self ? " (kamu)" : ""}</strong><small>@${esc(a.username)}${a.codeHint ? ` · kode ••••${esc(a.codeHint)}` : ""}</small>
      <div class="row" style="gap:6px;margin-top:5px;flex-wrap:wrap">${roleBadge(a.role)}${st}</div></div>
    <div class="actions" style="flex-direction:column">${acts.join("")}</div>
  </div>`;
}

function openForm(a){
  editing = a ? a.username : null;
  role = a ? a.role : "admin";
  $("#team-form-title").textContent = a ? "Ubah admin" : "Tambah admin";
  $("#team-name").value = a ? a.name : "";
  const u = $("#team-user"); u.value = a ? a.username : ""; u.disabled = !!a;
  $("#team-active").checked = a ? !!a.active : true;
  $$("#team-role button").forEach(b => b.setAttribute("aria-pressed", b.dataset.role === role));
  $("#team-reset-wrap").hidden = !a;
  $("#team-reset").checked = false;
  $("#team-code-wrap").style.display = a ? "none" : "";
  $("#team-code").value = "";
  $("#team-error").hidden = true;
  $("#team-form").hidden = false;
  $("#team-name").focus();
}

function bind(){
  $("#team-add").onclick = () => { pendingCode = null; openForm(null); };
  if ($("#team-copy")) $("#team-copy").onclick = async () => toast(await copyText(pendingCode.msg) ? "Pesan tersalin" : "Gagal menyalin", false);
  const form = $("#team-form");
  if (!form) return;
  $("#team-cancel").onclick = () => { form.hidden = true; };
  $("#team-gen").onclick = () => { $("#team-code").value = genCode(); };
  $$("#team-role button").forEach(b => b.onclick = () => { role = b.dataset.role; $$("#team-role button").forEach(x => x.setAttribute("aria-pressed", x === b)); });
  $("#team-save").onclick = async () => {
    const err = $("#team-error"); err.hidden = true;
    const payload = { username: $("#team-user").value.trim().toLowerCase(), name: $("#team-name").value.trim(), role, active: $("#team-active").checked ? 1 : "" };
    if (editing){ payload.editing = 1; if ($("#team-reset").checked){ payload.resetCode = 1; payload.code = $("#team-code").value.trim(); } }
    else payload.code = $("#team-code").value.trim();
    try {
      const r = await api("admin_save", payload);
      if (r.code){
        const msg = `Akses admin ResepFoto\n\nLink: ${origin}\nUsername: ${payload.username}\nKode akses: ${r.code}\nPeran: ${role === "super_admin" ? "Super admin" : "Admin"}\n\nSimpan kode ini ya, hanya tampil sekali.`;
        pendingCode = { username: payload.username, code: r.code, msg };
      } else pendingCode = null;
      toast(editing ? "Admin diperbarui" : "Admin ditambahkan");
      data = null; await load();
    } catch (e){ err.textContent = e.message; err.hidden = false; }
  };
  box.querySelectorAll("[data-edit]").forEach(b => b.onclick = () => { pendingCode = null; const a = (data.admins || []).find(x => x.username === b.dataset.edit); if (a) openForm(a); });
  box.querySelectorAll("[data-del]").forEach(b => b.onclick = () => armDelete(b, async () => {
    try { await api("admin_delete", { username: b.dataset.del }); toast("Admin dihapus"); data = null; load(); }
    catch (e){ toast(e.message, true); }
  }));
}
})();
