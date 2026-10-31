// Timetable: the weekly planner by group, faculty or room; Spectra-week view shows events and Week-7 moves.  [M1]
(async () => {
  const user = await CIT.boot("timetable");
  if (!user) return;
  const meta = await CIT.loadMeta();
  const main = document.getElementById("main");
  const state = {
    view: qs.get("view") || (user.faculty_id ? "faculty" : "group"),
    id: qs.get("id") || user.faculty_id || (meta.groups || [])[0]?.id,
    week: qs.get("week") || "regular",
    hours: qs.get("hours") || "full",
  };
  const lists = {
    group: (meta.groups || []).map(g => [g.id, `${g.id} · ${g.name}`]),
    faculty: (meta.faculty || []).map(f => [f.id, `${f.id} · ${f.name}`]),
    room: (meta.rooms || []).map(r => [r.id, `${r.id} · ${r.name} (${r.capacity})`]),
  };

  main.innerHTML = `<div class="page-head"><div><h1>Timetable</h1><div class="sub" id="summary"></div></div>
    <div class="actions" style="flex-wrap:wrap;gap:8px">
      <div class="seg" id="view">${["group", "faculty", "room"].map(v => `<button data-v="${v}">${{ group: "Group", faculty: "Faculty", room: "Room" }[v]}</button>`).join("")}</div>
      <select id="pick" aria-label="Choose" style="width:auto;min-width:220px"></select>
      <div class="seg" id="week"><button data-w="regular">Regular week</button><button data-w="fest">Spectra week</button></div>
      <div class="seg" id="hours"><button data-h="full">Full day (8:00–22:00)</button><button data-h="college">College hours (8:00–18:00)</button></div>
    </div></div>
    <div id="grid"></div>
    <div class="legend"><span class="lecture">Lecture</span><span class="tutorial">Tutorial</span><span class="lab">Lab, 3 hours</span><span class="event">Guest Lecture / Event</span><span class="blocked">Unavailable</span></div>`;

  const pick = document.getElementById("pick");
  function syncControls() {
    document.querySelectorAll("#view button").forEach(b => b.classList.toggle("on", b.dataset.v === state.view));
    document.querySelectorAll("#week button").forEach(b => b.classList.toggle("on", b.dataset.w === state.week));
    document.querySelectorAll("#hours button").forEach(b => b.classList.toggle("on", b.dataset.h === state.hours));
    pick.innerHTML = lists[state.view].map(([id, label]) => `<option value="${esc(id)}" ${id === state.id ? "selected" : ""}>${esc(label)}</option>`).join("");
    if (!lists[state.view].some(([id]) => id === state.id)) state.id = lists[state.view][0][0];
    history.replaceState(null, "", `?view=${state.view}&id=${encodeURIComponent(state.id)}&week=${state.week}&hours=${state.hours}`);
  }
  document.querySelectorAll("#view button").forEach(b => (b.onclick = () => { state.view = b.dataset.v; state.id = null; syncControls(); load(); }));
  document.querySelectorAll("#week button").forEach(b => (b.onclick = () => { state.week = b.dataset.w; syncControls(); load(); }));
  document.querySelectorAll("#hours button").forEach(b => (b.onclick = () => { state.hours = b.dataset.h; syncControls(); renderCurrent(); }));
  pick.onchange = () => { state.id = pick.value; syncControls(); load(); };
  syncControls();
  load();

  let cachedData = null;
  function renderCurrent() {
    if (cachedData) render(cachedData);
  }

  async function load() {
    const grid = document.getElementById("grid");
    grid.innerHTML = `<div class="loading"><span class="spin"></span> Loading</div>`;
    let d;
    try { d = await CIT.api(`schedule/get.php?view=${state.view}&id=${encodeURIComponent(state.id)}&week=${state.week}`); }
    catch (e) { grid.innerHTML = e.code === "no_timetable" ? `<div class="panel empty">${esc(e.message)}</div>` : CIT.errorHtml(e); return; }
    cachedData = d;
    render(d)  function render(d) {
    const days = meta.days || [];
    const entries = d.entries || [];
    const events = d.events || [];
    const closures = d.closures || [];
    const unavailable = d.unavailable || [];
    const hasEveningActivity = entries.some(e => +e.start_period >= 10 || (+e.start_period + +e.duration - 1) >= 10) ||
                               events.some(ev => +ev.end_period >= 10 || +ev.start_period >= 10);
    const showEvening = state.hours === "full" || hasEveningActivity || (state.week === "fest" && state.view === "room");
    const periods = (meta.periods || []).filter(p => +p.id <= (showEvening ? 13 : 9)).map(p => +p.id);
    // grid rows: header, P1-P5, lunch, P6-P9, (P10-P13)
    const rowOf = p => 1 + p + (p >= 6 ? 1 : 0);
    let html = `<div class="table-wrap"><div class="planner" style="--days:${days.length}"><div class="h" style="grid-row:1"></div>`;
    days.forEach((day, i) => (html += `<div class="h" style="grid-row:1;grid-column:${i + 2}">${day}</div>`));
    const blocked = new Map();
    [...closures, ...unavailable].forEach(c => (c.periods || []).forEach(p => blocked.set(`${c.day}-${p}`, c.reason)));
    periods.forEach(p => {
      const pm = (meta.periods || []).find(x => +x.id === p);
      const ev = p >= 10 ? " evening" : "";
      html += `<div class="t${ev}" style="grid-row:${rowOf(p)};grid-column:1"><b>P${p}</b>${pm ? pm.start : ""}</div>`;
      days.forEach((day, i) => {
        const why = blocked.get(`${day}-${p}`);
        html += `<div class="c${ev}${why ? " blocked" : "}" style="grid-row:${rowOf(p)};grid-column:${i + 2}" ${why ? `title="${esc(why)}"` : "}></div>`;
      });
    });
    html += `<div class="lunch" style="grid-row:7">Lunch 13:00–14:00</div>`;
    entries.forEach((e, k) => {
      const col = days.indexOf(e.day) + 2;
      const groups = e.groups || [];
      const label = state.view === "room" ? groups.join(", ") : state.view === "group" ? `${e.room_id} · ${e.faculty_id}` : `${e.room_id} · ${groups.join(", ")}`;
      html += `<div class="cell ${e.kind}${e.moved_for_spectra ? " moved" : "}" data-k="${k}" style="grid-column:${col};grid-row:${rowOf(+e.start_period)} / span ${+e.duration}">
        <b>${esc(e.course_id)} <span class="muted" style="font-weight:400">${esc(e.kind)}</span></b><span class="m">${esc(label)}</span>
        ${e.moved_for_spectra ? `<span class="m">moved for ${esc(e.moved_for_spectra.by)}</span>` : ""}</div>`;
    });
    events.forEach((ev, k) => {
      const col = days.indexOf(ev.day) + 2;
      const startP = +ev.start_period;
      const endP = +ev.end_period;
      const span = Math.max(1, endP - startP + 1);
      const isGuest = ev.requested_category === "guest_lecture";
      const roomLabel = ev.room_id ? `${ev.room_id}` : "";
      const requesterLabel = ev.requester ? `${ev.requester}` : "";
      const subLabel = state.view === "room" 
        ? requesterLabel 
        : (roomLabel && requesterLabel ? `${roomLabel} · ${requesterLabel}` : (roomLabel || requesterLabel));

      html += `<div class="cell event" data-ev="${k}" style="grid-column:${col};grid-row:${rowOf(startP)} / span ${span}">
        <b>${esc(ev.title || ev.request_id)}</b>
        <span class="m">${esc(subLabel)}</span>
        ${isGuest ? `<span style="display:inline-block;padding:1px 5px;font-size:10px;border-radius:3px;background:rgba(180,50,50,0.15);color:#8a2525;font-weight:600;margin-top:2px;">Guest Lecture</span>` : ""}
      </div>`;
    });
    html += `</div></div>`;
    document.getElementById("grid").innerHTML = html;
    const hours = entries.reduce((n, e) => n + +e.duration, 0);
    const eventCount = events.length;
    const versionId = d.version?.id ?? "–";
    document.getElementById("summary").innerHTML = `${entries.length} regular sessions · ${hours} hours a week${eventCount ? ` · ${eventCount} scheduled event(s)/guest lecture(s)` : ""} · version <span class="mono">${versionId}</span>`;
    document.querySelectorAll(".cell[data-k]").forEach(c => (c.onclick = () => showSession(entries[c.dataset.k])));
    document.querySelectorAll(".cell[data-ev]").forEach(c => (c.onclick = () => (location.href = `bookings.html?id=${encodeURIComponent(events[c.dataset.ev].request_id)}`)));
  }t_id)}`)));
  }

  function showSession(e) {
    const end = +e.start_period + +e.duration - 1;
    const dr = CIT.openDrawer(`<div class="drawer-head"><div><h2 style="margin:0">${esc(e.course_title || e.course_id)}</h2>
        <div class="mono sub">${esc(e.session_id)}</div></div><button class="btn ghost small" data-close aria-label="Close">${icon("x")}</button></div>
      <dl class="kv"><dt>When</dt><dd>${slot(e.day, e.start_period, end)} <span class="muted">${periodTime(e.start_period)}</span></dd>
        <dt>Room</dt><dd class="mono">${esc(e.room_id)}</dd><dt>Kind</dt><dd>${esc(e.kind)}, ${e.duration} h</dd>
        <dt>Faculty</dt><dd>${esc(e.faculty_name)} <span class="mono muted">${esc(e.faculty_id)}</span></dd>
        <dt>Groups</dt><dd><div class="chips">${e.groups.map(g => `<span class="chip">${esc(g)}</span>`).join("")}</div></dd>
        <dt>Students</dt><dd class="mono">${e.size}</dd>${e.basket_slot ? `<dt>Elective basket</dt><dd class="mono">${esc(e.basket_slot)}</dd>` : ""}
        ${e.moved_for_spectra ? `<dt>Spectra week</dt><dd>Moved from ${slot(e.moved_for_spectra.from[0], e.moved_for_spectra.from[1])} in ${esc(e.moved_for_spectra.from[2])} for <a href="bookings.html?id=${esc(e.moved_for_spectra.by)}">${esc(e.moved_for_spectra.by)}</a></dd>` : ""}</dl>
      <div style="margin-top:20px"><button class="btn" id="why">Why here?</button></div><div id="whyOut" style="margin-top:12px"></div>`);
    dr.querySelector("#why").onclick = async ev => {
      try {
        const x = await CIT.busy(ev.currentTarget, "Checking", () => CIT.api(`schedule/explain.php?session_id=${encodeURIComponent(e.session_id)}`));
        const blocked = {};
        x.grid.filter(r => r.status === "blocked").forEach(r => (blocked[r.why] = (blocked[r.why] || 0) + 1));
        const open = x.grid.filter(r => r.status === "possible").sort((a, b) => a.penalty - b.penalty).slice(0, 5);
        dr.querySelector("#whyOut").innerHTML = `<p>${esc(x.text)}</p>
          <h3 style="margin-top:12px">Ruled out</h3><table>${Object.entries(blocked).sort((a, b) => b[1] - a[1]).slice(0, 6).map(([w, n]) => `<tr><td>${esc(w)}</td><td class="num mono">${n}</td></tr>`).join("")}</table>
          ${open.length ? `<h3 style="margin-top:12px">Other open slots</h3><table>${open.map(o => `<tr><td>${slot(o.day, o.start_period)}</td><td class="mono">${esc(o.room)}</td><td class="sub">${o.penalty ? esc(o.penalty_parts.join("; ")) : "no local penalty"}</td></tr>`).join("")}</table>` : ""}`;
      } catch (err) { dr.querySelector("#whyOut").innerHTML = CIT.errorHtml(err); }
    };
  }
})();
