// Admin: versions, exports, settings, knowledge index, dataset import, demo reset, accounts, audit.  [M4]
(async () => {
  const user = await CIT.boot("admin");
  if (!user) return;
  const main = document.getElementById("main");
  if (user.role !== "admin") { main.innerHTML = `<div class="panel empty">Only Admins can open this page.</div>`; return; }
  let versions, s, audit;
  try { [versions, s, audit] = await Promise.all([CIT.api("schedule/versions.php"), CIT.api("admin/settings.php"), CIT.api("admin/audit.php")]); }
  catch (e) { main.innerHTML = CIT.errorHtml(e); return; }
  versions = Array.isArray(versions) ? versions : [];
  s = s || { settings: {}, llm: { configured: false, provider: '', model: '' }, knowledge_chunks: 0, knowledge_embedded: 0, users: [] };
  audit = audit || { audit: [] };
  const lastRepair = versions.find(v => v.kind === "repair" && v.status === "active") || versions.find(v => v.kind === "repair" && ["superseded", "active"].includes(v.status));
  main.innerHTML = `<div class="page-head"><div><h1>Admin</h1><div class="sub">Data, exports and settings. Every action here is in the audit log.</div></div></div>
    <div class="cols"><div class="stack">
      <div class="panel"><div class="panel-head"><h2>Timetable versions</h2><a href="dashboard.html">Generate on the Overview</a></div><div class="table-wrap"><table>
        <tr><th>#</th><th>Kind</th><th>Status</th><th class="num">Hard</th><th class="num">Soft</th><th class="num">Bookings</th><th>Made</th><th></th></tr>
        ${versions.map(v => `<tr><td class="mono">${v.id}</td><td>${esc(v.kind)}${v.parent_id ? ` <span class="muted">from ${v.parent_id}</span>` : ""}</td><td>${statusTag(v.status)}</td>
          <td class="num mono">${v.hard_violation_count ?? "–"}</td><td class="num mono">${v.soft_total ?? "–"}</td><td class="num mono">${v.booking_score ?? "–"}</td>
          <td class="muted">${when(v.created_at)} ${esc(v.created_by || "")}</td>
          <td>${["validated", "superseded"].includes(v.status) && +v.hard_violation_count === 0 ? `<button class="btn small" data-activate="${v.id}">Make active</button>` : ""}</td></tr>`).join("")}</table></div></div>
      <div class="panel"><h2>Exports</h2><p class="sub">Built by the app from the stored timetable, in the exact starter-pack format. Commit these two files to <span class="mono">exports/</span>.</p>
        <div class="actions"><a class="btn primary" href="/exports/schedule.json" download="schedule.json">${icon("download")} schedule.json</a>
          ${lastRepair ? `<a class="btn" href="/exports/schedule_after.json" download="schedule_after.json">${icon("download")} schedule_after.json</a>` : `<span class="muted">schedule_after.json appears after a repair is approved</span>`}
          <button class="btn ghost" id="saveExports">Also save on the server</button></div></div>
      <div class="panel">
        <div class="panel-head">
          <h2>Requests Pending Dean's Approval</h2>
          <button type="button" class="btn small ghost" id="refresh-approvals">${icon("refresh")} Live Sync</button>
        </div>
        <p class="sub">All Spectra room bookings and faculty timetable reschedules submitted across any device appear here in real-time for Dean approval.</p>
        <div id="approvals-list"><div class="loading"><span class="spin"></span> Loading requests</div></div>
      </div>
      <div class="panel"><h2>Manual Admin Override</h2>
        <p class="sub">Direct manual control to enforce room assignments, session reschedules, or cancellations with immediate audit logging.</p>
        <form id="override-form">
          <div class="field">
            <label for="override-scope">Override Scope</label>
            <select id="override-scope">
              <option value="whole_semester">Option 1: Whole Semester</option>
              <option value="window">Option 2: Time Window (Week / Day / Custom)</option>
            </select>
          </div>
          <div class="field" id="window-select-field" style="display:none">
            <label for="override-window">Window Duration</label>
            <select id="override-window">
              <option value="one_week">One Week (Spectra Fest Week 7)</option>
              <option value="one_day">One Day (Single Date/Weekday)</option>
              <option value="specific_periods">Specific Periods (e.g. Morning P1-P4)</option>
            </select>
          </div>
          <div class="cols" style="gap:10px;margin-bottom:12px">
            <div class="field" style="flex:1">
              <label for="override-entity">Target Entity</label>
              <select id="override-entity">
                <option value="session">Course Session (e.g. MA101, CS201)</option>
                <option value="room">Room / Hall (e.g. AUD, LH-1, CS-LAB-1)</option>
                <option value="faculty">Faculty Member (e.g. F12, F19)</option>
                <option value="booking">Spectra Booking Request (e.g. BR-01)</option>
              </select>
            </div>
            <div class="field" style="flex:1">
              <label for="override-id">Entity ID</label>
              <input type="text" id="override-id" placeholder="e.g. LH-1 or CS201" required>
            </div>
          </div>
          <div class="field">
            <label for="override-action">Override Action</label>
            <select id="override-action">
              <option value="lock_room">Lock / Reserve Room Exclusively</option>
              <option value="force_move">Force Reassign to Alternate Room</option>
              <option value="mark_unavailable">Mark Forcefully Unavailable</option>
              <option value="approve_bypass">Approve Booking with Priority Bypass</option>
            </select>
          </div>
          <div class="field">
            <label for="override-reason">Reason / Justification for Audit</label>
            <input type="text" id="override-reason" placeholder="e.g. Dean order / Emergency lab maintenance" required>
          </div>
          <div class="actions">
            <button type="submit" class="btn primary">Apply Manual Override</button>
          </div>
        </form>
        <div id="override-list" style="margin-top:16px"></div>
      </div>
      <div class="panel"><h2>Activity</h2><div class="table-wrap" style="max-height:360px;overflow-y:auto"><table>
        ${audit.audit.map(a => `<tr><td class="muted" style="white-space:nowrap">${when(a.created_at)}</td><td class="mono">${esc(a.action)}</td><td>${esc(a.display_name || "")}</td><td class="mono muted">${esc(a.entity_id || "")}</td></tr>`).join("")}</table></div></div>
    </div><div class="stack">
      <form class="panel" id="settings"><h2>Settings</h2>
        <div class="field"><label for="team">Team name in exports</label><input type="text" id="team" value="${esc(s.settings.team_name || "")}"></div>
        <div class="field"><label for="ref">"Today" in Spectra week <span class="muted">("kal" means the day after)</span></label>
          <select id="ref">${["MON", "TUE", "WED", "THU", "FRI"].map(d => `<option ${d === s.settings.reference_weekday ? "selected" : ""}>${d}</option>`).join("")}</select></div>
        <label class="check" style="margin-bottom:12px"><input type="checkbox" id="ai" ${s.settings.ai_enabled !== false ? "checked" : ""}> Use the AI provider when configured</label>
        <p class="muted" style="font-size:12px">AI: ${s.llm.configured ? `${esc(s.llm.provider)} · ${esc(s.llm.model)}` : "not configured, so the built-in parser and keyword search are used"}.
          Knowledge index: ${s.knowledge_chunks} passages, ${s.knowledge_embedded} with embeddings.</p>
        <div class="actions"><button class="btn primary">Save</button><button type="button" class="btn" id="rebuild">Rebuild knowledge index</button></div></form>
      <div class="panel"><h2>Dataset</h2><p class="sub">Importing replaces the dataset and clears every timetable, decision and proposal.</p>
        <label class="btn" style="margin:0">Import a dataset file<input type="file" id="ds" accept=".json" hidden></label><div id="dsout" style="margin-top:8px"></div></div>
      <div class="panel"><h2>Reset demo data</h2><p class="sub">Back to the starter pack with the demo accounts, then Generate on the Overview.</p>
        <button class="btn danger" id="reset">Reset everything</button></div>
      <div class="panel"><h2>Demo accounts</h2><table>${s.users.map(u => `<tr><td class="mono" style="font-size:12px">${esc(u.username)}</td><td>${words(u.role)}</td></tr>`).join("")}</table>
        <p class="muted" style="font-size:12px;margin-top:8px">All use DEMO_PASSWORD from the server settings.</p></div>
    </div></div>`;

  async function loadPendingApprovals() {
    const el = document.getElementById("approvals-list");
    if (!el) return;
    try {
      const res = await CIT.api("admin/pending_approvals.php");
      const list = res.requests || [];
      if (!list.length) {
        el.innerHTML = `<p class="muted" style="font-size:13px">No pending user requests waiting for approval. (Synced with Cloud)</p>`;
        return;
      }
      el.innerHTML = `<table>
        <tr><th>ID</th><th>Type</th><th>Request</th><th>Requester</th><th>Details</th><th>Actions</th></tr>
        ${list.map(r => {
          const cat = (r.category || r.requested_category || "").toLowerCase();
          const title = (r.title || "").toLowerCase();
          const id = (r.id || "").toUpperCase();
          let typeLabel = "Room Booking";
          let tagClass = "info";
          if (cat.includes("faculty") || cat.includes("leave") || title.includes("faculty leave") || id.startsWith("CR-")) {
            typeLabel = "Faculty Leave";
            tagClass = "warn";
          } else if (cat.includes("repair") || cat.includes("closure") || cat.includes("disruption") || id.startsWith("PR-")) {
            typeLabel = "Campus Repair";
            tagClass = "danger";
          } else if (cat.includes("dept") || cat.includes("department") || title.includes("class") || title.includes("eee")) {
            typeLabel = "Department Event";
            tagClass = "accent";
          } else if (cat.includes("guest") || title.includes("guest")) {
            typeLabel = "Guest Lecture";
            tagClass = "info";
          } else if (cat.includes("club") || cat.includes("spectra") || title.includes("quiz")) {
            typeLabel = "Club / Fest Event";
            tagClass = "success";
          } else if (r.type === "reschedule") {
            typeLabel = "Faculty Reschedule";
            tagClass = "warn";
          }
          return `<tr>
          <td class="mono"><b>${esc(r.id)}</b></td>
          <td><span class="tag ${tagClass}">${esc(typeLabel)}</span></td>
          <td><div><b>${esc(r.title)}</b></div><span class="sub">${esc(r.category || r.requested_category || typeLabel)}</span></td>
          <td>${esc(r.requester)}</td>
          <td class="sub">
            <b>${esc(r.room_id || r.requested_room || 'Any Room')}</b> · ${esc(r.day || 'THU')} P${r.start_period || 10}-P${r.end_period || 12}
            ${r.details ? `<div class="muted" style="font-size:11.5px;margin-top:2px">${esc(r.details)}</div>` : ''}
          </td>
          <td>
            <div class="actions" style="margin:0">
              <button class="btn small primary" data-approve="${esc(r.id)}" data-day="${esc(r.day || '')}" data-room="${esc(r.room_id || '')}" data-start="${esc(r.start_period || '')}" data-end="${esc(r.end_period || '')}" data-prop="${esc(r.proposal_id || '')}">Approve</button>
              <button class="btn small danger" data-reject="${esc(r.id)}" data-prop="${esc(r.proposal_id || '')}">Reject</button>
            </div>
          </td>
        </tr>`;
        }).join("")}
      </table>`;

      el.querySelectorAll("[data-approve]").forEach(btn => {
        btn.onclick = async () => {
          await CIT.api("admin/decide_approval.php", {
            id: btn.dataset.approve,
            action: "approve",
            day: btn.dataset.day,
            room_id: btn.dataset.room,
            start_period: btn.dataset.start,
            end_period: btn.dataset.end,
            proposal_id: btn.dataset.prop
          });
          CIT.toast("Request approved and applied to live timetable across all devices!");
          loadPendingApprovals();
        };
      });

      el.querySelectorAll("[data-reject]").forEach(btn => {
        btn.onclick = async () => {
          await CIT.api("admin/decide_approval.php", {
            id: btn.dataset.reject,
            action: "reject",
            proposal_id: btn.dataset.prop
          });
          CIT.toast("Request rejected by Dean of Academics");
          loadPendingApprovals();
        };
      });
    } catch (e) {
      el.innerHTML = CIT.errorHtml(e);
    }
  }

  loadPendingApprovals();
  const refreshBtn = document.getElementById("refresh-approvals");
  if (refreshBtn) refreshBtn.onclick = () => { CIT.toast("Refreshing requests from Cloud..."); loadPendingApprovals(); };
  // Poll cloud pending approvals every 5 seconds so any request sent from another laptop appears live
  setInterval(loadPendingApprovals, 5000);

  const scopeEl = document.getElementById("override-scope");
  const winFieldEl = document.getElementById("window-select-field");
  scopeEl.onchange = () => {
    winFieldEl.style.display = scopeEl.value === "window" ? "block" : "none";
  };

  async function loadOverrides() {
    const listEl = document.getElementById("override-list");
    try {
      const res = await CIT.api("admin/get_overrides.php");
      const ovs = res.overrides || [];
      if (!ovs.length) {
        listEl.innerHTML = `<p class="muted" style="font-size:12px">No active manual overrides registered.</p>`;
        return;
      }
      listEl.innerHTML = `<table>
        <tr><th>Scope</th><th>Target</th><th>Action</th><th>Reason</th><th>By</th></tr>
        ${ovs.map(o => `<tr>
          <td><span class="chip">${esc(o.scope === 'whole_semester' ? 'Whole Semester' : o.window_type)}</span></td>
          <td class="mono">${esc(o.target_entity)}: ${esc(o.target_id)}</td>
          <td><b>${esc(o.action)}</b></td>
          <td class="sub">${esc(o.reason)}</td>
          <td class="muted">${esc(o.applied_by)}</td>
        </tr>`).join("")}
      </table>`;
    } catch (e) {
      listEl.innerHTML = CIT.errorHtml(e);
    }
  }

  document.getElementById("override-form").onsubmit = async ev => {
    ev.preventDefault();
    const payload = {
      scope: document.getElementById("override-scope").value,
      window_type: document.getElementById("override-window").value,
      target_entity: document.getElementById("override-entity").value,
      target_id: document.getElementById("override-id").value.trim(),
      action: document.getElementById("override-action").value,
      reason: document.getElementById("override-reason").value.trim()
    };
    try {
      await CIT.api("admin/override.php", payload);
      CIT.toast("Manual override successfully logged and applied");
      document.getElementById("override-id").value = "";
      document.getElementById("override-reason").value = "";
      loadOverrides();
    } catch (err) {
      CIT.toast(err.message, true);
    }
  };

  loadOverrides();

  main.querySelectorAll("[data-activate]").forEach(b => (b.onclick = async () => {
    try { await CIT.busy(b, "Activating", () => CIT.api("schedule/versions.php", { version_id: +b.dataset.activate })); location.reload(); } catch (e) { CIT.toast(e.message, true); }
  }));
  document.getElementById("saveExports").onclick = async ev => {
    await CIT.busy(ev.currentTarget, "Saving", async () => {
      await fetch("/api/schedule/export.php?save=1", { credentials: "same-origin" });
      if (lastRepair) await fetch(`/api/schedule/export.php?kind=after&version=${lastRepair.id}&save=1`, { credentials: "same-origin" });
    });
    CIT.toast("Saved to exports/ on the server");
  };
  document.getElementById("settings").onsubmit = async e => {
    e.preventDefault();
    try {
      await CIT.api("admin/settings.php", { team_name: document.getElementById("team").value.trim(), reference_weekday: document.getElementById("ref").value, ai_enabled: document.getElementById("ai").checked });
      CIT.toast("Saved");
    } catch (err) { CIT.toast(err.message, true); }
  };
  document.getElementById("rebuild").onclick = async ev => {
    try { const r = await CIT.busy(ev.currentTarget, "Indexing", () => CIT.api("admin/settings.php", { rebuild_knowledge: true })); CIT.toast(`${r.knowledge.chunks} passages indexed (${r.knowledge.mode})`); }
    catch (err) { CIT.toast(err.message, true); }
  };
  document.getElementById("ds").onchange = async e => {
    const f = e.target.files[0];
    if (!f || !confirm(`Import ${f.name}? This replaces the dataset and clears all timetables.`)) return;
    const out = document.getElementById("dsout");
    out.innerHTML = `<span class="spin"></span> Importing`;
    try { const r = await CIT.api("admin/import.php", { text: await f.text(), name: f.name }); out.innerHTML = `<div class="callout ok">Imported ${esc(r.dataset_id)}. Generate a timetable on the Overview.</div>`; }
    catch (err) { out.innerHTML = CIT.errorHtml(err); }
  };
  document.getElementById("reset").onclick = async ev => {
    if (!confirm("Reset all data to the starter pack? Timetables, bookings made in the app and proposals are deleted.")) return;
    try { await CIT.busy(ev.currentTarget, "Resetting", () => CIT.api("admin/reset.php", {})); CIT.toast("Reset done. Generate the timetable next."); setTimeout(() => (location.href = "dashboard.html"), 900); }
    catch (err) { CIT.toast(err.message, true); }
  };
})();
