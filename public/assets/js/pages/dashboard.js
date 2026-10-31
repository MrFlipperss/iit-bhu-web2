// Overview: one summary strip, then what needs attention, conflicts and room use.  [M4]
(async () => {
  const user = await CIT.boot("dashboard");
  if (!user) return;
  const main = document.getElementById("main");
  const isAdmin = user.role === "admin";
  let s;
  try { s = await CIT.api("dashboard/summary.php"); } catch (e) { main.innerHTML = CIT.errorHtml(e); return; }

  const head = `<div class="page-head"><div><h1>Overview</h1>
      <div class="sub">${s.version ? `Timetable version <span class="mono">${s.version.id}</span> · ${s.version.kind === "repair" ? "repaired after a change" : "generated"} ${when(s.version.activated_at)}${s.version.solve_seconds ? ` in ${Math.round(s.version.solve_seconds)} s` : ""}` : "No timetable yet"}
      ${s.dataset ? ` · dataset <span class="mono">${esc(s.dataset.dataset_id)}</span>${s.dataset.created_reason !== "import" ? " (with applied changes)" : ""}` : ""}</div></div>
    ${isAdmin ? `<div class="actions">
      ${s.version ? `<button class="btn" id="improve" title="Spend another ~35 s lowering soft penalties">${icon("refresh")} Improve</button>` : ""}
      <button class="btn ${s.version ? "" : "primary"}" id="gen">${icon("grid")} ${s.version ? "Regenerate" : "Generate timetable"}</button>
      ${s.version ? `<a class="btn primary" href="/api/schedule/export.php">${icon("download")} schedule.json</a>` : ""}</div>` : ""}</div>`;

  if (!s.version) {
    main.innerHTML = head + `<div class="panel empty"><h2>No timetable yet</h2><p>${isAdmin ? "Generate builds the regular week, decides every Spectra request and plans the midsems. It takes about 35 seconds." : "An Admin needs to generate the timetable first."}</p></div>`;
    bindGenerate();
    return;
  }
  const v = s.validation || {};
  const c = s.decision_counts;
  const sp = v.soft_penalties || {};
  const softNote = Object.entries(sp).filter(([, n]) => n).map(([k, n]) => `${k} ${n}`).join(" · ") || "none";
  const toReview = s.pending.filter(p => p.item_type === "booking_decision").length;
  const strip = `<div class="strip">
    <div><div class="label">Hard violations</div><div class="figure ${v.hard_violation_count ? "bad" : "ok"}">${v.hard_violation_count ?? "–"}</div><div class="note">official validator</div></div>
    <div><div class="label">Soft penalty</div><div class="figure">${v.soft_total ?? "–"}</div><div class="note mono">${esc(softNote)}</div></div>
    <div><div class="label">Spectra requests</div><div class="figure">${(c.approved || 0)}<span class="muted" style="font-size:14px"> approved</span></div>
      <div class="note">${plural(c.alternative_offered || 0, "alternative")} · ${c.rejected || 0} rejected · score ${v.booking_score ?? "–"}</div></div>
    <div><div class="label">Midsem plan</div><div class="figure">${v.exam_soft_penalty ?? "–"}</div><div class="note">same-day exam penalty</div></div>
    ${isAdmin ? `<div><div class="label">Waiting for review</div><div class="figure">${toReview + s.proposals_pending}</div><div class="note">${plural(toReview, "booking")} · ${plural(s.proposals_pending, "repair")}</div></div>` : ""}
  </div>`;

  const violations = v.violations && v.violations.length
    ? `<div class="callout bad">${v.hard_violation_count} hard violations</div><table><tr><th>Rule</th><th>Detail</th></tr>${v.violations.map(x => `<tr><td class="mono">${esc(x.rule)}</td><td>${esc(x.detail)}</td></tr>`).join("")}</table>`
    : `<p class="sub">No clashes. The official validator checked this timetable ${v.created_at ? "at " + when(v.created_at, true) : ""}.</p>`;
  const inv = v.bookings?.priority_inversions || [];
  const invHtml = inv.length ? `<div class="callout warn">Priority inversions (a lower-priority request holds a room a rejected one could use):<ul>${inv.map(i => `<li>${esc(i)}</li>`).join("")}</ul></div>` : "";

  const pendingRows = s.pending.map(p => {
    const href = p.item_type === "booking_decision" ? `bookings.html?id=${encodeURIComponent(p.ref)}` : p.item_type === "reschedule_proposal" ? `reschedule.html?id=${p.item_id}` : "reschedule.html";
    return `<tr class="clickable" onclick="location.href='${href}'"><td>${p.item_type === "booking_decision" ? "Booking" : p.item_type === "reschedule_proposal" ? "Repair" : "Change request"}</td>
      <td class="mono">${esc(p.ref || p.item_id)}</td><td>${esc(p.summary)}</td></tr>`;
  }).join("");
  const mine = s.my_requests.map(r => `<tr class="clickable" onclick="location.href='bookings.html?id=${encodeURIComponent(r.id)}'"><td class="mono">${esc(r.id)}</td><td>${esc(r.title)}</td>
      <td>${r.state === "cancelled" ? statusTag("cancelled") : r.status ? statusTag(r.status) : statusTag("pending")}</td><td>${r.room_id ? esc(r.room_id) + " " + slot(r.day, r.start_period, r.end_period) : ""}</td></tr>`).join("");
  const attention = isAdmin
    ? `<div class="panel"><div class="panel-head"><h2>Waiting for you</h2><span class="muted">${s.pending.length}</span></div>
        ${s.pending.length ? `<div class="table-wrap"><table><tr><th>Type</th><th>Ref</th><th>Summary</th></tr>${pendingRows}</table></div>` : `<p class="sub">Nothing to review.</p>`}</div>`
    : `<div class="panel"><div class="panel-head"><h2>Your requests</h2><a href="request.html">New request</a></div>
        ${s.my_requests.length ? `<div class="table-wrap"><table><tr><th>ID</th><th>Title</th><th>Decision</th><th>Room and time</th></tr>${mine}</table></div>` : `<p class="sub">You have not asked for a room yet.</p>`}</div>`;

  const maxU = Math.max(...s.utilization.map(u => +u.utilization_pct), 1);
  const util = s.utilization.map((u, i) => `<tr><td class="mono">${esc(u.room_id)}</td><td class="sub">${words(u.type)}</td>
      <td style="width:40%"><div class="bar ${i === 0 ? "lead" : ""}"><i style="width:${(100 * u.utilization_pct / maxU).toFixed(0)}%"></i></div></td>
      <td class="num mono">${u.used_periods}/${u.teaching_periods}</td></tr>`).join("");
  const closures = s.closures.length ? s.closures.map(c => `<tr><td class="mono">${esc(c.room_id)}</td><td>${slot(c.day, c.periods[0], c.periods[c.periods.length - 1])}</td><td class="sub">${esc(c.reason)}</td></tr>`).join("") : "";

  main.innerHTML = head + strip + `<div class="cols"><div class="stack">${attention}
      <div class="panel"><div class="panel-head"><h2>Conflicts</h2></div>${violations}${invHtml}</div>
      ${closures ? `<div class="panel"><div class="panel-head"><h2>Room closures</h2></div><div class="table-wrap"><table>${closures}</table></div></div>` : ""}</div>
    <div class="panel"><div class="panel-head"><h2>Room use</h2><span class="muted">teaching periods a week</span></div>
      <div class="table-wrap"><table><tr><th>Room</th><th>Type</th><th></th><th class="num">Used</th></tr>${util}</table></div></div></div>`;
  bindGenerate();

  function bindGenerate() {
    const run = async (btn, improve) => {
      try {
        const r = await CIT.busy(btn, improve ? "Improving, about 35 s" : "Generating, about 35 s", () => CIT.api("schedule/generate.php", { improve }));
        CIT.toast(r.activated ? `Version ${r.version_id}: ${r.hard_violations} hard violations, soft penalty ${r.soft_total}` : `Version ${r.version_id} has ${r.hard_violations} hard violations and was not activated`, !r.activated);
        setTimeout(() => location.reload(), 900);
      } catch (e) { main.insertAdjacentHTML("afterbegin", CIT.errorHtml(e)); }
    };
    document.getElementById("gen")?.addEventListener("click", e => run(e.currentTarget, false));
    document.getElementById("improve")?.addEventListener("click", e => run(e.currentTarget, true));
  }
})();
