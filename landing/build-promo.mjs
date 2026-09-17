// Membuat landing/index.html (halaman /promo) dari landing/mockup.html.
//
// Mockup adalah sumber desain. Dua mode:
//
//   node landing/build-promo.mjs            PRATINJAU (default)
//     Pembayaran Mayar aktif, pelacakan aktif, notifikasi pesanan & penghitung
//     pengunjung memakai data asli. Testimoni, rating, dan label CONTOH tetap
//     tampil — jadi halaman jujur menyatakan dirinya contoh. Untuk dites sendiri
//     dan dibagikan ke tim, BUKAN untuk dipasang di iklan.
//
//   node landing/build-promo.mjs --live     PRODUKSI
//     Sama, tapi semua data ilustrasi dibuang: testimoni karangan, rating, dan
//     label CONTOH. Jalankan mode ini sebelum mengarahkan iklan ke halaman.
import { readFileSync, writeFileSync } from "node:fs";

// Cron di server menyalin landing/index.html + landing/img/ ke <docroot>/promo/,
// jadi keluarannya HARUS landing/index.html — bukan app/promo/.
const SRC = "landing/mockup.html", OUT = "landing/index.html";
const LIVE = process.argv.includes("--live");
let s = readFileSync(SRC, "utf8");
let n = 0;
const rep = (old, neu, label) => {
  const c = s.split(old).length - 1;
  if (c !== 1) throw new Error(`${label}: ketemu ${c} kali, harusnya 1`);
  s = s.replace(old, neu); n++;
};
const sub = (re, neu, label) => {
  const m = s.match(re);
  if (!m) throw new Error(`${label}: pola tidak ketemu`);
  s = s.replace(re, () => neu); n++;
};

/* judul & deskripsi */
rep("<title>ResepFoto · Mockup Presentasi</title>",
    "<title>ResepFoto — Resep foto AI siap pake</title>", "judul");

/* setelan produksi */
rep('const PREVIEW = true;', 'const PREVIEW = false;', "PREVIEW");
rep('const MOCKUP = true; // versi presentasi: semua data di bawah adalah ILUSTRASI, pembayaran dinonaktifkan',
    'const MOCKUP = false;\nconst TRACK_URL = "/api.php";   // pelacakan untuk Admin -> Iklan', "MOCKUP + TRACK_URL");
rep('const ORDER_FEED_URL = "";      // contoh: "/api.php?a=recent_orders"',
    'const ORDER_FEED_URL = "/api.php?a=recent_orders";', "ORDER_FEED_URL");

if (LIVE) {
  /* testimoni karangan tidak boleh tampil di halaman yang menarik uang */
  sub(/^\/\/ Testimoni ILUSTRASI[\s\S]*?^\];/m,
      '// Testimoni hanya boleh diisi dari ulasan ASLI yang sudah diizinkan pembelinya.\n' +
      '// Selama kosong, bagian testimoni disembunyikan seluruhnya.\nconst TESTIMONIALS = [];', "TESTIMONIALS");
  sub(/const RATING = \{avg: 4\.9, count: 483\}; \/\/ ILUSTRASI untuk presentasi/,
      'const RATING = null;            // isi {avg, count} hanya dari ulasan asli', "RATING");

  /* label CONTOH: markup, skrip, dan CSS-nya sekalian */
  sub(/\n *<div class="corner-tag"[\s\S]*?<\/div>/, "", "label CONTOH (markup)");
  sub(/\/\* label "CONTOH"[\s\S]*?\n@media \(min-width:560px\)\{\.corner-tag\{[^\n]*\}\n/, "", "label CONTOH (CSS)");
  sub(/\/\* ---- label CONTOH[\s\S]*?\n\}\)\(\);\n\n/, "", "label CONTOH (skrip)");
}

