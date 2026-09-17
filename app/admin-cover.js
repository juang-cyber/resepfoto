/* ResepFoto — tab "Cover": ubah cover halaman login (foto + teks). Admin & super admin. */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP || !APP.addAdminTab) return;
const { api, toast, esc, state } = APP;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const ico = id => `<svg class="ico"><use href="#i-${id}"/></svg>`;

const css = document.createElement("style");
css.textContent = `
.cov-slots{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.cov-slot{display:grid;gap:6px}
.cov-slot .ph{aspect-ratio:4/5;border-radius:14px;overflow:hidden;background:var(--surface-2);border:1px solid var(--line)}
.cov-slot .ph img{width:100%;height:100%;object-fit:cover;display:block}
.cov-slot .mini{display:flex;gap:4px}
.cov-slot .mini label,.cov-slot .mini button{flex:1;font-size:11px;padding:6px 4px;border-radius:9px;border:1px solid var(--line);background:var(--surface);color:var(--ink);text-align:center;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;gap:3px}
.cov-slot .mini .ico{width:12px;height:12px}
.cov-note{font-size:12px;color:var(--muted);margin:2px 2px 12px}
`;
document.head.appendChild(css);

const files = [null, null, null], clears = [false, false, false];
const box = APP.addAdminTab({ id: "cover", label: "Cover", onShow: () => render() });

const defaults = () => state._coverDefaults || $$("#s-login .login-hero .tile img").map(im => im.getAttribute("src"));
function effective(i){ const c = state.cover || {}; return (c.img || [])[i] || defaults()[i] || ""; }

function render(){
  const c = state.cover || {};
  box.innerHTML = `
    <p class="cov-note">Foto & teks di halaman login (yang dilihat sebelum masuk). Kosongkan teks untuk pakai bawaan.</p>
    <div class="cov-slots">
      ${[0, 1, 2].map(i => `
        <div class="cov-slot">
          <div class="ph"><img id="cov-img-${i}" src="${esc(effective(i))}" alt=""></div>
          <div class="mini">
            <label for="cov-file-${i}">${ico("image")}Ganti</label>
            <button type="button" data-reset="${i}">Bawaan</button>
          </div>
          <input id="cov-file-${i}" type="file" accept="image/*" hidden>
        </div>`).join("")}
    </div>
    <div class="field"><label for="cov-title">Judul</label><input class="input" id="cov-title" maxlength="120" value="${esc(c.title || "")}" placeholder="Foto biasa jadi luar biasa. Tinggal copas resepnya."></div>
    <div class="field"><label for="cov-sub">Subjudul</label><input class="input" id="cov-sub" maxlength="200" value="${esc(c.sub || "")}" placeholder="Masuk pakai username dan kode akses dari email pembelianmu."></div>
    <div class="field"><label for="cov-chip">Teks chip kecil</label><input class="input" id="cov-chip" maxlength="40" value="${esc(c.chip || "")}" placeholder="Tinggal copas"></div>
    <details class="pref" style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:700">Versi English (opsional)</summary>
      <div class="field"><label for="cov-title-en">Title (EN)</label><input class="input" id="cov-title-en" maxlength="120" value="${esc(c.titleEn || "")}"></div>
      <div class="field"><label for="cov-sub-en">Subtitle (EN)</label><input class="input" id="cov-sub-en" maxlength="200" value="${esc(c.subEn || "")}"></div>
    </details>
    <div class="error" id="cov-error" hidden></div>
    <button class="btn btn-primary btn-block" id="cov-save">Simpan cover</button>`;
  files[0] = files[1] = files[2] = null; clears[0] = clears[1] = clears[2] = false;
  bind();
}

function bind(){
  [0, 1, 2].forEach(i => {
    $("#cov-file-" + i).addEventListener("change", () => {
      const f = $("#cov-file-" + i).files[0]; $("#cov-file-" + i).value = "";
      if (!f || !/^image\/(jpeg|png|webp)$/.test(f.type)){ toast("File harus JPG, PNG, atau WEBP", true); return; }
      if (f.size > 8 * 1024 * 1024){ toast("Gambar terlalu besar. Maksimal 8 MB", true); return; }
      files[i] = f; clears[i] = false;
      $("#cov-img-" + i).src = URL.createObjectURL(f);
    });
  });
  box.querySelectorAll("[data-reset]").forEach(b => b.onclick = () => {
    const i = +b.dataset.reset; files[i] = null; clears[i] = true;
    $("#cov-img-" + i).src = defaults()[i] || "";
    toast("Slot " + (i + 1) + " kembali ke foto bawaan setelah disimpan");
  });
  $("#cov-save").onclick = async () => {
    const err = $("#cov-error"); err.hidden = true;
    const fd = new FormData();
    fd.append("title", $("#cov-title").value.trim());
    fd.append("sub", $("#cov-sub").value.trim());
    fd.append("chip", $("#cov-chip").value.trim());
    fd.append("titleEn", $("#cov-title-en").value.trim());
    fd.append("subEn", $("#cov-sub-en").value.trim());
    [0, 1, 2].forEach(i => { if (files[i]) fd.append("img" + (i + 1), files[i]); else if (clears[i]) fd.append("clear" + (i + 1), "1"); });
    const btn = $("#cov-save"); btn.disabled = true;
    try {
      const r = await api("cover_save", fd, true);
      APP.applyCover(r.cover);
      toast("Cover login diperbarui");
      render();
    } catch (e){ err.textContent = e.message; err.hidden = false; }
    finally { btn.disabled = false; }
  };
}
})();
