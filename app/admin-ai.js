/* ResepFoto — tab "AI" (Gemini & DeepSeek API) di panel admin */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP || !APP.addAdminTab) return;
const { api, toast, esc } = APP;
const $ = (s, r = document) => r.querySelector(s);
const num = n => Number(n || 0).toLocaleString("id-ID");
const when = iso => iso ? new Date(iso).toLocaleString("id-ID", {day: "numeric", month: "short", hour: "2-digit", minute: "2-digit"}) : "-";
const ACT = {test: "Tes koneksi", link: "Link referensi", reference: "Link Instagram", analyze: "Isi otomatis", ocr: "Prompt dari gambar", generate: "Tes generate", thumb: "Thumbnail sendiri", translate: "Terjemah EN", translate_th: "Terjemah TH"};

const box = APP.addAdminTab({id: "ai", label: "AI", onShow: () => load(), superOnly: true});
let data = null;

async function load(){
  if (!data) box.innerHTML = `<div class="empty">Memuat pengaturan AI…</div>`;
  try {
    const [s, en, th] = await Promise.all([api("ai_settings"), api("en_status").catch(() => null), api("th_status").catch(() => null)]);
    data = s; data.en = en ? en.status : null; data.th = th ? th.status : null; render();
  }
  catch (e) { box.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
}

/* Kartu etalase internasional: berapa resep sudah tampil di imagine.kitlab.id, dan tombol melengkapi kolom EN. */
function enCard(){
  const s = data.en;
  if (!s) return "";
  const f = s.fields, left = f.title + f.desc + f.tips + f.cat + f.prompt;
  return `
    <div class="set-card">
      <div class="set-row"><h3>Versi English · imagine.kitlab.id</h3><span class="pill ${s.visible === s.total ? "ok" : "warn"}">${num(s.visible)} / ${num(s.total)} resep tampil</span></div>
      <small class="muted">Etalase internasional hanya menampilkan resep yang sudah punya <b>judul English</b> dan prompt yang bisa dibaca dalam <b>bahasa Inggris</b>. Tombol di bawah mengisi kolom English yang <b>masih kosong</b> dengan AI (mesin utama di atas, cadangannya otomatis). Teks Indonesia dan prompt utama ResepFoto tidak pernah diubah, dan terjemahan yang sudah ada tidak ditimpa.</small>
      <div class="stats" style="grid-template-columns:repeat(5,minmax(0,1fr))">
        <div class="stat"><b>${num(f.title)}</b><span>Judul</span></div>
        <div class="stat"><b>${num(f.desc)}</b><span>Deskripsi</span></div>
        <div class="stat"><b>${num(f.tips)}</b><span>Tips</span></div>
        <div class="stat"><b>${num(f.cat)}</b><span>Kategori</span></div>
        <div class="stat"><b>${num(f.prompt)}</b><span>Prompt Indonesia</span></div>
      </div>
      <small class="muted">Angka di atas = kolom English yang masih kosong. Hasil AI tetap bisa disunting per resep (form resep → Versi English).</small>
      <div class="ord-actions"><button class="main" id="en-fill" ${left ? "" : "disabled"}>${left ? "Lengkapi versi English dengan AI" : "Semua sudah lengkap ✓"}</button></div>
      <div id="en-out"></div>
    </div>`;
}

/* Kartu etalase Thailand (/th): teks Thai (judul, deskripsi, tips, kategori). Prompt tetap English. */
function thCard(){
  const s = data.th;
  if (!s) return "";
  const f = s.fields, left = f.title + f.desc + f.tips + f.cat;
  return `
    <div class="set-card">
      <div class="set-row"><h3>Versi Thai · imagine.kitlab.id/th</h3><span class="pill ${s.visibleComplete === s.visible ? "ok" : "warn"}">${num(s.visibleComplete)} / ${num(s.visible)} resep berbahasa Thai</span></div>
      <small class="muted">Etalase Thailand menampilkan resep yang sama dengan etalase English (jadi lengkapi <b>versi English dulu</b>). Tombol di bawah menulis <b>judul, deskripsi, tips, dan kategori</b> dalam bahasa Thai untuk kolom yang <b>masih kosong</b>, dengan gaya bahasa aplikasi Thai yang natural. Prompt tidak diterjemahkan: model gambar paling patuh pada prompt English. Kolom Thai yang kosong tampil dalam English.</small>
      <div class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))">
        <div class="stat"><b>${num(f.title)}</b><span>Judul</span></div>
        <div class="stat"><b>${num(f.desc)}</b><span>Deskripsi</span></div>
        <div class="stat"><b>${num(f.tips)}</b><span>Tips</span></div>
        <div class="stat"><b>${num(f.cat)}</b><span>Kategori</span></div>
      </div>
      <small class="muted">Angka di atas = kolom Thai yang masih kosong. Hasil AI tetap bisa disunting per resep (form resep → Versi Thai). Sebelum mulai jualan, sebaiknya minta penutur asli Thai membaca sekilas.</small>
      <div class="ord-actions"><button class="main" id="th-fill" ${left ? "" : "disabled"}>${left ? "Lengkapi versi Thai dengan AI" : "Semua sudah lengkap ✓"}</button></div>
      <div id="th-out"></div>
    </div>`;
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
      <div class="set-row"><h3>DeepSeek API</h3><span class="pill ${s.dsHasKey ? "ok" : "warn"}">${s.dsHasKey ? "Terhubung · ••••" + esc(s.dsKeyHint) : "API key belum diisi"}</span></div>
      ${s.dsHasKey ? "" : `<div class="warnbox">Buat API key di <b>platform.deepseek.com</b> → API keys, lalu tempel di bawah. Dipakai untuk mode <b>Link Instagram</b> (membaca caption dan tulisan prompt di slide).</div>`}
      <div class="field"><label for="ds-key">API key DeepSeek</label><input class="input" id="ds-key" type="password" autocomplete="off" placeholder="${s.dsHasKey ? "•••••••• (tersimpan, isi untuk mengganti)" : "tempel API key (sk-…)"}"></div>
      <div class="two">
        <div class="field"><label for="ds-model">Model DeepSeek</label><input class="input" id="ds-model" value="${esc(s.dsModel)}" placeholder="${esc(s.dsDefaultModel)}" list="ds-models"></div>
        <div class="field"><label for="ai-engine">Mesin utama baca referensi</label>
          <select class="input" id="ai-engine">
            <option value="deepseek" ${s.engine === "deepseek" ? "selected" : ""}>DeepSeek, cadangan Gemini</option>
            <option value="gemini" ${s.engine === "gemini" ? "selected" : ""}>Gemini, cadangan DeepSeek</option>
          </select></div>
      </div>
      <datalist id="ds-models"><option value="deepseek-flash"></option></datalist>
      <small class="muted">Model harus bisa membaca gambar (bawaan: <b>${esc(s.dsDefaultModel)}</b>). Kalau mesin utama gagal atau key-nya kosong, link Instagram otomatis dibaca mesin satunya.</small>
      <div class="ord-actions">
        <button class="main" id="ds-save">Simpan</button>
        <button id="ds-test" ${s.dsHasKey ? "" : "disabled"}>Tes koneksi</button>
        ${s.dsHasKey ? `<button class="warn" id="ds-clear">Hapus key</button>` : ""}
      </div>
      <div id="ds-test-out"></div>
    </div>

    ${enCard()}
    ${thCard()}

    <div class="set-card">
      <div class="set-row"><h3>Pemakaian 30 hari</h3><button class="sq" id="ai-refresh" aria-label="Muat ulang">↻</button></div>
      <div class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))">
        <div class="stat"><b>${num(u.calls)}</b><span>Permintaan</span></div>
        <div class="stat"><b>${rate}%</b><span>Berhasil</span></div>
        <div class="stat"><b>${num(u.tokensIn + u.tokensOut)}</b><span>Token</span></div>
        <div class="stat"><b>${u.avgMs ? (u.avgMs / 1000).toFixed(1) + "s" : "-"}</b><span>Rata-rata</span></div>
      </div>
      <div class="row" style="gap:6px;flex-wrap:wrap">${Object.entries(u.byAction || {}).map(([k, v]) => `<span class="pill">${esc(ACT[k] || k)} · ${num(v)}</span>`).join("") || `<span class="muted" style="font-size:13px">Belum ada pemakaian.</span>`}</div>
      <small class="muted">Biaya dihitung per token/gambar sesuai tarif Google AI Studio dan DeepSeek. Cek tagihan di akun masing-masing.</small>
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
  // Satu endpoint menyimpan semua setelan, jadi kedua kartu selalu mengirim model & mesin yang sedang tampil.
  const settings = extra => ({model: $("#ai-model").value.trim(), imageModel: $("#ai-imodel").value.trim(),
    dsModel: $("#ds-model").value.trim(), engine: $("#ai-engine").value, ...extra});
  const save = async (extra, msg) => {
    try { await api("ai_settings_save", settings(extra)); toast(msg); data = null; load(); }
    catch (e) { toast(e.message, true); }
  };
  $("#ai-save").onclick = () => save({apiKey: $("#ai-key").value.trim()}, "Pengaturan AI disimpan");
  $("#ds-save").onclick = () => save({dsKey: $("#ds-key").value.trim()}, "Pengaturan DeepSeek disimpan");
  const clr = $("#ai-clear");
  if (clr) clr.onclick = () => APP.armDelete(clr, () => save({clearKey: true}, "API key Gemini dihapus"));
  const dclr = $("#ds-clear");
  if (dclr) dclr.onclick = () => APP.armDelete(dclr, () => save({clearDsKey: true}, "API key DeepSeek dihapus"));
  const test = (engine, btn, out, label) => async () => {
    btn.disabled = true; out.innerHTML = `<div class="ai-status"><span class="spin"></span><div>Menghubungi ${label}…</div></div>`;
    try { const r = await api("ai_test", {engine}); out.innerHTML = `<div class="ai-status ok"><div>Terhubung ke <b>${esc(r.model)}</b> dalam ${(r.ms / 1000).toFixed(1)} detik. Jawaban: “${esc(r.reply)}”</div></div>`; }
    catch (e) { out.innerHTML = `<div class="ai-status err"><div>${esc(e.message)}</div></div>`; }
    finally { btn.disabled = false; }
  };
  $("#ai-test").onclick = test("gemini", $("#ai-test"), $("#ai-test-out"), "Gemini");
  $("#ds-test").onclick = test("deepseek", $("#ds-test"), $("#ds-test-out"), "DeepSeek");
  $("#ai-refresh").onclick = () => load();
  const enBtn = $("#en-fill");
  if (enBtn) enBtn.onclick = async () => {
    const out = $("#en-out"); enBtn.disabled = true;
    const st = (h, k) => { out.innerHTML = `<div class="ai-status ${k || ""}">${k === "busy" ? '<span class="spin"></span>' : ""}<div>${h}</div></div>`; };
    let done = 0;
    try {
      // Satu putaran = beberapa resep. Berhenti kalau sudah habis, atau kalau satu putaran tidak menghasilkan apa-apa.
      for (let i = 0; i < 300; i++){
        st(`Menerjemahkan ke bahasa Inggris… ${done} resep selesai. Biarkan tab ini terbuka.`, "busy");
        const r = await api("en_fill", {});
        done += r.done || 0;
        const f = r.status.fields, left = f.title + f.desc + f.tips + f.cat + f.prompt;
        if (!left){ st(`Selesai: ${done} resep diterjemahkan. ${num(r.status.visible)} dari ${num(r.status.total)} resep kini tampil di imagine.kitlab.id.`, "ok"); break; }
        if (!r.done){ st(`Berhenti di ${done} resep: AI tidak mengembalikan terjemahan untuk sisanya. Coba tekan lagi, atau isi manual di form resep.`, "err"); break; }
      }
    } catch (e) { st(`${esc(e.message)} (${done} resep sempat diterjemahkan)`, "err"); }
    const keep = out.innerHTML;
    try { data.en = (await api("en_status")).status; render(); $("#en-out").innerHTML = keep; } catch {}
  };
  const thBtn = $("#th-fill");
  if (thBtn) thBtn.onclick = async () => {
    const out = $("#th-out"); thBtn.disabled = true;
    const st = (h, k) => { out.innerHTML = `<div class="ai-status ${k || ""}">${k === "busy" ? '<span class="spin"></span>' : ""}<div>${h}</div></div>`; };
    let done = 0;
    try {
      for (let i = 0; i < 300; i++){
        st(`Menulis versi Thai… ${done} resep selesai. Biarkan tab ini terbuka.`, "busy");
        const r = await api("th_fill", {});
        done += r.done || 0;
        const f = r.status.fields, left = f.title + f.desc + f.tips + f.cat;
        if (!left){ st(`Selesai: ${done} resep kini punya teks Thai. Cek hasilnya di imagine.kitlab.id/th.`, "ok"); break; }
        if (!r.done){ st(`Berhenti di ${done} resep: AI tidak mengembalikan teks Thai untuk sisanya. Coba tekan lagi, atau isi manual di form resep.`, "err"); break; }
      }
    } catch (e) { st(`${esc(e.message)} (${done} resep sempat diterjemahkan)`, "err"); }
    const keep = out.innerHTML;
    try { data.th = (await api("th_status")).status; render(); $("#th-out").innerHTML = keep; } catch {}
  };
  $("#ai-new").onclick = () => APP.openPromptForm(null);
}
})();
