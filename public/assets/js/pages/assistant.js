// Assistant: live answers from the database, policy answers from RAG with sources. Reads only.  [M4]
(async () => {
  const user = await CIT.boot("assistant");
  if (!user) return;
  const main = document.getElementById("main");
  const history = [];
  const prompts = ["Is LH-1 free on Tuesday at 10 am?", "CSE-2 ka timetable batao", "Why is BR-07 in AUD?", "Which rooms are free on Friday evening?",
    "What is the booking priority order?", "seminar hall kal shaam 6 baje, 2 ghante, 100 log, mic chahiye", "status of my bookings"];
  main.innerHTML = `<div class="page-head"><div><h1>Assistant</h1>
      <div class="sub">Ask in English or Hinglish. Room and timetable answers come from the live data; rules come from CIT's documents, with sources. It never books or changes anything.</div></div></div>
    <div class="panel chat"><div class="chat-log" id="log"><div class="suggest">${prompts.map(p => `<button>${esc(p)}</button>`).join("")}</div></div>
      <form class="chat-input" id="f"><textarea id="m" rows="1" placeholder="Ask about rooms, timetables, bookings or the rules" aria-label="Message"></textarea>
        <button class="btn primary" id="send" aria-label="Send">${icon("send")}</button></form></div>`;
  const log = document.getElementById("log");
  log.querySelectorAll(".suggest button").forEach(b => (b.onclick = () => ask(b.textContent)));
  const m = document.getElementById("m");
  m.onkeydown = e => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); document.getElementById("f").requestSubmit(); } };
  document.getElementById("f").onsubmit = e => { e.preventDefault(); const t = m.value.trim(); if (t) { m.value = ""; ask(t); } };

  function add(html, cls) {
    const d = document.createElement("div");
    d.className = "msg " + cls;
    d.innerHTML = html;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  }
  async function ask(text) {
    add(esc(text), "me");
    const pending = add(`<div class="who">Assistant</div><span class="spin"></span>`, "bot");
    try {
      const r = await CIT.api("ai/chat.php", { message: text, history });
      const reply = typeof r?.reply === "string" && r.reply.trim()
        ? r.reply
        : "I couldn't prepare an answer right now. Please try again.";
      history.push({ role: "user", content: text }, { role: "assistant", content: reply });
      const sources = (Array.isArray(r?.sources) ? r.sources : [])
        .filter(s => s && typeof s === "object")
        .map(s => `<span class="chip" title="${esc(s.section || "")}">S${esc(s.n ?? "")} ${esc(s.source || "Source")}${s.section ? " · " + esc(s.section) : ""}</span>`).join("");
      let action = "";
      if (r.action?.type === "open_booking_form") action = `<button class="btn small primary" data-act="form">Open the form</button>`;
      if (r.action?.type === "open_change_form") action = `<a class="btn small" href="reschedule.html?text=${encodeURIComponent(r.action.text)}">Go to Reschedule</a>`;
      pending.innerHTML = `<div class="who">Assistant${r?.intent ? " · " + esc(words(r.intent)) : ""}${r?.used_llm ? "" : " · answered from the data directly"}</div>${esc(reply)}
        <div class="meta">${sources}${r.link ? `<a class="btn small" href="${esc(r.link)}">Open</a>` : ""}${action}</div>`;
      pending.querySelector("[data-act=form]")?.addEventListener("click", () => { sessionStorage.setItem("cit_parsed", JSON.stringify(r.action.parsed)); location.href = "request.html"; });
    } catch (e) { pending.innerHTML = `<div class="who">Assistant</div>` + CIT.errorHtml(e); }
    log.scrollTop = log.scrollHeight;
  }
})();
