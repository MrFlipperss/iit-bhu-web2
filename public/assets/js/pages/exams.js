// Midsem plan: MON-SAT x AM/PM, each exam with its rooms and seats at half capacity.  [M2]
(async () => {
  const user = await CIT.boot("exams");
  if (!user) return;
  const meta = await CIT.loadMeta();
  const main = document.getElementById("main");
  const group = qs.get("group") || "";
  main.innerHTML = `<div class="page-head"><div><h1>Midsem exams</h1><div class="sub" id="sum"></div></div>
    <div class="actions"><select id="g" style="width:auto;min-width:220px"><option value="">All groups</option>
      ${meta.groups.map(g => `<option value="${esc(g.id)}" ${g.id === group ? "selected" : ""}>${esc(g.id)} · ${esc(g.name)}</option>`).join("")}</select></div></div><div id="out"></div>`;
  document.getElementById("g").onchange = e => (location.search = e.target.value ? "?group=" + encodeURIComponent(e.target.value) : "");
  let d;
  try { d = await CIT.api("exams/get.php" + (group ? "?group=" + encodeURIComponent(group) : "")); }
  catch (e) { document.getElementById("out").innerHTML = e.code === "no_timetable" ? `<div class="panel empty">${esc(e.message)}</div>` : CIT.errorHtml(e); return; }
  const slots = meta.exam_slots;
  document.getElementById("sum").innerHTML = `${d.exams.length} exams · ${slots.map(s => `${s.code} ${s.start}–${s.end}`).join(", ")} · rooms seat half their capacity`;
  const by = {};
  d.exams.forEach(x => ((by[x.day + x.session] ||= []).push(x)));
  let html = `<div class="table-wrap"><div class="planner" style="--days:${meta.exam_days.length}"><div class="h"></div>${meta.exam_days.map(dd => `<div class="h">${dd}</div>`).join("")}`;
  slots.forEach(s => {
    html += `<div class="t"><b>${s.code}</b>${s.start}</div>`;
    meta.exam_days.forEach(day => {
      html += `<div class="c">${(by[day + s.code] || []).map(x => `<div class="cell lecture" style="border-color:var(--k-exam-b);background:var(--k-exam);cursor:default">
        <b>${esc(x.exam_id.replace("EX-", ""))}</b><span class="m">${esc(x.groups.join(", "))}</span>
        <span class="m">${x.size} students · ${x.seats} seats</span><div class="chips" style="margin-top:4px">${x.rooms.map(r => `<span class="chip">${esc(r)}</span>`).join("")}</div></div>`).join("")}</div>`;
    });
  });
  html += `</div></div>`;
  document.getElementById("out").innerHTML = d.exams.length ? html : `<div class="panel empty">No exams for this group.</div>`;
})();
