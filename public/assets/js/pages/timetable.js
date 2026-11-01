// Timetable: the weekly planner by group, faculty or room; Spectra-week view shows events and Week-7 moves.  [M1]
(async () => {
  const user = await CIT.boot("timetable");
  if (!user) return;
  const meta = await CIT.loadMeta();
  const main = document.getElementById("main");
  const isFacultyUser = user.role === "faculty" || Boolean(user.faculty_id);
  const isDeptCoord = user.role === "department_coordinator";
  const defaultGroup = isDeptCoord 
    ? ((meta.groups || []).find(g => g.id.includes(user.department || "ECE"))?.id || "ECE-2")
    : (user.faculty_id || (meta.groups || [])[0]?.id || "Y1-A");

  const state = {
    view: isFacultyUser ? "faculty" : (qs.get("view") || "group"),
    id: isFacultyUser ? (user.faculty_id || "F12") : (qs.get("id") || defaultGroup),
    week: qs.get("week") || "regular",
    hours: qs.get("hours") || "full",
    yearFilter: "all",
    branchFilter: isDeptCoord ? (user.department || "ECE") : "all",
    typeFilter: "all" // all, sessions, spectra, exams
  };

  const lists = {
    group: (meta.groups || []).map(g => [g.id, `${g.id} · ${g.name}`]),
    faculty: (meta.faculty || []).map(f => [f.id, `${f.id} · ${f.name}`]),
    room: (meta.rooms || []).map(r => [r.id, `${r.id} · ${r.name} (${r.capacity})`]),
  };

  const facultyNotice = isFacultyUser 
    ? `<div class="callout ok" style="margin-bottom:14px;padding:8px 12px;font-size:13px">Showing your teaching sessions and scheduled exam duties (${esc(user.display_name)} · ${esc(user.faculty_id || 'Faculty')}).</div>`
    : "";

  main.innerHTML = `<div class="page-head"><div><h1>Timetable</h1><div class="sub" id="summary"></div></div>
    <div class="actions" style="flex-wrap:wrap;gap:8px">
      ${!isFacultyUser ? `<div class="seg" id="view">${["group", "faculty", "room"].map(v => `<button data-v="${v}">${{ group: "Group", faculty: "Faculty", room: "Room" }[v]}</button>`).join("")}</div>
      <select id="pick" aria-label="Choose" style="width:auto;min-width:200px"></select>` : ""}
      <div class="seg" id="week"><button data-w="regular">Regular week</button><button data-w="fest">Spectra fest</button></div>
      <div class="seg" id="hours"><button data-h="full">Full day (8–22)</button><button data-h="college">College hours (8–18)</button></div>
    </div></div>
    ${facultyNotice}
    <div class="panel" style="padding:10px 14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;background:var(--surface)">
      <span style="font-weight:600;font-size:12.5px;color:var(--text-2)">Quick Filters:</span>
      <div style="display:flex;align-items:center;gap:6px">
        <label style="margin:0;font-size:12px">Year:</label>
        <select id="filt-year" style="width:auto;height:28px;padding:0 8px;font-size:12px">
          <option value="all">All Years</option>
          <option value="Y1">Year 1</option>
          <option value="2">Year 2</option>
          <option value="3">Year 3</option>
          <option value="4">Year 4</option>
        </select>
      </div>
      <div style="display:flex;align-items:center;gap:6px">
        <label style="margin:0;font-size:12px">Branch:</label>
        <select id="filt-branch" style="width:auto;height:28px;padding:0 8px;font-size:12px">
          <option value="all">All Branches</option>
          <option value="CSE">CSE</option>
          <option value="ECE">ECE</option>
          <option value="EE">EE</option>
          <option value="ME">ME</option>
          <option value="CE">CE</option>
        </select>
      </div>
      <div style="display:flex;align-items:center;gap:6px">
        <label style="margin:0;font-size:12px">Show Type:</label>
        <select id="filt-type" style="width:auto;height:28px;padding:0 8px;font-size:12px">
          <option value="all">All (Classes, Events & Exams)</option>
          <option value="sessions">Teaching Sessions Only</option>
          <option value="spectra">Spectra Fest & Clubs Only</option>
          <option value="exams">Exams Only</option>
        </select>
      </div>
    </div>
    <div id="grid"></div>
    <div class="legend" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;font-size:12px">
      <span class="lecture" style="padding:2px 8px;border-radius:4px;border:1px solid var(--k-lecture-b);background:var(--k-lecture)">Lecture</span>
      <span class="tutorial" style="padding:2px 8px;border-radius:4px;border:1px solid var(--k-tutorial-b);background:var(--k-tutorial)">Tutorial</span>
      <span class="lab" style="padding:2px 8px;border-radius:4px;border:1px solid var(--k-lab-b);background:var(--k-lab)">Lab, 3h</span>
      <span class="event" style="padding:2px 8px;border-radius:4px;border:1px solid var(--k-event-b);background:var(--k-event)">Spectra Fest / Club Event</span>
      <span class="exam" style="padding:2px 8px;border-radius:4px;border:1px solid var(--k-exam-b);background:var(--k-exam)">College Exam Duty</span>
      <span class="blocked" style="padding:2px 8px;border-radius:4px;background:#eee">Unavailable</span>
    </div>`;

  const pick = document.getElementById("pick");
  function syncControls() {
    document.querySelectorAll("#view button").forEach(b => b.classList.toggle("on", b.dataset.v === state.view));
    document.querySelectorAll("#week button").forEach(b => b.classList.toggle("on", b.dataset.w === state.week));
    document.querySelectorAll("#hours button").forEach(b => b.classList.toggle("on", b.dataset.h === state.hours));
    if (pick) {
      pick.innerHTML = lists[state.view].map(([id, label]) => `<option value="${esc(id)}" ${id === state.id ? "selected" : ""}>${esc(label)}</option>`).join("");
      if (!lists[state.view].some(([id]) => id === state.id)) state.id = lists[state.view][0][0];
    }
  }

  document.querySelectorAll("#view button").forEach(b => (b.onclick = () => { 
    state.view = b.dataset.v; 
    state.id = lists[state.view]?.[0]?.[0] || ""; 
    syncControls(); 
    load(); 
  }));
  document.querySelectorAll("#week button").forEach(b => (b.onclick = () => { state.week = b.dataset.w; syncControls(); load(); }));
  document.querySelectorAll("#hours button").forEach(b => (b.onclick = () => { state.hours = b.dataset.h; syncControls(); renderCurrent(); }));
  if (pick) pick.onchange = () => { state.id = pick.value; syncControls(); load(); };

  document.getElementById("filt-year").onchange = e => { state.yearFilter = e.target.value; renderCurrent(); };
  document.getElementById("filt-branch").onchange = e => { state.branchFilter = e.target.value; renderCurrent(); };
  document.getElementById("filt-type").onchange = e => { state.typeFilter = e.target.value; renderCurrent(); };

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
    try { 
      const facultyParam = isFacultyUser ? `&faculty_id=${encodeURIComponent(user.faculty_id || "F12")}` : "";
      d = await CIT.api(`schedule/get.php?view=${state.view}&id=${encodeURIComponent(state.id)}${facultyParam}&week=${state.week}`); 
    }
    catch (e) { grid.innerHTML = e.code === "no_timetable" ? `<div class="panel empty">${esc(e.message)}</div>` : CIT.errorHtml(e); return; }
    cachedData = d;
    render(d);
  }

  function render(d) {
    const days = meta.days || [];
    let entries = d.entries || [];
    let events = d.events || [];
    let exams = d.exams || [];
    const closures = d.closures || [];
    const unavailable = d.unavailable || [];

    // Filter by Faculty if current user is faculty
    if (isFacultyUser) {
      const myId = user.faculty_id || "F12";
      entries = entries.filter(e => e.faculty_id === myId);
      exams = exams.filter(x => (x.invigilators || []).includes(myId) || (x.faculty_id === myId));
      if (state.typeFilter === "spectra") {
        entries = [];
        exams = [];
      }
    }

    // Apply Branch filter if set
    if (state.branchFilter && state.branchFilter !== "all") {
      const bCode = state.branchFilter;
      const bMatches = entries.filter(e => (e.groups || []).some(g => g.includes(bCode)) || (e.course_id && e.course_id.startsWith(bCode)));
      if (bMatches.length > 0) {
        entries = bMatches;
        exams = exams.filter(x => (x.groups || []).some(g => g.includes(bCode)) || (x.course_id && x.course_id.startsWith(bCode)));
      }
    }

    // Apply Year filter if set
    if (state.yearFilter && state.yearFilter !== "all") {
      const yCode = state.yearFilter;
      const yMatches = entries.filter(e => (e.groups || []).some(g => g.includes(yCode)));
      if (yMatches.length > 0) {
        entries = yMatches;
        exams = exams.filter(x => (x.groups || []).some(g => g.includes(yCode)));
      }
    }

    // Apply Type Filter
    if (state.typeFilter === "sessions") {
      events = [];
      exams = [];
    } else if (state.typeFilter === "spectra") {
      entries = [];
      exams = [];
    } else if (state.typeFilter === "exams") {
      entries = [];
      events = [];
    }

    const hasEveningActivity = entries.some(e => +e.start_period >= 10 || (+e.start_period + +e.duration - 1) >= 10) ||
                               events.some(ev => +ev.end_period >= 10 || +ev.start_period >= 10);
    const showEvening = state.hours === "full" || hasEveningActivity || (state.week === "fest" && state.view === "room");
    const periods = (meta.periods || []).filter(p => +p.id <= (showEvening ? 13 : 9)).map(p => +p.id);
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
        html += `<div class="c${ev}${why ? " blocked" : ""}" style="grid-row:${rowOf(p)};grid-column:${i + 2}" ${why ? `title="${esc(why)}"` : ""}></div>`;
      });
    });
    html += `<div class="lunch" style="grid-row:7">Lunch 13:00–14:00</div>`;

    // Render Sessions
    entries.forEach((e, k) => {
      const col = days.indexOf(e.day) + 2;
      const groups = e.groups || [];
      const label = state.view === "room" ? groups.join(", ") : state.view === "group" ? `${e.room_id} · ${e.faculty_id}` : `${e.room_id} · ${groups.join(", ")}`;
      html += `<div class="cell ${e.kind}${e.moved_for_spectra ? " moved" : ""}" data-k="${k}" style="grid-column:${col};grid-row:${rowOf(+e.start_period)} / span ${+e.duration}">
        <b>${esc(e.course_id)} <span class="muted" style="font-weight:400">${esc(e.kind)}</span></b><span class="m">${esc(label)}</span>
        ${e.moved_for_spectra ? `<span class="m">moved for ${esc(e.moved_for_spectra.by)}</span>` : ""}</div>`;
    });

    // Render Spectra Events
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

    // Render College Exams
    exams.forEach((ex, k) => {
      const col = days.indexOf(ex.day) + 2;
      if (col < 2) return;
      const startP = ex.session === "AM" ? 2 : 7;
      const span = 3;
      html += `<div class="cell exam" data-ex="${k}" style="grid-column:${col};grid-row:${rowOf(startP)} / span ${span};background:var(--k-exam);border-color:var(--k-exam-b)">
        <b>Exam: ${esc(ex.course_id)}</b>
        <span class="m">${esc((ex.rooms || []).join(", "))} · ${ex.session} Slot</span>
        <span class="m" style="color:var(--accent-ink)">${esc((ex.groups || []).join(", "))}</span>
      </div>`;
    });

    html += `</div></div>`;
    document.getElementById("grid").innerHTML = html;
    const hours = entries.reduce((n, e) => n + +e.duration, 0);
    const eventCount = events.length;
    const examCount = exams.length;
    const versionId = d.version?.id ?? "–";
    document.getElementById("summary").innerHTML = `${entries.length} regular sessions (${hours} h/week)${eventCount ? ` · ${eventCount} Spectra event(s)` : ""}${examCount ? ` · ${examCount} scheduled exam(s)` : ""} · version <span class="mono">${versionId}</span>`;
    document.querySelectorAll(".cell[data-k]").forEach(c => (c.onclick = () => showSession(entries[c.dataset.k])));
    document.querySelectorAll(".cell[data-ev]").forEach(c => (c.onclick = () => (location.href = `bookings.html?id=${encodeURIComponent(events[c.dataset.ev].request_id)}`)));
    document.querySelectorAll(".cell[data-ex]").forEach(c => (c.onclick = () => (location.href = `exams.html`)));
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
