// Reschedule: change file -> minimum-move proposal -> before/after diff -> Admin approval.
// Faculty report their own unavailability here; it becomes a proposal for an Admin.  [M3]
(async () => {
  const user = await CIT.boot("reschedule");
  if (!user) return;
  const main = document.getElementById("main");
  const isAdmin = user.role === "admin";
  if (!isAdmin && user.role !== "faculty") { main.innerHTML = `<div class="panel empty">Only Admins and faculty use this page.</div>`; return; }

  main.innerHTML = `<div class="page-head"><div><h1>${isAdmin ? "Reschedule" : "Report times you can't teach"}</h1>
      <div class="sub">${isAdmin ? "Changes are permanent. The repair moves as few sessions as possible and nothing changes until you approve it." : "This becomes a proposal. An Admin approves it before anything in the timetable moves."}</div></div></div>
    <div class="cols"><div class="stack" id="left"></div><div class="stack" id="right"></div></div>`;
  const left = document.getElementById("left"), right = document.getElementById("right");

  if (isAdmin) {
    left.innerHTML = `<div class="panel"><h2>Campus Disruption & Reschedule</h2>
      <p class="sub">Report faculty leave, room closures, emergency maintenance, or session additions in plain English or structured input.</p>
      
      <div style="margin-bottom:12px">
        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px">Describe the disruption:</label>
        <textarea id="disrupt-prompt" rows="3" placeholder="e.g. Dr. Amit Behera (F12) is on medical leave this Wednesday periods 2 to 5, or LH-1 closed for roof repair all day Friday"></textarea>
      </div>

      <div class="actions" style="margin-top:8px">
        <button class="btn primary" id="propose">Compute Minimal Repairs</button>
        <label class="btn" style="margin:0">Open change file<input type="file" id="file" accept=".json,application/json" hidden></label>
        <button class="btn ghost" id="toggle-json-view">Advanced JSON</button>
      </div>

      <div id="json-container" style="display:none;margin-top:12px">
        <label class="sub" style="display:block;margin-bottom:4px">Starter-pack change file (optional):</label>
        <textarea id="json" rows="6" class="mono" spellcheck="false" placeholder='{"changes": [{"type": "room_unavailable", ...}]}'></textarea>
        <button class="btn ghost" id="sample" style="margin-top:6px">Load starter-pack sample</button>
      </div>

      <div id="pout" style="margin-top:12px"></div>
    </div>
    <div class="panel" id="detail"><p class="sub">Choose a proposal to see what moves.</p></div>`;

    document.getElementById("toggle-json-view").onclick = () => {
      const box = document.getElementById("json-container");
      box.style.display = box.style.display === "none" ? "block" : "none";
    };

    document.getElementById("file").onchange = async e => {
      const f = e.target.files[0];
      if (f) {
        document.getElementById("json-container").style.display = "block";
        document.getElementById("json").value = await f.text();
      }
    };

    document.getElementById("sample").onclick = async () => {
      document.getElementById("json-container").style.display = "block";
      document.getElementById("json").value = await (await fetch("/samples/sample_disruption.json")).text();
    };

    document.getElementById("propose").onclick = async ev => {
      const out = document.getElementById("pout");
      out.innerHTML = "";
      const prompt = document.getElementById("disrupt-prompt").value.trim();
      let text = document.getElementById("json").value.trim();

      if (!text && !prompt) {
        out.innerHTML = `<div class="callout bad">Please describe the disruption or provide a change file.</div>`;
        return;
      }

      try {
        const r = await CIT.busy(ev.currentTarget, "Calculating 3 minimal-move repair options...", async () => {
          return await CIT.api("disruptions/upload.php", { text: text || JSON.stringify({ prompt, timestamp: new Date().toISOString() }) });
        });
        CIT.toast(r.status === "pending" || r.proposals ? `Found optimal repair options` : "No valid repair exists", !r);
        await list();
        if (r.proposal_id) show(r.proposal_id);
      } catch (e) {
        out.innerHTML = CIT.errorHtml(e);
      }
    };
  } else {
    left.innerHTML = `<div class="panel"><h2>When can't you teach?</h2>
      <textarea id="ctext" rows="3" placeholder="e.g. I can't teach on Wednesday mornings for the rest of the semester">${esc(qs.get("text") || "")}</textarea>
      <div class="actions" style="margin-top:8px"><button class="btn" id="preview">Check</button></div><div id="cout" style="margin-top:12px"></div></div>
      <div class="panel" id="detail"><p class="sub">Your reports and their status appear on the right.</p></div>`;
    document.getElementById("preview").onclick = async ev => {
      const out = document.getElementById("cout");
      try {
        const p = await CIT.busy(ev.currentTarget, "Reading", () => CIT.api("changes/request.php", { text: document.getElementById("ctext").value.trim(), preview: true }));
        if (!p.changes.length) { out.innerHTML = `<div class="callout warn">${esc(p.follow_up || "I could not find a day in that.")}</div>`; return; }
        out.innerHTML = `<div class="callout">You, <span class="mono">${esc(user.faculty_id)}</span>, would be unavailable:<ul>${p.changes.map(c => `<li>${c.days.map(d => slot(d, c.periods[0], c.periods[c.periods.length - 1])).join(", ")}</li>`).join("")}</ul>
          ${p.assumptions.length ? `<div class="muted">${p.assumptions.map(esc).join("<br>")}</div>` : ""}</div><button class="btn primary" id="send">Send for approval</button>`;
        document.getElementById("send").onclick = async e2 => {
          try {
            const r = await CIT.busy(e2.currentTarget, "Finding the smallest change", () => CIT.api("changes/request.php", { text: document.getElementById("ctext").value.trim(), changes: p.changes }));
            out.innerHTML = r.status === "pending" ? `<div class="callout ok">Sent. ${r.summary.sessions_moved} of your sessions would move. An Admin will review it.</div>` : `<div class="callout bad">No valid timetable fits that. An Admin can see the details.</div>`;
            list();
          } catch (err) { out.innerHTML = CIT.errorHtml(err); }
        };
      } catch (e) { out.innerHTML = CIT.errorHtml(e); }
    };
  }

  async function list() {
    let rows;
    try { rows = await CIT.api("disruptions/list.php"); } catch (e) { right.innerHTML = CIT.errorHtml(e); return; }
    if (!isAdmin) rows = rows.filter(r => r.change_request_id && r.created_by === user.display_name);
    right.innerHTML = `<div class="panel" style="padding:0"><div class="panel-head" style="padding:16px 16px 0"><h2>Proposals</h2></div>
      ${rows.length ? `<table><tr><th>#</th><th>Change</th><th>Moves</th><th>Status</th></tr>${rows.map(r => `<tr class="clickable" data-id="${r.id}">
        <td class="mono">${r.id}</td><td>${esc(r.external_id || "change file")}<div class="muted" style="font-size:12px">${plural(+r.change_count, "change")} · ${when(r.created_at)}</div></td>
        <td class="mono">${r.sessions_moved ?? "–"}</td><td>${statusTag(r.status)}</td></tr>`).join("")}</table>` : `<div class="empty">No proposals yet.</div>`}</div>`;
    right.querySelectorAll("tr[data-id]").forEach(tr => (tr.onclick = () => show(+tr.dataset.id)));
  }

  function describe(c) {
    const days = c.days ? c.days.join(", ") : "";
    const per = c.periods ? `P${c.periods[0]}–P${c.periods[c.periods.length - 1]}` : "";
    return { faculty_unavailable: `${c.faculty_id} unavailable ${days} ${per}`, room_unavailable: `${c.room_id} closed ${days} ${per}`,
      add_faculty: `New faculty ${c.faculty?.id} ${c.faculty?.name || ""}`, add_session: `New session ${c.session?.id} (${c.session?.kind}, ${c.session?.groups?.join(", ")})`,
      remove_session: `Remove session ${c.session_id}` }[c.type] + (c.reason ? ` · ${c.reason}` : "");
  }

  async function show(id) {
    right.querySelectorAll("tr[data-id]").forEach(tr => tr.classList.toggle("selected", +tr.dataset.id === id));
    const det = document.getElementById("detail");
    det.innerHTML = `<div class="loading"><span class="spin"></span> Loading</div>`;
    let p;
    try { p = await CIT.api("disruptions/proposal.php?id=" + id); } catch (e) { det.innerHTML = CIT.errorHtml(e); return; }
    const changes = (p.disruption?.changes || []).map(describe);
    const head = `<div class="panel-head"><h2>Proposal <span class="mono">${p.id}</span></h2>${statusTag(p.status)}</div>
      ${p.description ? `<p class="sub">${esc(p.description)}</p>` : ""}<ol class="sub" style="padding-left:18px">${changes.map(c => `<li>${esc(c)}</li>`).join("")}</ol>`;
    if (p.status === "infeasible") {
      det.innerHTML = head + `<div class="callout bad">No timetable satisfies every hard rule after these changes. Nothing was changed.<ul>${(p.infeasible_reasons || []).map(r => `<li><span class="mono">${esc(r.constraint)}</span> ${esc(r.detail)}</li>`).join("")}</ul></div>`;
      return;
    }
    const v = p.validation;
    det.innerHTML = head + `<div class="strip" style="margin:12px 0"><div><div class="label">Sessions moved</div><div class="figure">${p.sessions_moved}</div></div>
        <div><div class="label">New time</div><div class="figure">${p.time_changed}</div></div><div><div class="label">Room only</div><div class="figure">${p.room_only_changed}</div></div>
        <div><div class="label">Hard violations</div><div class="figure ${v?.hard_violation_count ? "bad" : "ok"}">${v?.hard_violation_count ?? "–"}</div></div></div>
      <div class="table-wrap"><table><tr><th>Session</th><th>Before</th><th></th><th>After</th><th>Why</th></tr>
        ${p.changes.map(c => `<tr class="changed"><td class="mono">${esc(c.session_id)}<div class="muted" style="font-size:12px;font-family:var(--font)">${esc(c.kind || "")} ${esc(c.faculty_id || "")}</div></td>
          <td>${c.from_day ? `<span class="was">${esc(c.from_day)} · P${c.from_period} ${esc(c.from_room)}</span>` : `<span class="muted">new</span>`}</td><td class="arrow">→</td>
          <td>${c.to_day ? `<span class="to">${esc(c.to_day)} · P${c.to_period} ${esc(c.to_room)}</span>` : `<span class="muted">removed</span>`}</td>
          <td class="sub" style="font-size:12.5px">${esc(c.reason)}</td></tr>`).join("")}</table></div>
      ${p.status === "pending" && isAdmin ? `<div class="field" style="margin-top:16px"><label for="cmt">Comment (optional)</label><input type="text" id="cmt" maxlength="500"></div>
        <div class="actions"><button class="btn primary" id="approve">${icon("check")} Approve and apply</button><button class="btn danger" id="reject">Reject</button></div>
        <p class="muted" style="font-size:12px;margin-top:8px">Approving checks the proposal again with the official validator, makes the change permanent and re-decides Spectra bookings on the new timetable.</p>` : ""}
      ${p.status === "approved" ? `<p style="margin-top:12px">Applied by ${esc(p.decided_by_name || "")} ${when(p.decided_at)}. <a class="btn small primary" href="/exports/schedule_after.json" download="schedule_after.json">${icon("download")} schedule_after.json</a></p>` : ""}
      <div id="dout"></div>`;
    const decide = async (btn, action) => {
      try {
        await CIT.busy(btn, action === "approve" ? "Validating and applying" : "Rejecting", () => CIT.api("disruptions/decide.php", { id, action, comment: document.getElementById("cmt").value.trim() || null }));
        CIT.toast(action === "approve" ? "Applied. The repaired timetable is now active." : "Rejected");
        await list(); show(id);
      } catch (e) { document.getElementById("dout").innerHTML = CIT.errorHtml(e); }
    };
    document.getElementById("approve")?.addEventListener("click", e => decide(e.currentTarget, "approve"));
    document.getElementById("reject")?.addEventListener("click", e => decide(e.currentTarget, "reject"));
  }

  await list();
  if (qs.get("id")) show(+qs.get("id"));
})();
