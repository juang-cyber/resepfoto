// Membuat halaman iklan dari landing/mockup.html — dua halaman sekali jalan:
//   landing/index.html         → /promo       (mode mengikuti --live, defaultnya PRATINJAU)
//   app/promo-live/index.html  → /promo-live  (SELALU PRODUKSI, apa pun modenya)
//
// Mockup adalah sumber desain. Dua mode untuk /promo:
//
//   node landing/build-promo.mjs            PRATINJAU (default)
//     Halaman DEMO. Pembayaran Mayar & pelacakan tetap aktif sungguhan, tapi semua
//     bukti sosial adalah CONTOH: 10 nama karangan di notifikasi, "45 orang sedang
//     melihat", testimoni & rating contoh. Penandanya label pojok CONTOH di kanan
//     bawah. Untuk presentasi dan tes sendiri, BUKAN untuk dipasang di iklan.
//
//   node landing/build-promo.mjs --live     PRODUKSI
//     Sama, tapi semua data ilustrasi dibuang: testimoni karangan, rating, dan
//     label CONTOH. Jalankan mode ini sebelum mengarahkan iklan ke /promo.
//
// /promo-live ada supaya /promo boleh tetap jadi halaman demo sementara iklan sudah jalan
// (permintaan pemilik, 24 Sep 2026): iklan diarahkan ke /promo-live, bukan /promo.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname } from "node:path";

// Cron di server menyalin landing/index.html + landing/img/ ke <docroot>/promo/,
// jadi keluaran /promo HARUS landing/index.html — bukan app/promo/.
// /promo-live ikut app/. (cron menyalin app/. ke document root) dan TIDAK punya salinan
// gambar sendiri: rujukan "img/…" di halamannya diarahkan ke /promo/img/.
const SRC = "landing/mockup.html", OUT = "landing/index.html", OUT_LIVE = "app/promo-live/index.html";
const LIVE_PROMO = process.argv.includes("--live");

