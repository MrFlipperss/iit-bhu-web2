// public/assets/js/app.js  [M4]  Shared helpers for every page: API calls, shell, formatting.
"use strict";

const ICONS = {
  dashboard: '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
  grid: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M9 4v17M15 4v17"/>',
  exam: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
  inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  shuffle: '<path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8M21 3v5h-5"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  send: '<path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/>',
};
function icon(name) {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ""}</svg>`;
}

const CIT = {
  csrf: null,
  user: null,
  meta: null,

  async api(path, body, { method } = {}) {
    const opts = { method: method || (body === undefined ? "GET" : "POST"), headers: { Accept: "application/json" }, credentials: "same-origin" };
    if (body !== undefined) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    if (opts.method !== "GET" && CIT.csrf) opts.headers["X-CSRF-Token"] = CIT.csrf;
    let res, json;
    try {
      res = await fetch("/api/" + path, opts);
      json = await res.json();
    } catch (e) {
      const err = new Error(res && res.status === 504 ? "The server took too long to answer. Try again." : "Could not reach the server. Check your connection.");
      err.code = "network";
      throw err;
    }
    if (!json.ok) {
      if (res.status === 401 && !path.startsWith("auth/login")) { location.href = "/index.html?next=" + encodeURIComponent(location.pathname + location.search); }
      const err = new Error(json.error?.message || "Request failed");
      err.code = json.error?.code;
      err.details = json.error;
      throw err;
    }
    return json.data;
  },

  async boot(active) {
    let me;
    try { me = await CIT.api("auth/me.php"); } catch (e) { return null; }
    CIT.user = me.user;
    CIT.csrf = me.csrf;
    CIT.aiAvailable = me.ai_available;
    CIT.renderShell(active);
    return me.user;
  },

  async loadMeta() {
    if (!CIT.meta) CIT.meta = await CIT.api("meta.php");
    return CIT.meta;
  },

  renderShell(active) {
    const u = CIT.user;
    const items = [
      ["dashboard", "Overview", "dashboard.html", "dashboard"],
      ["timetable", "Timetable", "timetable.html", "grid"],
      ["exams", "Exams", "exams.html", "exam"],
      ["bookings", "Spectra bookings", "bookings.html", "inbox"],
      ["request", "New request", "request.html", "plus"],
      ["assistant", "Assistant", "assistant.html", "chat"],
      ["reschedule", u.role === "faculty" ? "Report unavailability" : u.role === "department_coordinator" ? "Department changes" : "Reschedule", "reschedule.html", "shuffle", ["admin", "faculty", "department_coordinator"]],
      ["admin", "Admin", "admin.html", "settings", ["admin"]],
    ];
    const nav = items.filter(i => !i[4] || i[4].includes(u.role))
      .map(([key, label, href, ic]) => `<a href="${href}" class="${key === active ? "active" : ""}">${icon(ic)}<span>${label}</span></a>`).join("");
    const roleLabel = { admin: "Admin", department_coordinator: "Department coordinator", faculty: "Faculty", club: "Club representative" }[u.role];
    document.getElementById("sidebar").innerHTML = `
      <div class="brand"><div class="brand-mark">${"<i></i>".repeat(9)}</div>
        <div class="brand-name">CIT Scheduler<small>Chilika Institute of Technology</small></div></div>
      <nav class="nav">${nav}</nav>
      <div class="user-box"><div>${esc(u.display_name)}</div><div class="role">${roleLabel}${u.faculty_id ? " · " + u.faculty_id : ""}</div>
        <button class="btn ghost small" id="logout">${icon("logout")} Log out</button></div>`;
    document.getElementById("topbar").innerHTML = `<button class="btn ghost small" id="menu" aria-label="Menu">${icon("menu")}</button>
      <span class="brand-name">CIT Scheduler</span><span></span>`;
    document.getElementById("menu").onclick = () => document.querySelector(".shell").classList.toggle("nav-open");
    document.getElementById("logout").onclick = async () => { await CIT.api("auth/logout.php", {}); location.href = "/index.html"; };
  },

  toast(msg, bad = false) {
    let t = document.getElementById("toast");
    if (!t) { t = document.createElement("div"); t.id = "toast"; t.className = "toast"; t.setAttribute("role", "status"); document.body.appendChild(t); }
    t.textContent = msg;
    t.className = "toast show" + (bad ? " bad" : "");
    clearTimeout(t._h);
    t._h = setTimeout(() => (t.className = "toast"), bad ? 6000 : 3000);
  },

  errorHtml(e) {
    const list = e.details?.problems || e.details?.reasons?.map(r => r.detail) || e.details?.violations?.map(v => `[${v.rule}] ${v.detail}`);
    return `<div class="callout bad">${esc(e.message)}${list && list.length ? "<ul>" + list.map(x => `<li>${esc(x)}</li>`).join("") + "</ul>" : ""}</div>`;
  },

  async busy(btn, label, fn) {
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="spin"></span> ${label}`;
    try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = old; }
  },

  openDrawer(html) {
    let d = document.getElementById("drawer");
    if (!d) { d = document.createElement("aside"); d.id = "drawer"; d.className = "drawer"; d.setAttribute("aria-live", "polite"); document.body.appendChild(d); }
    d.innerHTML = html;
    requestAnimationFrame(() => d.classList.add("open"));
    d.querySelectorAll("[data-close]").forEach(b => (b.onclick = CIT.closeDrawer));
    document.onkeydown = e => { if (e.key === "Escape") CIT.closeDrawer(); };
    return d;
  },
  closeDrawer() {
    document.getElementById("drawer")?.classList.remove("open");
    document.querySelectorAll("tr.selected").forEach(r => r.classList.remove("selected"));
  },
};

function esc(s) {
  return String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
/** The signature: time references written exactly as the dataset writes them. */
function slot(day, a, b) {
  if (!day) return "";
  return `<span class="slot">${esc(day)} · P${a}${b && b !== a ? "–P" + b : ""}</span>`;
}
function periodTime(p) {
  const t = CIT.meta?.periods?.find(x => +x.id === +p);
  return t ? t.start : "";
}
function statusTag(s, label) {
  const names = { approved: "Approved", rejected: "Rejected", alternative_offered: "Alternative offered", pending: "Pending", cancelled: "Cancelled",
    proposed: "To review", confirmed: "Confirmed", overridden: "Set by Admin", infeasible: "No valid repair", stale: "Out of date", active: "Active",
    superseded: "Superseded", draft: "Draft", validated: "Validated", decided: "Decided", submitted: "Submitted", rejected_by_admin: "Rejected" };
  return `<span class="status ${esc(s)}">${esc(label || names[s] || s)}</span>`;
}
const words = s => String(s || "").replace(/_/g, " ");
const qs = new URLSearchParams(location.search);
/** MySQL UTC "YYYY-MM-DD HH:MM:SS" -> local date/time text. */
function when(utc, timeOnly = false) {
  if (!utc) return "";
  const d = new Date(String(utc).replace(" ", "T") + "Z");
  if (isNaN(d)) return esc(utc);
  return timeOnly ? d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : d.toLocaleString([], { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}
const plural = (n, word, many) => `${n} ${n === 1 ? word : many || word + "s"}`;
