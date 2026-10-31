// Admin: versions, exports, settings, knowledge index, dataset import, demo reset, accounts, audit.  [M4]
(async () => {
  const user = await CIT.boot("admin");
  if (!user) return;
  const main = document.getElementById("main");
  if (user.role !== "admin") { main.innerHTML = `<div class="panel empty">Only Admins can open this page.</div>`; return; }
  const [versions, s, audit] = await Promise.all([CIT.api("schedule/versions.php"), CIT.api("admin/settings.php"), CIT.api("admin/audit.php")]);
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
        <div class="actions"><a class="btn primary" href="/api/schedule/export.php">${icon("download")} schedule.json</a>
          ${lastRepair ? `<a class="btn" href="/api/schedule/export.php?kind=after&version=${lastRepair.id}">${icon("download")} schedule_after.json (version ${lastRepair.id})</a>` : `<span class="muted">schedule_after.json appears after a repair is approved</span>`}
          <button class="btn ghost" id="saveExports">Also save on the server</button></div></div>
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