// Isi fungsi sengaja tidak diindentasi: template literal di dalamnya ikut masuk ke HTML
// apa adanya, jadi menambah indentasi akan mengubah halaman yang dihasilkan.
function build(LIVE) {
let s = readFileSync(SRC, "utf8").replace(/\r\n?/g, "\n");   // checkout Windows bisa CRLF; semua pola di bawah memakai \n
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
if (LIVE) rep('const PREVIEW = true;', 'const PREVIEW = false;', "PREVIEW");   // pratinjau butuh PREVIEW tetap true
rep('const MOCKUP = true; // versi presentasi: semua data di bawah adalah ILUSTRASI, pembayaran dinonaktifkan',
    'const MOCKUP = false;\nconst TRACK_URL = "/api.php";   // pelacakan untuk Admin -> Iklan', "MOCKUP + TRACK_URL");
if (LIVE) {
  /* umpan pesanan+keranjang asli hanya di produksi; pratinjau memakai kartu CONTOH berlabel */
  rep('const ORDER_FEED_URL = "";      // contoh: "/api.php?a=recent_orders"',
      'const ORDER_FEED_URL = "/api.php?a=recent_orders";', "ORDER_FEED_URL");

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

  /* pita "harga launching — naik dalam 3 hari": diminta pemilik untuk halaman DEMO (23 Sep 2026).
     Di halaman yang menarik uang, tenggat yang tidak pasti dijalankan adalah klaim harga yang
     menyesatkan, jadi ia ikut dibuang bersama data karangan lain. Kalau mau dipasang di produksi,
     pakai tanggal yang memang akan ditepati — lihat landing/CLAUDE.md. */
  sub(/\n *<!-- launch:mulai[\s\S]*?<!-- launch:selesai -->/, "", "pita launching (markup)");
  sub(/\/\* launch:mulai[\s\S]*?\/\* launch:selesai \*\/\n/, "", "pita launching (CSS)");
  if (/class="launch"|launch:mulai/.test(s)) throw new Error("pita launching masih tersisa di build --live");
}

/* penghitung pengunjung: angka asli di produksi, angka CONTOH berlabel di pratinjau */
sub(/\/\/ viewers\n\(\(\) => \{[\s\S]*?\n\}\)\(\);/, LIVE ?
`// viewers — angka asli dari api.php?a=live
(() => {
  const el = $("#live-pill");
  if (!TRACK_URL) return;
  const upd = () => fetch(TRACK_URL + "?a=live&page=promo", {credentials: "omit"}).then(r => r.json()).then(d => {
    if (!d || !d.ok) return;
    const now = Number(d.now) || 0, day = Number(d.day) || 0;
    // Ambang 2, bukan 1: angka 1 itu pengunjung yang sedang membaca sendiri, jadi tidak ada artinya
    // sebagai bukti sosial. Angkanya sendiri selalu apa adanya dari presence — tidak pernah dibulatkan naik.
    if (now >= 2){ $("#live-text").textContent = now.toLocaleString("id-ID") + " orang sedang melihat halaman ini"; el.hidden = false; }
    else if (day >= 2){ $("#live-text").textContent = day.toLocaleString("id-ID") + " orang mengunjungi halaman ini dalam 24 jam terakhir"; el.hidden = false; }
    else el.hidden = true;
  }).catch(() => {});
  upd(); setInterval(upd, 30000);
})();` :
`// viewers — angka CONTOH untuk halaman demo. Bukan data asli; penandanya label pojok CONTOH.
(() => {
  $("#live-text").textContent = "45 orang sedang melihat halaman ini";
  $("#live-pill").hidden = false;
})();`, "viewers");

/* sembunyikan seluruh bagian testimoni kalau belum ada ulasan asli */
rep('  if (!tList.length){ $("#t-stage").hidden = true; $("#t-empty").hidden = false; return; }',
    '  if (!tList.length){ $("#testimoni").hidden = true; return; }', "sembunyikan testimoni");

/* peristiwa corong: checkout saat sheet dibuka, pay saat menuju Mayar */
rep('function openSheet(p){\n  if (p) setPlan(p);',
    'function openSheet(p){\n  if (p) setPlan(p);\n  trk("checkout", {plan: p || plan});', "event checkout");
rep('  if (MOCKUP){ toast("Mockup presentasi: pembayaran dinonaktifkan"); return; }\n  const url = CHECKOUT[plan];',
    '  const url = CHECKOUT[plan];\n  trk("pay", {plan});', "event pay");

/* Meta Pixel dimulai di <head>, bukan di akhir halaman. Dulu PageView baru terkirim sesudah seluruh
   skrip halaman jalan dan berebut jaringan dengan gambar, jadi pengunjung iklan yang cepat pergi
   tidak tercatat sebagai "landing page view" (26 Sep 2026: 19 klik, hanya 11 tercatat). ID tetap
   diambil dari api.php?a=pixel, jadi masih diatur dari Admin -> Iklan tanpa membangun ulang. */
rep('<link rel="preconnect" href="https://fonts.googleapis.com">',
`<link rel="preconnect" href="https://connect.facebook.net">
<script>
window.__rfPixel = fetch("/api.php?a=pixel", {credentials: "omit"}).then(r => r.json()).then(d => {
  const id = String((d && d.id) || "").replace(/[^0-9]/g, "");
  if (!id) return "";
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;
  s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");
  fbq("init", id);
  fbq("track", "PageView", {}, {eventID: Date.now().toString(36) + Math.random().toString(36).slice(2, 8)});
  return id;
}).catch(() => "");
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">`, "pixel di head");

/* blok pelacakan */
rep('/* ---- init ---- */',
`/* ---- Meta Pixel (opsional; ID diisi di Admin -> Iklan) ----
   ID-nya diambil dari api.php?a=pixel, tidak ditanam di halaman, supaya bisa diganti dari
   panel admin tanpa membangun ulang. Kolom kosong = tidak ada satu pun skrip Meta dimuat.
   Purchase sengaja TIDAK ada di sini: pembayaran terjadi di domain Mayar, jadi pixel
   halaman ini tidak pernah melihatnya. Purchase dikirim Mayar (Server Side Tracking) —
   lihat landing/CLAUDE.md, dan jangan tambahkan pengirim kedua. */
const fbSend = (() => {
  if (!TRACK_URL) return () => {};
  const queue = [];
  let ready = null;                       // null = ID belum datang, false = pixel mati
  const eid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  const fire = (name, params, custom) => fbq(custom ? "trackCustom" : "track", name, params, {eventID: eid()});
  const money = p => {
    const x = PLANS[p] || PLANS[plan];
    return x ? {value: x.price, currency: "IDR", content_name: "ResepFoto " + x.name, content_type: "product"} : {};
  };
  const map = (type, extra) => {
    if (type === "view") fire("ViewContent", {content_name: "ResepFoto promo", content_type: "product"});
    else if (type === "cta") fire("CTAClick", money(extra.plan), true);
    else if (type === "checkout") fire("InitiateCheckout", money(extra.plan));
    else if (type === "pay") fire("AddPaymentInfo", money(extra.plan));
  };
  const off = () => { ready = false; queue.length = 0; };
  // pixel + PageView sudah dimulai di <head> (window.__rfPixel); di sini tinggal menunggu ID-nya
  (window.__rfPixel || Promise.resolve("")).then(id => {
    if (!id) return off();
    ready = true;
    queue.splice(0).forEach(a => map(a[0], a[1]));
  }).catch(off);
  return (type, extra) => { if (ready) map(type, extra || {}); else if (ready === null) queue.push([type, extra || {}]); };
})();

/* ---- pelacakan (Admin → Iklan) ---- */
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
    fbSend(type, extra);
    const body = JSON.stringify({type, vid, sid, page: "promo", ref: document.referrer, vou: store.get("rfp_voucher", ""), ...utm, ...extra});
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
return {s, n};
}

/* /promo */
const promo = build(LIVE_PROMO);
writeFileSync(OUT, promo.s);   // img/ sudah di landing/img/, ikut disalin cron apa adanya
console.log(`${OUT} dibuat — mode ${LIVE_PROMO ? "PRODUKSI" : "PRATINJAU"}, ${promo.n} perubahan, ${(promo.s.length/1024).toFixed(0)} KB`);

/* /promo-live — selalu produksi. Semua rujukan gambar di mockup berbentuk "img/…" di dalam
   kutip (atribut src dan data JS), jadi cukup diganti ke /promo/img/ yang sudah disalin cron. */
const live = build(true);
const IMG_REF = /(["'`(])img\//g;
const nImg = (live.s.match(IMG_REF) || []).length;
const liveHtml = live.s.replace(IMG_REF, "$1/promo/img/");
if (!nImg || /(["'`(])img\//.test(liveHtml)) throw new Error("promo-live: rujukan gambar img/ tidak terganti semua");
mkdirSync(dirname(OUT_LIVE), {recursive: true});
writeFileSync(OUT_LIVE, liveHtml);
console.log(`${OUT_LIVE} dibuat — mode PRODUKSI, ${live.n} perubahan, ${nImg} rujukan gambar → /promo/img/, ${(liveHtml.length/1024).toFixed(0)} KB`);
if (!LIVE_PROMO) console.log('/promo masih halaman DEMO (testimoni, rating, label CONTOH). Iklan diarahkan ke /promo-live, bukan /promo.');
