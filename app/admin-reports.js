/* ResepFoto — tab "Pengguna" (laporan user & aplikasi) dan "Iklan" (laporan landing page) */
(() => {
"use strict";
const APP = window.RFAPP;
if (!APP || !APP.addAdminTab) return;
const { api, toast, esc, copyText } = APP;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const num = n => Number(n || 0).toLocaleString("id-ID");
const rp = n => "Rp " + Number(n || 0).toLocaleString("id-ID");
const rpShort = n => { n = Number(n || 0); return n >= 1e6 ? "Rp " + (n / 1e6).toLocaleString("id-ID", {maximumFractionDigits: 1}) + " jt" : n >= 1e3 ? "Rp " + Math.round(n / 1e3).toLocaleString("id-ID") + " rb" : "Rp " + n; };
const pct = (a, b) => b ? (a / b * 100).toLocaleString("id-ID", {maximumFractionDigits: 1}) + "%" : "–";
const dLabel = d => new Date(d + "T00:00:00").toLocaleDateString("id-ID", {day: "numeric", month: "short"});
const when = iso => iso ? new Date(iso).toLocaleString("id-ID", {day: "numeric", month: "short", hour: "2-digit", minute: "2-digit"}) : "-";

const css = document.createElement("style");
css.textContent = `
.rep{display:grid;gap:12px}
.rep-kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
@media (min-width:600px){.rep-kpis{grid-template-columns:repeat(4,minmax(0,1fr))}}
.kpi{padding:14px;border-radius:18px;background:var(--surface-2);display:grid;gap:2px}
.kpi span{font-size:12px;color:var(--muted);font-weight:600}
.kpi b{font-size:24px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums;line-height:1.15}
.kpi small{font-size:11.5px;color:var(--muted)}
.rep-grid{display:grid;gap:12px}
@media (min-width:700px){.rep-grid.two-col{grid-template-columns:1fr 1fr}}
.card-r{border:1px solid var(--line);border-radius:20px;padding:14px;display:grid;gap:10px;min-width:0}
.card-r h3{font-size:15px;margin:0}
.card-r .sub{font-size:12px;color:var(--muted);margin-top:-6px}
.chart{position:relative}
.chart svg{display:block;width:100%;height:150px;overflow:visible;margin-top:14px}
.chart .bar{fill:var(--accent)}
.chart .bar.dim{fill:var(--accent);opacity:.35}
.chart .grid{stroke:var(--line);stroke-width:1}
.chart .axis{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px}
.chart .tip{position:absolute;pointer-events:none;background:var(--ink);color:var(--surface);font-size:12px;font-weight:600;padding:6px 9px;border-radius:9px;white-space:nowrap;transform:translate(-50%,-100%);opacity:0;transition:opacity 120ms;z-index:2}
.chart .tip.on{opacity:1}
.chart .max{position:absolute;left:0;top:-4px;font-size:11px;color:var(--muted)}
.hbars{display:grid;gap:8px}
.hbar{display:grid;grid-template-columns:minmax(90px,38%) 1fr auto;gap:8px;align-items:center;font-size:12.5px}
.hbar .lbl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink-2)}
.hbar .trk{height:12px;border-radius:6px;background:var(--surface-2);overflow:hidden}
.hbar .trk i{display:block;height:100%;border-radius:6px;background:var(--accent);min-width:2px}
.hbar b{font-variant-numeric:tabular-nums;font-size:12.5px}
.tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.tbl th{text-align:left;color:var(--muted);font-weight:600;padding:6px 6px;border-bottom:1px solid var(--line);white-space:nowrap}
.tbl td{padding:7px 6px;border-bottom:1px solid var(--line);vertical-align:middle}
.tbl td.n,.tbl th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.tbl tr:last-child td{border-bottom:0}
.tbl-wrap{overflow-x:auto;margin:0 -4px}
.tbl img{width:30px;height:36px;border-radius:7px;object-fit:cover;vertical-align:middle;margin-right:8px}
.range{display:flex;gap:6px;flex-wrap:wrap}
.range button{height:34px;padding:0 12px;border-radius:10px;border:1.5px solid var(--line);font-size:12.5px;font-weight:700;color:var(--ink-2)}
.range button[aria-pressed="true"]{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-deep)}
:root[data-theme="dark"] .range button[aria-pressed="true"]{color:var(--accent)}
.live{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:var(--ok)}
.live i{width:8px;height:8px;border-radius:50%;background:var(--ok)}
.feed{display:grid;gap:6px;font-size:12.5px}
.feed div{display:flex;justify-content:space-between;gap:8px;padding:7px 9px;border-radius:10px;background:var(--surface-2)}
.feed span{color:var(--muted);white-space:nowrap}
.spend-form{display:grid;grid-template-columns:1fr 1fr;gap:8px}
@media (min-width:600px){.spend-form{grid-template-columns:140px 1fr 140px auto}}
.spend-form .input{height:42px}
`;
document.head.appendChild(css);

/* ---- grafik batang satu seri dengan tooltip ---- */
function barChart(values, {fmt = num, name = ""} = {}){
  const n = values.length, W = 600, H = 150, gap = 2;
  const max = Math.max(1, ...values.map(v => v.value));
  const bw = (W - gap * (n - 1)) / n;
  const bars = values.map((v, i) => {
    const h = v.value ? Math.max(3, v.value / max * (H - 8)) : 0;
    const x = i * (bw + gap), y = H - h, r = Math.min(4, bw / 2, h);
    const path = h ? `M${x},${H}V${y + r}Q${x},${y} ${x + r},${y}H${x + bw - r}Q${x + bw},${y} ${x + bw},${y + r}V${H}Z` : "";
    return `${path ? `<path class="bar" d="${path}"/>` : ""}<rect x="${x}" y="0" width="${bw + gap}" height="${H}" fill="transparent" data-i="${i}"/>`;
  }).join("");
  const id = "c" + Math.random().toString(36).slice(2, 8);
  setTimeout(() => {
    const root = document.getElementById(id); if (!root) return;
    const tip = root.querySelector(".tip"), svg = root.querySelector("svg");
    const showTip = ev => {
      const rct = ev.target.closest("rect[data-i]"); if (!rct){ tip.classList.remove("on"); return; }
      const v = values[+rct.dataset.i], box = svg.getBoundingClientRect(), rb = rct.getBoundingClientRect();
      tip.textContent = `${v.label} · ${fmt(v.value)}${name ? " " + name : ""}`;
      tip.style.left = Math.min(box.width - 40, Math.max(40, rb.left - box.left + rb.width / 2)) + "px";
      tip.style.top = (H - (v.value / max * (H - 8)) - 6) * (box.height / H) + "px";
      tip.classList.add("on");
    };
    svg.addEventListener("pointermove", showTip);
    svg.addEventListener("pointerdown", showTip);
    svg.addEventListener("pointerleave", () => tip.classList.remove("on"));
  }, 0);
  const total = values.reduce((a, v) => a + v.value, 0);
  return `<div class="chart" id="${id}"><span class="max">maks ${fmt(max)}</span>
    <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="${esc(name)}: total ${fmt(total)}">
      <line class="grid" x1="0" x2="${W}" y1="${H}" y2="${H}"/><line class="grid" x1="0" x2="${W}" y1="${H / 2}" y2="${H / 2}" stroke-dasharray="3 4"/>${bars}</svg>
    <div class="axis"><span>${esc(values[0]?.label || "")}</span><span>${esc(values[n - 1]?.label || "")}</span></div>
    <div class="tip"></div></div>`;
}
const hbars = (rows, fmt = num) => {
  const max = Math.max(1, ...rows.map(r => r.value));
  return `<div class="hbars">${rows.map(r => `<div class="hbar"><span class="lbl" title="${esc(r.label)}">${esc(r.label)}</span><span class="trk"><i style="width:${r.value / max * 100}%"></i></span><b>${fmt(r.value)}${r.extra ? ` <span class="muted" style="font-weight:600">· ${esc(r.extra)}</span>` : ""}</b></div>`).join("")}</div>`;
};
const kpi = (label, value, sub = "") => `<div class="kpi"><span>${esc(label)}</span><b>${value}</b>${sub ? `<small>${sub}</small>` : ""}</div>`;
const card = (title, body, sub = "") => `<div class="card-r"><h3>${esc(title)}</h3>${sub ? `<div class="sub">${sub}</div>` : ""}${body}</div>`;
const empty = t => `<div class="muted" style="font-size:13px">${t}</div>`;

/* ================= PENGGUNA ================= */
const uBox = APP.addAdminTab({id: "rep-users", label: "Pengguna", onShow: () => loadUsers(), superOnly: true});
const TYPE = {login: "masuk", open: "membuka", copy: "menyalin", fav: "menyimpan"};
async function loadUsers(){
  uBox.innerHTML = `<div class="empty">Memuat laporan pengguna…</div>`;
  try { renderUsers(await api("report_users")); }
  catch (e) { uBox.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
}
function renderUsers(d){
  const m = d.members, o = d.orders, a = d.app;
  const PLAN = {Premium: "Premium", Standard: "Standard", Lifetime: "Lifetime", Bulanan: "Bulanan", Tahunan: "Tahunan"};
  uBox.innerHTML = `<div class="rep">
    <div class="row between"><div><div class="eyebrow">Laporan</div><strong>Pengguna & aplikasi · 30 hari</strong></div><button class="sq" data-refresh aria-label="Muat ulang">↻</button></div>
    <div class="rep-kpis">
      ${kpi("Total member", num(m.total), `+${num(m.new30)} dalam 30 hari`)}
      ${kpi("Member aktif (akses)", num(m.status.ok || 0), `${num(m.status.expired || 0)} kedaluwarsa · ${num(m.status.off || 0)} nonaktif`)}
      ${kpi("Pakai app 7 hari", num(d.active.d7), `hari ini ${num(d.active.d1)} · 30 hari ${num(d.active.d30)}`)}
      ${kpi("Pendapatan", rpShort(o.revenue), `${num(o.paid)} order lunas · 30 hari ${rpShort(o.revenue30)}`)}
    </div>
    <div class="rep-grid two-col">
      ${card("Member yang memakai app per hari", barChart(d.daily.map(x => ({label: dLabel(x.day), value: x.active})), {name: "member"}), "Member berbeda yang membuka, menyalin, atau login")}
      ${card("Resep disalin per hari", barChart(d.daily.map(x => ({label: dLabel(x.day), value: x.copy})), {name: "salinan"}), `Total ${num(d.daily.reduce((s, x) => s + x.copy, 0))} salinan · ${num(d.daily.reduce((s, x) => s + x.open, 0))} kali resep dibuka`)}
      ${card("Member baru per hari", barChart(d.daily.map(x => ({label: dLabel(x.day), value: x.new})), {name: "member baru"}))}
      ${card("Member per paket", Object.keys(m.plans).length ? hbars(Object.entries(m.plans).sort((x, y) => y[1] - x[1]).map(([k, v]) => ({label: PLAN[k] || k, value: v, extra: pct(v, m.total)}))) : empty("Belum ada member."))}
    </div>
    ${card("Resep paling sering disalin", d.top.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Resep</th><th class="n">Disalin</th><th class="n">Dibuka</th><th class="n">Favorit</th><th class="n">Salin/buka</th></tr></thead><tbody>${d.top.map(t => `<tr><td><img src="${esc(t.image)}" alt="">${esc(t.title)} <span class="muted">· ${esc(t.cat)}</span></td><td class="n"><b>${num(t.copy)}</b></td><td class="n">${num(t.open)}</td><td class="n">${num(t.fav)}</td><td class="n">${pct(t.copy, t.open)}</td></tr>`).join("")}</tbody></table></div>` : empty("Belum ada aktivitas member. Data muncul setelah member membuka dan menyalin resep."), `${num(a.unused30)} dari ${num(a.prompts)} resep belum pernah disalin dalam 30 hari`)}
    <div class="rep-grid two-col">
      ${card("Kesehatan katalog", hbars([
        {label: "Total resep", value: a.prompts},
        {label: "Resep baru 30 hari", value: a.newPrompts30},
        {label: "Kategori", value: a.categories},
        {label: "Sudah ada versi English", value: a.withEn, extra: pct(a.withEn, a.prompts)},
        {label: "Resep sudah dites", value: a.testedPrompts, extra: `${num(a.tests)} hasil tes`},
        {label: "Permintaan AI 30 hari", value: a.aiCalls30},
      ]))}
      ${card("Pesanan", hbars([
        {label: "Lunas / aktif", value: o.paid},
        {label: "Menunggu / perlu dicek", value: o.pending},
        {label: "Ditolak", value: o.rejected},
        ...Object.entries(o.byPlan).map(([k, v]) => ({label: "Paket " + k, value: v})),
      ]), `Pendapatan total ${rp(o.revenue)}`)}
      ${card("Member paling aktif", d.topUsers.length ? hbars(d.topUsers.map(u => ({label: "@" + u.username, value: u.copies, extra: "salinan"}))) : empty("Belum ada data."))}
      ${card("Aktivitas terbaru", d.recent.length ? `<div class="feed">${d.recent.map(r => `<div><div><b>${esc(r.name || "@" + r.username)}</b> ${esc(TYPE[r.type] || r.type)}${r.title ? " " + esc(r.title) : ""}</div><span>${esc(when(r.ts))}</span></div>`).join("")}</div>` : empty("Belum ada aktivitas."))}
    </div>
    <small class="muted">Aktivitas admin tidak dihitung. Pencatatan aktivitas member dimulai sejak fitur laporan ini dipasang.</small>
  </div>`;
  uBox.querySelector("[data-refresh]").onclick = () => loadUsers();
}

/* ================= IKLAN ================= */
const aBox = APP.addAdminTab({id: "rep-ads", label: "Iklan", onShow: () => loadAds(), superOnly: true});
let range = 30, adsData = null;
const store = { get(k, d){ try { return localStorage.getItem(k) || d; } catch { return d; } }, set(k, v){ try { localStorage.setItem(k, v); } catch {} } };
async function loadAds(){
  if (!adsData) aBox.innerHTML = `<div class="empty">Memuat laporan iklan…</div>`;
  try { adsData = await api("report_ads&days=" + range); renderAds(adsData); }
  catch (e) { aBox.innerHTML = `<div class="error">${esc(e.message)}</div>`; }
}
function utmUrl(base, camp, content){
  const u = new URL(base);
  u.searchParams.set("utm_source", "facebook"); u.searchParams.set("utm_medium", "paid");
  u.searchParams.set("utm_campaign", camp || "resepfoto"); u.searchParams.set("utm_content", content || "{{ad.name}}");
  return u.toString().replace(/%7B%7B/g, "{{").replace(/%7D%7D/g, "}}");
}
function renderAds(d){
  const t = d.totals;
  const base = store.get("rf_landing_url", location.origin + "/promo/");
  const funnel = [
    {label: "Pengunjung", value: t.visitors},
    {label: "Klik paket / beli", value: t.cta},
    {label: "Buka pilihan paket", value: t.checkout},
    {label: "Lanjut ke pembayaran", value: t.pay},
    {label: "Order lunas", value: t.orders},
  ];
  const campNames = [...new Set(d.campaigns.map(c => c.camp).filter(c => c && c !== "(tanpa kampanye)"))];
  aBox.innerHTML = `<div class="rep">
    <div class="row between" style="flex-wrap:wrap;gap:8px">
      <div><div class="eyebrow">Laporan iklan & landing page</div><strong>${esc(dLabel(d.from))} – ${esc(dLabel(d.to))}</strong> ${d.liveNow ? `<span class="live"><i></i>${num(d.liveNow)} sedang di halaman</span>` : ""}</div>
      <div class="row" style="gap:6px"><div class="range">${[[1, "Hari ini"], [7, "7 hari"], [30, "30 hari"], [90, "90 hari"]].map(([v, l]) => `<button data-range="${v}" aria-pressed="${v === range}">${l}</button>`).join("")}</div><button class="sq" data-refresh aria-label="Muat ulang">↻</button></div>
    </div>
    ${d.tracking ? "" : `<div class="warnbox">Belum ada data kunjungan. Pelacakan aktif setelah landing page di-deploy ke domain ini dan <code>TRACK_URL</code> di landing page diisi <code>/api.php</code>. Pakai link iklan dengan UTM dari pembuat link di bawah.</div>`}
    <div class="rep-kpis">
      ${kpi("Pengunjung unik", num(t.visitors), `${num(t.views)} tampilan halaman`)}
      ${kpi("Order lunas", num(t.orders), `${num(t.pending)} menunggu · konversi ${t.conv === null ? "–" : t.conv.toLocaleString("id-ID") + "%"}`)}
      ${kpi("Pendapatan", rpShort(t.revenue), Object.entries(d.ordersByPlan).map(([k, v]) => `${esc(k)} ${num(v)}`).join(" · ") || "&nbsp;")}
      ${kpi("Biaya iklan", rpShort(t.spend), t.spend ? `ROAS ${t.roas === null ? "–" : t.roas.toLocaleString("id-ID") + "×"} · CPA ${t.cpa ? rpShort(t.cpa) : "–"}` : "Isi biaya di bawah")}
    </div>
    <div class="rep-grid two-col">
      ${card("Corong penjualan", hbars(funnel.map(f => ({...f, extra: pct(f.value, t.visitors)}))), "Persentase dihitung dari pengunjung unik")}
      ${card("Pengunjung per hari", barChart(d.daily.map(x => ({label: dLabel(x.day), value: x.visitors})), {name: "pengunjung"}))}
      ${card("Order lunas per hari", barChart(d.daily.map(x => ({label: dLabel(x.day), value: x.orders})), {name: "order"}), `Pendapatan ${rp(t.revenue)}`)}
      ${card("Biaya iklan per hari", barChart(d.daily.map(x => ({label: dLabel(x.day), value: x.spend})), {fmt: rpShort, name: ""}), t.cpv ? `Rata-rata ${rpShort(t.cpv)} per pengunjung` : "Diisi manual dari Meta Ads Manager")}
    </div>
    ${card("Per kampanye", d.campaigns.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kampanye</th><th>Sumber</th><th class="n">Pengunjung</th><th class="n">Buka paket</th><th class="n">Ke bayar</th><th class="n">Biaya</th><th class="n">Biaya/pengunjung</th><th class="n">Biaya/ke bayar</th></tr></thead><tbody>${d.campaigns.map(c => `<tr><td><b>${esc(c.camp)}</b></td><td>${esc(c.src)}${c.med ? ` <span class="muted">/ ${esc(c.med)}</span>` : ""}</td><td class="n">${num(c.visitors)}</td><td class="n">${num(c.checkout)}</td><td class="n">${num(c.pay)} <span class="muted">(${pct(c.pay, c.visitors)})</span></td><td class="n">${c.spend ? rpShort(c.spend) : "–"}</td><td class="n">${c.spend && c.visitors ? rpShort(c.spend / c.visitors) : "–"}</td><td class="n">${c.spend && c.pay ? rpShort(c.spend / c.pay) : "–"}</td></tr>`).join("")}</tbody></table></div>` : empty("Belum ada kunjungan dari kampanye."), "Nama kampanye diambil dari <code>utm_campaign</code>. Order Mayar belum bisa dipisah per kampanye, jadi pakai kolom “Ke bayar” sebagai pembanding.")}
    <div class="rep-grid two-col">
      ${card("Sumber pengunjung", d.sources.length ? hbars(d.sources.map(s => ({label: s.src, value: s.visitors, extra: `${num(s.pay)} ke bayar`}))) : empty("Belum ada data."))}
      ${card("Perangkat", Object.keys(d.devices).length ? hbars(Object.entries(d.devices).sort((x, y) => y[1] - x[1]).map(([k, v]) => ({label: {mobile: "HP", desktop: "Komputer", tablet: "Tablet"}[k] || k, value: v, extra: pct(v, t.visitors)}))) : empty("Belum ada data."))}
      ${card("Klik per paket", Object.keys(d.planClicks).length ? hbars(Object.entries(d.planClicks).map(([k, v]) => ({label: k[0].toUpperCase() + k.slice(1), value: v.pay, extra: `ke bayar · ${num(v.checkout)} buka`}))) : empty("Belum ada data."))}
      ${card("Situs perujuk", d.refs.length ? hbars(d.refs.map(r => ({label: r.ref, value: r.visitors}))) : empty("Belum ada data."))}
    </div>
    ${card("Biaya iklan (dari Meta Ads Manager)", `
      <form class="spend-form" id="spend-form">
        <input class="input" type="date" id="sp-day" value="${esc(d.to)}" required aria-label="Tanggal">
        <input class="input" id="sp-camp" list="sp-camps" placeholder="Nama kampanye (sama dengan utm_campaign)" aria-label="Kampanye">
        <input class="input" id="sp-amt" inputmode="numeric" placeholder="Biaya, contoh 150000" aria-label="Biaya">
        <button class="btn btn-primary btn-sm" type="submit" style="height:42px">Simpan</button>
        <datalist id="sp-camps">${campNames.map(c => `<option value="${esc(c)}"></option>`).join("")}</datalist>
      </form>
      ${d.spendRows.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th>Kampanye</th><th class="n">Biaya</th><th></th></tr></thead><tbody>${d.spendRows.map(r => `<tr><td>${esc(dLabel(r.day))}</td><td>${esc(r.campaign)}</td><td class="n">${rp(r.amount)}</td><td class="n"><button class="sq danger" data-del-spend="${esc(r.day)}|${esc(r.campaign)}" aria-label="Hapus">${'<svg class="ico"><use href="#i-trash"/></svg>'}</button></td></tr>`).join("")}</tbody></table></div>` : empty("Belum ada biaya tercatat pada periode ini.")}`,
      "Salin angka “Jumlah yang dibelanjakan” per hari per kampanye dari Meta Ads Manager. Nilai yang sama pada tanggal & kampanye yang sama akan ditimpa.")}
    ${card("Meta Pixel", `
      <p class="muted" style="font-size:12px;margin:0 0 10px">Isi Pixel ID dari <b>Meta Events Manager → Data sources</b>. Halaman <code>/promo</code> memuatnya sendiri, jadi ID ini berlaku untuk <b>semua kampanye di akun iklan yang sama</b> — tidak perlu diganti tiap bikin iklan baru. Kosongkan kalau tidak mau ada pelacakan Meta sama sekali.</p>
      <div class="two">
        <div class="field"><label for="px-id">Pixel ID</label><input class="input" id="px-id" inputmode="numeric" maxlength="20" value="${esc(d.metaPixelId || "")}" placeholder="contoh 1234567890123456"></div>
        <div class="field" style="display:flex;align-items:flex-end"><button class="btn btn-primary btn-sm" id="px-save" style="height:42px;width:100%">Simpan Pixel</button></div>
      </div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Peristiwa yang dikirim: <code>PageView</code>, <code>ViewContent</code>, <code>CTAClick</code>, <code>InitiateCheckout</code>, <code>AddPaymentInfo</code>. <b><code>Purchase</code> tidak dikirim</b> — pembayaran terjadi di domain Mayar, jadi pixel halaman ini tidak bisa melihatnya.</p>`,
      "Cek dengan ekstensi <b>Meta Pixel Helper</b> di Chrome, atau Events Manager → Test Events.")}
    ${card("Pembuat link iklan (UTM)", `
      <div class="field"><label for="utm-base">Alamat landing page</label><input class="input" id="utm-base" value="${esc(base)}"></div>
      <div class="two"><div class="field"><label for="utm-camp">Nama kampanye</label><input class="input" id="utm-camp" placeholder="contoh: wisuda-sept" maxlength="60"></div>
      <div class="field"><label for="utm-content">Nama iklan</label><input class="input" id="utm-content" placeholder="{{ad.name}}"></div></div>
      <div class="set-row"><span class="urlbox" id="utm-out"></span><button class="sq" id="utm-copy" aria-label="Salin link">${'<svg class="ico"><use href="#i-copy"/></svg>'}</button></div>`,
      "Tempel link ini di kolom URL situs web iklan Meta. <code>{{ad.name}}</code> otomatis diganti Meta dengan nama iklan.")}
  </div>`;
  bindAds();
}
function bindAds(){
  aBox.querySelector("[data-refresh]").onclick = () => loadAds();
  $$("[data-range]", aBox).forEach(b => b.onclick = () => { range = +b.dataset.range; loadAds(); });
  const upd = () => {
    try { $("#utm-out").textContent = utmUrl($("#utm-base").value.trim(), $("#utm-camp").value.trim().toLowerCase().replace(/\s+/g, "-"), $("#utm-content").value.trim()); store.set("rf_landing_url", $("#utm-base").value.trim()); }
    catch { $("#utm-out").textContent = "Alamat landing page tidak valid"; }
  };
  ["#utm-base", "#utm-camp", "#utm-content"].forEach(s => $(s).addEventListener("input", upd));
  upd();
  $("#utm-copy").onclick = async () => toast(await copyText($("#utm-out").textContent) ? "Link iklan tersalin" : "Gagal menyalin", false);
  $("#px-save").onclick = async () => {
    const id = $("#px-id").value.replace(/[^0-9]/g, "");
    try {
      await api("settings_save", {metaPixelId: id});
      toast(id ? "Pixel ID disimpan" : "Pixel dimatikan"); adsData = null; loadAds();
    } catch (ex) { toast(ex.message, true); }
  };
  $("#spend-form").onsubmit = async e => {
    e.preventDefault();
    try {
      await api("ad_spend_save", {day: $("#sp-day").value, campaign: $("#sp-camp").value.trim(), amount: $("#sp-amt").value});
      toast("Biaya iklan disimpan"); loadAds();
    } catch (ex) { toast(ex.message, true); }
  };
  aBox.querySelectorAll("[data-del-spend]").forEach(b => b.onclick = () => APP.armDelete(b, async () => {
    const [day, ...c] = b.dataset.delSpend.split("|");
    try { await api("ad_spend_save", {day, campaign: c.join("|"), amount: 0}); toast("Biaya dihapus"); loadAds(); }
    catch (ex) { toast(ex.message, true); }
  }));
}
})();
