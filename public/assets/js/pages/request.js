// New request: plain text -> editable form (or fill the form by hand) -> deterministic decision.  [M2 + M4]
(async () => {
  const user = await CIT.boot("request");
  if (!user) return;
  const meta = await CIT.loadMeta();
  const main = document.getElementById("main");
  const rooms = (meta.rooms || []).map(r => ({ ...r, allowed_uses: Array.isArray(r.allowed_uses) ? r.allowed_uses : [], features: Array.isArray(r.features) ? r.features : [] }));
  const types = [...new Set(rooms.filter(r => r.allowed_uses.includes("event")).map(r => r.type))];
  const needs = [...new Set(rooms.flatMap(r => r.features)), "accessible"];
  const cap = (meta.role_caps || {})[user.role];
  const capRank = (meta.categories || []).find(c => c.code === cap)?.rank ?? 99;
  const cats = (meta.categories || []).filter(c => +c.bookable);
  const periodOpts = sel => (meta.periods || []).map(p => `<option value="${p.id}" ${+sel === +p.id ? "selected" : ""}>P${p.id} ${p.start}</option>`).join("");

  main.innerHTML = `<div class="page-head"><div><h1>New request page</h1>
      <div class="sub">Describe it in your own words or fill the form. Nothing is booked until you submit, and the decision follows the priority rules.</div></div></div>
    <div class="cols-even">
      <div class="panel"><h2>Describe it</h2>
        <textarea id="text" rows="4" placeholder="e.g. seminar hall kal shaam 6 baje, 2 ghante, 100 log, mic chahiye"></textarea>
        <div class="actions" style="margin-top:8px"><button class="btn" id="parse">Fill the form</button>
          <span class="muted" style="font-size:12px">${CIT.aiAvailable ? "Uses the AI parser, with the built-in parser as a fallback" : "Built-in parser (English and Hinglish)"}</span></div>
        <div id="parsed" style="margin-top:12px"></div>
        <h3 style="margin-top:16px">Examples</h3>
        <div class="suggest">${["Need a room for 45 people on Tuesday 6 to 8 pm, projector please.", "I want SH-2 on Friday at 10 am for a wheelchair-accessible poster session, 60 people.",
          "Our club meets every Monday and Wednesday 7 to 8 pm, 30 people, any small room is fine.", "Need somewhere for the quiz finals after lunch on Thursday, maybe 150 people."]
          .map(x => `<button type="button">${esc(x)}</button>`).join("")}</div>
      </div>
      <form class="panel" id="form" novalidate><h2>Request</h2>
        <div class="field"><label for="title">Title</label><input type="text" id="title" required maxlength="200"></div>
        <div class="row"><div class="field"><label for="att">Attendance</label><input type="number" id="att" min="1" max="5000" required></div>
          <div class="field"><label for="dur">Duration (periods of 1 hour)</label><input type="number" id="dur" min="1" max="13" value="2" required></div>
          <div class="field"><label for="cat">Category</label><select id="cat">${cats.map(c => `<option value="${c.code}" ${c.code === cap ? "selected" : ""}>${words(c.code)}${+c.rank < capRank ? " (counted as " + words(cap) + ")" : ""}</option>`).join("")}</select></div></div>
        <label>When it may take place <span class="muted">(the event must fit inside one window)</span></label>
        <div id="wins"></div><button type="button" class="btn small ghost" id="addWin">${icon("plus")} Add a window</button>
        <label class="check" style="margin:8px 0 12px"><input type="checkbox" id="repeat"> Separate booking for each window (a weekly series)</label>
        <div class="field"><label>Room types</label><div class="checks" id="types">${types.map(t => `<label class="check"><input type="checkbox" value="${t}"> ${words(t)}</label>`).join("")}</div></div>
        <div class="field"><label>Needs</label><div class="checks" id="needs">${needs.map(n => `<label class="check"><input type="checkbox" value="${n}"> ${words(n)}</label>`).join("")}</div></div>
        <div class="row"><div class="field"><label for="room">Preferred room <span class="muted">(optional)</span></label>
            <select id="room"><option value="">Any suitable room</option>${rooms.filter(r => r.allowed_uses.includes("event")).map(r => `<option value="${r.id}">${r.id} · ${esc(r.name)} (${r.capacity}${r.accessible ? "" : ", not step-free"})</option>`).join("")}</select></div>
          <div class="field"><label for="groups">Mandatory for groups <span class="muted">(optional)</span></label>
            <select id="groups" multiple size="3">${(meta.groups || []).map(g => `<option value="${g.id}">${g.id} · ${esc(g.name)}</option>`).join("")}</select></div></div>
        <div class="actions"><button class="btn primary" id="submit">Submit request</button><button type="button" class="btn" id="avail">Check rooms for the first window</button></div>
        <div id="result" style="margin-top:12px"></div>
      </form></div>`;

  const winsEl = document.getElementById("wins");
  const days = meta.days || [];
  function addWindow(w = { day: days[0], from_period: 10, to_period: 13 }) {
    const row = document.createElement("div");
    row.className = "row win";
    row.innerHTML = `<div class="field"><select class="wd" aria-label="Day">${days.map(d => `<option ${d === w.day ? "selected" : ""}>${d}</option>`).join("")}</select></div>
      <div class="field"><select class="wf" aria-label="From">${periodOpts(w.from_period)}</select></div>
      <div class="field"><select class="wt" aria-label="Until">${periodOpts(w.to_period)}</select></div>
      <button type="button" class="btn ghost small" aria-label="Remove window" style="margin-top:3px">${icon("x")}</button>`;
    row.querySelector("button").onclick = () => { if (winsEl.children.length > 1) row.remove(); };
    winsEl.appendChild(row);
  }
  addWindow();
  document.getElementById("addWin").onclick = () => addWindow();
  document.querySelectorAll(".suggest button").forEach(b => (b.onclick = () => { document.getElementById("text").value = b.textContent; parse(); }));
  document.getElementById("parse").onclick = parse;

  const setChecks = (id, vals) => document.querySelectorAll(`#${id} input`).forEach(i => (i.checked = (vals || []).includes(i.value)));
  let aiId = null, raw = null;
  function fill(p) {
    const f = p.fields;
    aiId = p.ai_interaction_id; raw = p.raw_text;
    document.getElementById("title").value = f.title || "";
    document.getElementById("att").value = f.attendance || "";
    document.getElementById("dur").value = f.duration_periods || 2;
    if (f.requested_category) document.getElementById("cat").value = f.requested_category;
    winsEl.innerHTML = "";
    (f.windows.length ? f.windows : [undefined]).forEach(w => addWindow(w));
    document.getElementById("repeat").checked = !!f.repeat;
    setChecks("types", f.room_types);
    setChecks("needs", f.needs);
    document.getElementById("room").value = f.requested_room || "";
    [...document.getElementById("groups").options].forEach(o => (o.selected = (f.mandatory_for_groups || []).includes(o.value)));
    document.getElementById("parsed").innerHTML =
      (p.follow_up ? `<div class="callout warn"><b style="font-weight:500">Question:</b> ${esc(p.follow_up)}</div>` : "") +
      (p.warnings?.length ? `<div class="callout warn">${p.warnings.map(esc).join("<br>")}
         ${p.suggested_rooms?.length ? `<div class="actions" style="margin-top:8px">${p.suggested_rooms.map(s => `<button type="button" class="btn small" data-room="${s.room_id}">Use ${s.room_id}</button>`).join("")}</div>` : ""}</div>` : "") +
      (p.assumptions?.length ? `<div class="callout"><b style="font-weight:500">Assumed</b><ul>${p.assumptions.map(a => `<li>${esc(a)}</li>`).join("")}</ul></div>` : "") +
      `<p class="muted" style="font-size:12px">Read by the ${p.parsed_by === "llm" ? "AI parser" : "built-in parser"}. Check the form before submitting.</p>`;
    document.querySelectorAll("[data-room]").forEach(b => (b.onclick = () => { document.getElementById("room").value = b.dataset.room; CIT.toast(`Preferred room set to ${b.dataset.room}`); }));
  }
  async function parse() {
    const text = document.getElementById("text").value.trim();
    if (!text) return;
    try { fill(await CIT.busy(document.getElementById("parse"), "Reading", () => CIT.api("ai/parse_booking.php", { text }))); }
    catch (e) { document.getElementById("parsed").innerHTML = CIT.errorHtml(e) + `<p class="muted">You can still fill the form by hand.</p>`; }
  }
  const fromAssistant = sessionStorage.getItem("cit_parsed");
  if (fromAssistant) { sessionStorage.removeItem("cit_parsed"); const p = JSON.parse(fromAssistant); document.getElementById("text").value = p.raw_text || ""; fill(p); }

  function collect() {
    return {
      title: document.getElementById("title").value.trim(),
      attendance: +document.getElementById("att").value || null,
      duration_periods: +document.getElementById("dur").value || null,
      requested_category: document.getElementById("cat").value,
      windows: [...document.querySelectorAll(".win")].map(r => ({ day: r.querySelector(".wd").value, from_period: +r.querySelector(".wf").value, to_period: +r.querySelector(".wt").value })),
      repeat: document.getElementById("repeat").checked,
      room_types: [...document.querySelectorAll("#types input:checked")].map(i => i.value),
      needs: [...document.querySelectorAll("#needs input:checked")].map(i => i.value),
      requested_room: document.getElementById("room").value || null,
      mandatory_for_groups: [...document.getElementById("groups").selectedOptions].map(o => o.value),
      raw_text: raw, ai_interaction_id: aiId,
    };
  }
  document.getElementById("form").onsubmit = async e => {
    e.preventDefault();
    const body = collect();
    const out = document.getElementById("result");
    try {
      const r = await CIT.busy(document.getElementById("submit"), "Deciding", () => CIT.api("bookings/create.php", body));
      out.innerHTML = r.decisions.map((d, i) => d ? `<div class="callout ${d.status === "approved" ? "ok" : d.status === "rejected" ? "bad" : "warn"}">
          <span class="mono">${esc(r.request_ids[i])}</span> ${statusTag(d.status)} ${d.room_id ? `<span class="mono">${esc(d.room_id)}</span> ${slot(d.day, d.start_period, d.end_period)}` : ""}
          <div style="margin-top:4px">${esc(d.reason)}</div>${d.effective_category !== body.requested_category ? `<div class="muted">Counted as ${words(d.effective_category)} for your role.</div>` : ""}</div>`
        : `<div class="callout"><span class="mono">${esc(r.request_ids[i])}</span> saved. It will be decided once a timetable exists.</div>`).join("") +
        `<a href="bookings.html?id=${encodeURIComponent(r.request_ids[0])}">Open in Spectra bookings</a>`;
    } catch (err) { out.innerHTML = CIT.errorHtml(err); }
  };
  document.getElementById("avail").onclick = async ev => {
    const b = collect();
    const w = b.windows[0];
    const out = document.getElementById("result");
    try {
      const d = await CIT.busy(ev.currentTarget, "Checking", () => CIT.api(`bookings/availability.php?day=${w.day}&from=${w.from_period}&to=${w.to_period}&attendance=${b.attendance || 0}&types=${b.room_types.join(",")}&needs=${b.needs.join(",")}`));
      const rows = d.rooms.sort((x, y) => (y.suitable && y.free) - (x.suitable && x.free));
      out.innerHTML = `<h3>Rooms ${slot(d.day, d.from, d.to)}, Spectra week</h3><div class="table-wrap"><table><tr><th>Room</th><th>Seats</th><th>Fits</th><th>Free</th></tr>
        ${rows.map(r => `<tr><td class="mono">${esc(r.room_id)}</td><td class="mono">${r.capacity}</td><td>${r.suitable ? statusTag("ok", "Yes") : `<span class="sub">${esc(r.problems.join("; "))}</span>`}</td>
          <td>${r.free ? statusTag("ok", "Free") : `<span class="sub">${esc(r.taken_by.slice(0, 2).join("; "))}</span>`}</td></tr>`).join("")}</table></div>
        <p class="muted" style="font-size:12px">For information only: the decision still follows priority order when you submit.</p>`;
    } catch (err) { out.innerHTML = CIT.errorHtml(err); }
  };
})();
