// public/assets/js/pages/dashboard.js — CIT Scheduler: Overview Dashboard
(async () => {
  const user = await CIT.boot("dashboard");
  if (!user) return;
  const main = document.getElementById("main");
  const isAdmin = user.role === "admin";
  let s;
  try {
    s = await CIT.api("dashboard/summary.php");
  } catch (e) {
    main.innerHTML = CIT.errorHtml(e);
    return;
  }

  const v = s.validation || {};
  const c = s.decision_counts || {};
  const sp = v.soft_penalties || {};
  const softNote = Object.entries(sp).filter(([, n]) => n).map(([k, n]) => `${k} ${n}`).join(" · ") || "none";
  const toReview = s.pending ? s.pending.length : 0;
  const hardCount = v.hard_violation_count ?? 0;
  const isClean = hardCount === 0;

  // 1. Page Header with real version metadata & action buttons
  const headHtml = `
    <div class="page-head">
      <div>
        <h1 style="font-size:28px;font-weight:600;letter-spacing:-0.02em;color:var(--c-deep-charcoal);margin:0 0 4px">Overview</h1>
        <div class="sub">
          ${s.version ? `Timetable version <span class="mono" style="font-weight:600;color:var(--c-deep-charcoal)">${esc(s.version.id)}</span> · ${s.version.kind === "repair" ? "repaired after change" : "generated"} ${when(s.version.activated_at)}${s.version.solve_seconds ? ` in ${Math.round(s.version.solve_seconds)} s` : ""}` : "No timetable generated yet"}
          ${s.dataset ? ` · dataset <span class="mono">${esc(s.dataset.dataset_id)}</span>${s.dataset.created_reason !== "import" ? " (with applied changes)" : ""}` : ""}
        </div>
      </div>
      ${isAdmin ? `
        <div class="actions">
          ${s.version ? `<button class="btn small" id="improve" title="Spend another ~35s lowering soft penalties">${icon("refresh")} Improve</button>` : ""}
          <button class="btn small ${s.version ? "" : "primary"}" id="gen">${icon("grid")} ${s.version ? "Regenerate" : "Generate timetable"}</button>
          ${s.version ? `<a class="btn small primary" href="/exports/schedule.json" download="schedule.json">${icon("download")} schedule.json</a>` : ""}
        </div>` : ""}
    </div>`;

  if (!s.version) {
    main.innerHTML = headHtml + `
      <div class="panel empty" style="border-radius:24px;background:#FFF;padding:48px 24px">
        <h2 style="margin-bottom:8px">No timetable yet</h2>
        <p class="sub">${isAdmin ? "Generate builds the regular week, decides every Spectra request and plans the midsems. It takes about 35 seconds." : "An Admin needs to generate the timetable first."}</p>
        ${isAdmin ? `<button class="btn primary" id="gen-empty" style="margin-top:16px">${icon("grid")} Generate timetable</button>` : ""}
      </div>`;
    document.getElementById("gen-empty")?.addEventListener("click", () => document.getElementById("gen")?.click());
    bindGenerate();
    return;
  }

  // 2. Real Metric Cards (styled with Crextio design language & palette)
  const cardsHtml = `
    <div class="stat-grid">
      <!-- Card 1: Hard Violations (Charcoal Panel) -->
      <div class="stat-card charcoal">
        <div class="stat-label">Hard Violations</div>
        <div class="stat-figure">${isClean ? "0" : hardCount}</div>
        <div class="stat-note">${isClean ? "✓ Official validator: 0 clashes" : `<span style="color:var(--bad)">${hardCount} constraint violations</span>`}</div>
      </div>

      <!-- Card 2: Soft Penalty -->
      <div class="stat-card">
        <div class="stat-label">Soft Penalty</div>
        <div class="stat-figure">${v.soft_total ?? "–"}</div>
        <div class="stat-note mono" style="font-size:11.5px;color:var(--c-secondary-text)">${esc(softNote)}</div>
      </div>

      <!-- Card 3: Spectra Requests -->
      <div class="stat-card">
        <div class="stat-label">Spectra Requests</div>
        <div class="stat-figure">
          ${c.approved || 0}
          <span style="font-size:14px;font-weight:400;color:var(--c-secondary-text)">approved</span>
        </div>
        <div class="stat-note">${plural(c.alternative_offered || 0, "alternative")} · ${c.rejected || 0} rejected · score <span class="mono">${v.booking_score ?? "–"}</span></div>
      </div>

      <!-- Card 4: Midsem Plan -->
      <div class="stat-card">
        <div class="stat-label">Midsem Plan</div>
        <div class="stat-figure">${v.exam_soft_penalty ?? "0"}</div>
        <div class="stat-note">Same-day exam penalty</div>
      </div>

      <!-- Card 5: Waiting for Review (Admin) or User Requests -->
      ${isAdmin ? `
        <div class="stat-card">
          <div class="stat-label">Waiting for Review</div>
          <div class="stat-figure" style="${toReview ? 'color:var(--warn)' : ''}">${toReview}</div>
          <div class="stat-note">${plural(s.pending.filter(p => !p.item_type?.includes("repair") && !p.item_type?.includes("leave")).length, "booking")} · ${plural(s.pending.filter(p => p.item_type?.includes("repair") || p.item_type?.includes("leave")).length, "repair/leave")}</div>
        </div>` : `
        <div class="stat-card">
          <div class="stat-label">Your Bookings</div>
          <div class="stat-figure">${s.my_requests.length}</div>
          <div class="stat-note">${s.my_requests.filter(r => r.status === "approved").length} approved</div>
        </div>`}
    </div>`;

  // 3. Real Attention List / Pending Approvals
  const pendingRows = (s.pending || []).map(p => {
    const isBooking = p.item_type !== "reschedule_proposal" && p.item_type !== "campus_repair";
    const href = isBooking ? `admin.html#approvals-list` : p.item_id ? `reschedule.html?id=${p.item_id}` : "reschedule.html";
    const catLabel = p.category_label || (isBooking ? "Room Booking" : "Campus Repair");
    const tagCls = p.tag_class || (isBooking ? "info" : "warn");
    return `
      <tr class="clickable" onclick="location.href='${href}'">
        <td><span class="tag ${tagCls}">• ${esc(catLabel)}</span></td>
        <td class="mono"><b>${esc(p.ref || p.item_id)}</b></td>
        <td>${esc(p.summary)}</td>
        <td style="text-align:right"><a class="btn small" href="${href}">Review</a></td>
      </tr>`;
  }).join("");

  const mineRows = (s.my_requests || []).map(r => `
    <tr class="clickable" onclick="location.href='bookings.html?id=${encodeURIComponent(r.id)}'">
      <td class="mono"><b>${esc(r.id)}</b></td>
      <td>${esc(r.title)}</td>
      <td>${r.state === "cancelled" ? statusTag("cancelled") : r.status ? statusTag(r.status) : statusTag("pending")}</td>
      <td class="mono" style="font-size:12px">${r.room_id ? esc(r.room_id) + " " + slot(r.day, r.start_period, r.end_period) : "—"}</td>
    </tr>`).join("");

  const attentionHtml = isAdmin
    ? `<div class="panel">
        <div class="panel-head">
          <h2>Waiting for you</h2>
          <span class="tag ${toReview ? 'accent' : 'muted'}">${toReview} pending</span>
        </div>
        ${toReview ? `
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Type</th><th>Ref</th><th>Summary</th><th style="text-align:right">Action</th></tr>
              </thead>
              <tbody>${pendingRows}</tbody>
            </table>
          </div>` : `<p class="sub">Nothing waiting for review right now. All requests and proposals have been decided.</p>`}
      </div>`
    : `<div class="panel">
        <div class="panel-head">
          <h2>Your requests</h2>
          <a href="request.html" class="btn small">${icon("plus")} New request</a>
        </div>
        ${(s.my_requests || []).length ? `
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>ID</th><th>Title</th><th>Status</th><th>Room & Time</th></tr>
              </thead>
              <tbody>${mineRows}</tbody>
            </table>
          </div>` : `<p class="sub">You have not submitted any room requests yet.</p>`}
      </div>`;

  // 4. Conflicts & Violations
  const violationsHtml = v.violations && v.violations.length
    ? `<div class="callout bad">${v.hard_violation_count} hard violations detected</div>
       <div class="table-wrap">
         <table>
           <thead><tr><th>Rule</th><th>Detail</th></tr></thead>
           <tbody>${v.violations.map(x => `<tr><td class="mono">${esc(x.rule)}</td><td>${esc(x.detail)}</td></tr>`).join("")}</tbody>
         </table>
       </div>`
    : `<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--c-warm-cream);border-radius:14px;border:1px solid var(--c-light-border)">
         <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--c-butter-yellow);color:var(--c-deep-charcoal);font-weight:700;font-size:12px">✓</span>
         <div class="sub" style="margin:0;font-size:13px;color:var(--c-deep-charcoal)">No clashes. The official validator checked this timetable ${v.created_at ? "at " + when(v.created_at, true) : ""}.</div>
       </div>`;

  const inv = v.bookings?.priority_inversions || [];
  const invHtml = inv.length
    ? `<div class="callout warn" style="margin-top:12px">
         <b>Priority inversions</b> (lower-priority request holds a room a rejected one could use):
         <ul style="margin:6px 0 0;padding-left:18px">${inv.map(i => `<li>${esc(i)}</li>`).join("")}</ul>
       </div>`
    : "";

  // 5. Room Closures
  const closuresRows = (s.closures || []).map(cl => `
    <tr>
      <td class="mono"><b>${esc(cl.room_id)}</b></td>
      <td class="mono" style="font-size:12px">${slot(cl.day, cl.periods[0], cl.periods[cl.periods.length - 1])}</td>
      <td class="sub">${esc(cl.reason)}</td>
    </tr>`).join("");

  const closuresHtml = s.closures && s.closures.length
    ? `<div class="panel">
         <div class="panel-head"><h2>Room closures</h2><span class="tag muted">${s.closures.length} closed</span></div>
         <div class="table-wrap">
           <table>
             <thead><tr><th>Room</th><th>Slot</th><th>Reason</th></tr></thead>
             <tbody>${closuresRows}</tbody>
           </table>
         </div>
       </div>`
    : "";

  // 6. Real Room Utilization (teaching periods a week)
  const maxU = Math.max(...(s.utilization || []).map(u => +u.utilization_pct), 1);
  const utilRows = (s.utilization || []).map((u, i) => `
    <tr>
      <td class="mono"><b>${esc(u.room_id)}</b></td>
      <td class="sub" style="font-size:12px">${words(u.type)}</td>
      <td style="width:38%">
        <div class="bar ${i === 0 ? "lead" : ""}">
          <i style="width:${(100 * u.utilization_pct / maxU).toFixed(0)}%"></i>
        </div>
      </td>
      <td class="num mono" style="font-size:12px">${u.used_periods}/${u.teaching_periods}</td>
    </tr>`).join("");

  const roomUseHtml = `
    <div class="panel">
      <div class="panel-head">
        <h2>Room use</h2>
        <span class="muted" style="font-size:12px">teaching periods a week</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Room</th><th>Type</th><th>Load</th><th class="num">Used</th></tr>
          </thead>
          <tbody>${utilRows}</tbody>
        </table>
      </div>
    </div>`;

  // Assemble the real, clean dashboard
  main.innerHTML = `
    ${headHtml}
    ${cardsHtml}
    <div class="cols">
      <div class="stack">
        ${attentionHtml}
        <div class="panel">
          <div class="panel-head"><h2>Conflicts & Validation</h2></div>
          ${violationsHtml}
          ${invHtml}
        </div>
        ${closuresHtml}
      </div>
      <div>
        ${roomUseHtml}
      </div>
    </div>`;

  bindGenerate();

  function bindGenerate() {
    const run = async (btn, improve) => {
      try {
        const r = await CIT.busy(btn, improve ? "Improving, about 35 s" : "Generating, about 35 s", () => CIT.api("schedule/generate.php", { improve }));
        CIT.toast(r.activated ? `Version ${r.version_id}: ${r.hard_violations} hard violations, soft penalty ${r.soft_total}` : `Version ${r.version_id} has ${r.hard_violations} hard violations and was not activated`, !r.activated);
        setTimeout(() => location.reload(), 900);
      } catch (e) {
        main.insertAdjacentHTML("afterbegin", CIT.errorHtml(e));
      }
    };
    document.getElementById("gen")?.addEventListener("click", e => run(e.currentTarget, false));
    document.getElementById("improve")?.addEventListener("click", e => run(e.currentTarget, true));
  }
})();