/* penghitung pengunjung: pakai data asli dari api.php?a=live */
sub(/\/\/ viewers\n\(\(\) => \{[\s\S]*?\n\}\)\(\);/,
`// viewers — angka asli dari api.php?a=live
(() => {
  const el = $("#live-pill");
  if (!TRACK_URL) return;
  const upd = () => fetch(TRACK_URL + "?a=live", {credentials: "omit"}).then(r => r.json()).then(d => {
    if (!d || !d.ok) return;
    if (d.now >= 5){ $("#live-text").textContent = d.now.toLocaleString("id-ID") + " orang sedang melihat halaman ini"; el.hidden = false; }
    else if (d.day >= 50){ $("#live-text").textContent = d.day.toLocaleString("id-ID") + " orang mengunjungi halaman ini dalam 24 jam terakhir"; el.hidden = false; }
    else el.hidden = true;
  }).catch(() => {});
  upd(); setInterval(upd, 30000);
})();`, "viewers");

/* sembunyikan seluruh bagian testimoni kalau belum ada ulasan asli */
rep('  if (!tList.length){ $("#t-stage").hidden = true; $("#t-empty").hidden = false; return; }',
    '  if (!tList.length){ $("#testimoni").hidden = true; return; }', "sembunyikan testimoni");

/* peristiwa corong: checkout saat sheet dibuka, pay saat menuju Mayar */
rep('function openSheet(p){\n  if (p) setPlan(p);',
    'function openSheet(p){\n  if (p) setPlan(p);\n  trk("checkout", {plan: p || plan});', "event checkout");
rep('  if (MOCKUP){ toast("Mockup presentasi: pembayaran dinonaktifkan"); return; }\n  const url = CHECKOUT[plan];',
    '  const url = CHECKOUT[plan];\n  trk("pay", {plan});', "event pay");

/* blok pelacakan */
rep('/* ---- init ---- */',
`/* ---- pelacakan (Admin → Iklan) ---- */
const trk = (() => {
  if (!TRACK_URL) return () => {};
  const rnd = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), b => b.toString(36).padStart(2, "0")).join("").slice(0, 16);
  let vid = store.get("rf_vid", null); if (!/^[a-z0-9]{8,32}$/.test(vid || "")) { vid = rnd(); store.set("rf_vid", vid); }
  let sid; try { sid = sessionStorage.getItem("rf_sid") || rnd(); sessionStorage.setItem("rf_sid", sid); } catch { sid = rnd(); }
  const q = new URLSearchParams(location.search);
  let utm = store.get("rf_utm", {});
  if (q.get("utm_source") || q.get("utm_campaign") || q.get("fbclid")){
    utm = {src: q.get("utm_source") || "", med: q.get("utm_medium") || "", camp: q.get("utm_campaign") || "", content: q.get("utm_content") || "", fbclid: q.get("fbclid") ? 1 : 0, at: Date.now()};
    store.set("rf_utm", utm);
  } else if (utm.at && Date.now() - utm.at > 7 * 864e5) utm = {};
  const url = TRACK_URL + "?a=lt";
  const send = (type, extra = {}) => {
    const body = JSON.stringify({type, vid, sid, page: "promo", ref: document.referrer, ...utm, ...extra});
    try { if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], {type: "text/plain"}))) return; } catch {}
    fetch(url, {method: "POST", body, keepalive: true, credentials: "omit", headers: {"Content-Type": "text/plain"}}).catch(() => {});
  };
  send("view");
  setInterval(() => { if (!document.hidden) send("ping"); }, 20000);
  return send;
})();
document.addEventListener("click", e => {
  const b = e.target.closest('a[href="#harga"],[data-goplan],[data-buy]');
  if (b) trk("cta", {plan: b.dataset.buy || b.dataset.goplan || ""});
}, true);

/* ---- init ---- */`, "blok pelacakan");

/* trk dipakai sebelum dideklarasikan pada openSheet; angkat deklarasinya */
if (!/const trk = \(\(\) =>/.test(s)) throw new Error("blok trk hilang");

writeFileSync(OUT, s);   // img/ sudah di landing/img/, ikut disalin cron apa adanya
console.log(`landing/index.html dibuat — mode ${LIVE ? "PRODUKSI" : "PRATINJAU"}, ${n} perubahan, ${(s.length/1024).toFixed(0)} KB`);
if (!LIVE) console.log('Testimoni, rating, dan label CONTOH masih tampil. Jalankan dengan --live sebelum dipasang di iklan.');
