// Spectra-week requests: queue, filters, detail with the decision and its reason; Admin/department review.  [M2]
(async () => {
  const user = await CIT.boot("bookings");
  if (!user) return;
  const main = document.getElementById("main");
  const isAdmin = user.role === "admin";
  const isCoordinator = user.role === "department_coordinator";
  const canReview = isAdmin || isCoordinator;
  const filters = [["", "All"], ["to_review", "To review"], ["approved", "Approved"], ["alternative_offered", "Alternatives"], ["rejected", "Rejected"], ["cancelled", "Cancelled"]];
  let status = qs.get("status") || "";
  main.innerHTML = `<div class="page-head"><div><h1>Spectra bookings</h1>
      <div class="sub">Week 7. ${isCoordinator ? `Showing requests for ${esc(user.department || "your department")}; you cannot review your own requests.` : "Decided in priority order: institute events, guest lectures, department events, club events; earlier requests win ties."}</div></div>
    <div class="actions"><a class="btn primary" href="request.html">${icon("plus")} New request</a></div></div>
    <div class="actions" style="margin-bottom:12px"><div class="seg" id="f">${filters.filter(f => canReview || f[0] !== "to_review").map(([k, l]) => `<button data-s="${k}">${l}</button>`).join("")}</div>
      <input type="search" id="q" placeholder="Search" style="max-width:240px"></div>
    <div class="panel" style="padding:0"><div class="table-wrap" id="list"><div class="loading" style="padding:20px"><span class="spin"></span> Loading</div></div></div>`;
  document.querySelectorAll("#f button").forEach(b => (b.onclick = () => { status = b.dataset.s; load(); }));
  let t;
  document.getElementById("q").oninput = () => { clearTimeout(t); t = setTimeout(load, 250); };
  await load();
  if (qs.get("id")) open(qs.get("id"));

  async function load() {
    document.querySelectorAll("#f button").forEach(b => b.classList.toggle("on", b.dataset.s === status));
    const q = document.getElementById("q").value.trim();
    let d;
    try { d = await CIT.api(`bookings/list.php?status=${status}${q ? "&q=" + encodeURIComponent(q) : ""}`); }
    catch (e) { document.getElementById("list").innerHTML = CIT.errorHtml(e); return; }
    // Keep the visible list filtered even if a cached or older API response
    // ignores the query parameters. The API applies the same filters server-side.
    const needle = q.toLocaleLowerCase();
    const rows = (Array.isArray(d?.requests) ? d.requests : []).filter(r => {
      const matchesStatus = !status
        || (status === "to_review" && r.review_state === "proposed")
        || (status === "cancelled" && r.state === "cancelled")
        || (["approved", "rejected", "alternative_offered"].includes(status) && r.status === status);
      const searchable = [r.id, r.title, r.requester].some(value => String(value ?? "").toLocaleLowerCase().includes(needle));
      return matchesStatus && searchable;
    });
    if (!rows.length) { document.getElementById("list").innerHTML = `<div class="empty">No requests here.</div>`; return; }
    document.getElementById("list").innerHTML = `<table><tr><th>ID</th><th>Request</th><th>Category</th><th>Asked for</th><th>Decision</th><th>Room and time</th>${canReview ? "<th>Review</th>" : ""}</tr>
      ${rows.map(r => `<tr class="clickable" data-id="${esc(r.id)}"><td class="mono">${esc(r.id)}</td>
        <td><div>${esc(r.title)}</div><div class="muted" style="font-size:12px">${esc(r.requester)} · ${r.attendance} people${r.series_id ? ` · series <span class="mono">${esc(r.series_id)}</span>` : ""}</div></td>
        <td>${words(r.effective_category || r.requested_category)}${r.effective_category && r.effective_category !== r.requested_category ? `<div class="muted" style="font-size:12px">asked: ${words(r.requested_category)}</div>` : ""}</td>
        <td class="mono" style="font-size:12px">${esc(r.windows)}</td>
        <td>${r.state === "cancelled" ? statusTag("cancelled") : r.status ? statusTag(r.status) : statusTag("pending", "Waiting for timetable")}</td>
        <td>${r.room_id ? `<span class="mono">${esc(r.room_id)}</span> ${slot(r.day, r.start_period, r.end_period)}` : ""}</td>
        ${canReview ? `<td>${r.review_state ? statusTag(r.review_state) : ""}</td>` : ""}</tr>`).join("")}</table>`;
    document.querySelectorAll("#list tr[data-id]").forEach(tr => (tr.onclick = () => open(tr.dataset.id)));
  }

  async function open(id) {
    document.querySelectorAll("#list tr").forEach(tr => tr.classList.toggle("selected", tr.dataset.id === id));
    let r;
    try { r = await CIT.api("bookings/get.php?id=" + encodeURIComponent(id)); } catch (e) { CIT.toast(e.message, true); return; }
    const d = r.decision;
    const capped = d && d.effective_category !== r.requested_category;
    const notes = d?.alternatives?.notes || [];
    const canActOnDecision = canReview && d && r.state !== "cancelled" && r.can_review;
    const dr = CIT.openDrawer(`<div class="drawer-head"><div><div class="mono sub">${esc(r.id)}${r.source === "app" ? " · made in the app" : ""}</div>
        <h2 style="margin:2px 0 0">${esc(r.title)}</h2></div><button class="btn ghost small" data-close aria-label="Close">${icon("x")}</button></div>
      ${d ? `<div class="callout ${d.status === "approved" ? "ok" : d.status === "rejected" ? "bad" : "warn"}">${statusTag(d.status)}
          ${d.room_id ? ` <span class="mono">${esc(d.room_id)}</span> ${slot(d.day, d.start_period, d.end_period)} <span class="muted">${periodTime(d.start_period)}</span>` : ""}
          <div style="margin-top:6px">${esc(d.reason)}</div>${notes.length ? `<ul>${notes.map(n => `<li>${esc(n)}</li>`).join("")}</ul>` : ""}</div>`
        : r.state === "cancelled" ? `<div class="callout">${statusTag("cancelled")} ${esc(r.cancellation?.note || "")}</div>` : `<div class="callout">No timetable yet, so no decision.</div>`}
      ${r.raw_text ? `<div class="quote">${esc(r.raw_text)}</div>` : ""}
      <dl class="kv"><dt>Requester</dt><dd>${esc(r.requester)} <span class="muted">${words(r.role)}</span></dd>
        <dt>Category</dt><dd>${words(d?.effective_category || r.requested_category)}${capped ? ` <span class="muted">(asked for ${words(r.requested_category)}, capped by role)</span>` : ""}</dd>
        <dt>Submitted</dt><dd class="mono">${esc(r.submitted_at)}</dd><dt>Attendance</dt><dd class="mono">${r.attendance}</dd>
        <dt>Duration</dt><dd>${r.duration_periods} ${r.duration_periods > 1 ? "periods" : "period"}</dd>
        <dt>Windows</dt><dd>${r.windows.map(w => slot(w.day, w.from_period, w.to_period)).join("<br>")}</dd>
        <dt>Room types</dt><dd>${r.room_types.map(words).join(", ")}</dd><dt>Needs</dt><dd>${r.needs.length ? r.needs.map(words).join(", ") : "none"}</dd>
        <dt>Wished room</dt><dd class="mono">${esc(r.requested_room || "any")}</dd>
        ${r.mandatory_for_groups.length ? `<dt>Mandatory for</dt><dd class="mono">${esc(r.mandatory_for_groups.join(", "))}</dd>` : ""}
        ${d?.review_state && d.review_state !== "proposed" ? `<dt>Review</dt><dd>${statusTag(d.review_state)} ${esc(d.reviewed_by_name || "")}</dd>` : ""}</dl>
      ${r.history.length ? `<h3 style="margin-top:16px">History</h3><table>${r.history.map(h => `<tr><td class="muted">${when(h.created_at)}</td><td>${esc(h.action)}</td><td>${esc(h.display_name || "engine")}</td><td class="sub">${esc(h.comment || "")}</td></tr>`).join("")}</table>` : ""}
      <div class="actions" style="margin-top:20px">
        ${canActOnDecision && d.review_state === "proposed" ? `<button class="btn primary" id="confirm">${icon("check")} Confirm</button>` : ""}
        ${canActOnDecision && d.status !== "rejected" ? `<button class="btn danger" id="reject">Reject</button>` : ""}
        ${canReview && r.is_mine && !r.can_review ? `<span class="muted">This is your own request, so another authorized reviewer must handle it.</span>` : ""}
        ${r.is_mine && r.state !== "cancelled" ? `<button class="btn" id="cancel">Cancel request</button>` : ""}
      </div><div id="act"></div>`);
    dr.querySelector("#confirm")?.addEventListener("click", ev => act(ev.currentTarget, "bookings/review.php", { id, action: "confirm" }, "Confirmed"));
    dr.querySelector("#reject")?.addEventListener("click", () => {
      dr.querySelector("#act").innerHTML = `<div class="panel" style="margin-top:12px"><label for="why">Reason for rejecting</label><textarea id="why" rows="2"></textarea>
        <p class="muted" style="font-size:12px;margin-top:6px">The room goes to the next request in priority order. The validator counts this as a priority inversion if a lower-priority request ends up with the room.</p>
        <button class="btn danger" id="doReject">Reject ${esc(id)}</button></div>`;
      dr.querySelector("#doReject").onclick = ev => act(ev.currentTarget, "bookings/review.php", { id, action: "reject", reason: dr.querySelector("#why").value.trim() }, "Rejected; decisions updated");
    });
    dr.querySelector("#cancel")?.addEventListener("click", ev => { if (confirm(`Cancel ${id}? This cannot be undone.`)) act(ev.currentTarget, "bookings/cancel.php", { id }, "Cancelled"); });
  }

  async function act(btn, path, body, done) {
    try { await CIT.busy(btn, "Saving", () => CIT.api(path, body)); CIT.toast(done); await load(); open(body.id); }
    catch (e) { document.getElementById("act").innerHTML = CIT.errorHtml(e); }
  }
})();
