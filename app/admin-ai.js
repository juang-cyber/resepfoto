/* ResepFoto — tab "AI" (Gemini API) di panel admin */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP || !APP.addAdminTab) return;
const { api, toast, esc } = APP;
const $ = (s, r = document) => r.querySelector(s);
const num = n => Number(n || 0).toLocaleString("id-ID");
const when = iso => iso ? new Date(iso).toLocaleString("id-ID", {day: "numeric", month: "short", hour: "2-digit", minute: "2-digit"}) : "-";
const ACT = {test: "Tes koneksi", link: "Link referensi", analyze: "Isi otomatis", generate: "Tes generate"};

const box = APP.addAdminTab({id: "ai", label: "AI Gemini", onShow: () => load()});
let data = null;

async function load(){
  if (!data) box.innerHTML = `<div class="empty">Memuat pengaturan AI…</div>`;
  try { data = await api("ai_settings"); render(); }
  catch (e) { box.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
}

function render(){
  const s = data.settings, u = data.usage;
  const rate = u.calls ? Math.round(u.ok / u.calls * 100) : 0;
  box.innerHTML = `
    <div class="set-card">
      <div class="set-row"><h3>Gemini API</h3><span class="pill ${s.hasKey ? "ok" : "warn"}">${s.hasKey ? "Terhubung · ••••" + esc(s.keyHint) : "API key belum diisi"}</span></div>
      ${s.hasKey ? "" : `<div class="warnbox">Buat API key di <b>Google AI Studio</b> (aistudio.google.com → Get API key), lalu tempel di bawah. Key disimpan di server dan tidak pernah ditampilkan lagi.</div>`}
      ${!s.curl ? `<div class="warnbox">Ekstensi cURL PHP tidak aktif di server; koneksi memakai cara cadangan.</div>` : ""}
      <div class="field"><label for="ai-key">API key Gemini</label><input class="input" id="ai-key" type="password" autocomplete="off" placeholder="${s.hasKey ? "•••••••• (tersimpan, isi untuk mengganti)" : "tempel API key"}"></div>
      <div class="two">
        <div class="field"><label for="ai-model">Model teks & analisa</label><input class="input" id="ai-model" value="${esc(s.model)}" placeholder="${esc(s.defaultModel)}" list="ai-models"></div>
        <div class="field"><label for="ai-imodel">Model gambar (tes generate)</label><input class="input" id="ai-imodel" value="${esc(s.imageModel)}" placeholder="${esc(s.defaultImageModel)}" list="ai-imodels"></div>
      </div>
      <datalist id="ai-models"><option value="gemini-3.8-flash"></option></datalist>
      <datalist id="ai-imodels"><option value="gemini-3.1-flash-image"></option><option value="gemini-3.1-flash-lite-image"></option><option value="gemini-3-pro-image"></option></datalist>
      <small class="muted">Default: <b>${esc(s.defaultModel)}</b> untuk membaca link & mengisi resep, <b>${esc(s.defaultImageModel)}</b> untuk tes generate gambar.</small>
      <div class="ord-actions">
        <button class="main" id="ai-save">Simpan</button>
        <button id="ai-test" ${s.hasKey ? "" : "disabled"}>Tes koneksi</button>
        ${s.hasKey ? `<button class="warn" id="ai-clear">Hapus key</button>` : ""}
      </div>
      <div id="ai-test-out"></div>
    </div>

    <div class="set-card">
      <div class="set-row"><h3>Pemakaian 30 hari</h3><button class="sq" id="ai-refresh" aria-label="Muat ulang">↻</button></div>
      <div class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))">
        <div class="stat"><b>${num(u.calls)}</b><span>Permintaan</span></div>
        <div class="stat"><b>${rate}%</b><span>Berhasil</span></div>
        <div class="stat"><b>${num(u.tokensIn + u.tokensOut)}</b><span>Token</span></div>
        <div class="stat"><b>${u.avgMs ? (u.avgMs / 1000).toFixed(1) + "s" : "-"}</b><span>Rata-rata</span></div>
      </div>
      <div class="row" style="gap:6px;flex-wrap:wrap">${Object.entries(u.byAction || {}).map(([k, v]) => `<span class="pill">${esc(ACT[k] || k)} · ${num(v)}</span>`).join("") || `<span class="muted" style="font-size:13px">Belum ada pemakaian.</span>`}</div>
      <small class="muted">Biaya dihitung Google per token/gambar sesuai tarif di Google AI Studio. Cek tagihan di akun Google Cloud kamu.</small>
    </div>

    <div class="set-card">
      <h3>Kategori yang dikenali AI</h3>
      <small class="muted">Gemini selalu memilih dari daftar ini dulu. Kategori baru hanya dibuat kalau tidak ada yang cocok, dan kamu tetap bisa mengubahnya sebelum menyimpan.</small>
      <div class="row" style="gap:6px;flex-wrap:wrap">${data.categories.map(c => `<span class="pill">${esc(c.cat)}${c.catEn ? ` <span class="muted">/ ${esc(c.catEn)}</span>` : ""} · ${c.count}</span>`).join("")}</div>
      <button class="btn btn-ai btn-sm" id="ai-new" style="justify-self:start"><svg class="ico" style="width:16px;height:16px"><use href="#i-spark"/></svg>Tambah resep dengan AI</button>
    </div>

    <details class="set-card">
      <summary style="cursor:pointer;font-weight:700">Log permintaan (${data.log.length})</summary>
      <div class="log">${data.log.length ? data.log.map(l => `<div><b>${esc(when(l.ts))}</b> · ${esc(ACT[l.action] || l.action)} · ${esc(l.model)} · <span class="pill ${Number(l.ok) ? "ok" : "bad"}">${Number(l.ok) ? "OK" : "Gagal"}</span> · ${(Number(l.ms) / 1000).toFixed(1)}s · ${num(Number(l.tokens_in) + Number(l.tokens_out))} token${l.note ? `<br>${esc(l.note)}` : ""}</div>`).join("") : "Belum ada permintaan."}</div>
    </details>`;
  bind();
}

function bind(){
  const settings = extra => ({model: $("#ai-model").value.trim(), imageModel: $("#ai-imodel").value.trim(), ...extra});
  $("#ai-save").onclick = async () => {
    try { await api("ai_settings_save", settings({apiKey: $("#ai-key").value.trim()})); toast("Pengaturan AI disimpan"); data = null; load(); }
    catch (e) { toast(e.message, true); }
  };
  const clr = $("#ai-clear");
  if (clr) clr.onclick = () => APP.armDelete(clr, async () => {
    try { await api("ai_settings_save", settings({clearKey: true})); toast("API key dihapus"); data = null; load(); }
    catch (e) { toast(e.message, true); }
  });
  $("#ai-test").onclick = async () => {
    const out = $("#ai-test-out"), b = $("#ai-test");
    b.disabled = true; out.innerHTML = `<div class="ai-status"><span class="spin"></span><div>Menghubungi Gemini…</div></div>`;
    try { const r = await api("ai_test", {}); out.innerHTML = `<div class="ai-status ok"><div>Terhubung ke <b>${esc(r.model)}</b> dalam ${(r.ms / 1000).toFixed(1)} detik. Jawaban: “${esc(r.reply)}”</div></div>`; }
    catch (e) { out.innerHTML = `<div class="ai-status err"><div>${esc(e.message)}</div></div>`; }
    finally { b.disabled = false; }
  };
  $("#ai-refresh").onclick = () => load();
  $("#ai-new").onclick = () => APP.openPromptForm(null);
}
})();
