// Assistant: live answers from the database, policy answers from RAG with sources. Reads only.  [M4]
(async () => {
  const user = await CIT.boot("assistant");
  if (!user) return;
  const main = document.getElementById("main");
  const history = [];
  const prompts = ["Is LH-1 free on Tuesday at 10 am?", "CSE-2 ka timetable batao", "Why is BR-07 in AUD?", "Which rooms are free on Friday evening?",
    "What is the booking priority order?", "seminar hall kal shaam 6 baje, 2 ghante, 100 log, mic chahiye", "status of my bookings"];
  main.innerHTML = `<div class="page-head"><div><h1>Assistant</h1>
      <div class="sub">Ask in English or Hinglish. Room and timetable answers come from the live data; rules come from CIT's documents, with sources. It never books or changes anything.</div></div>
      <div class="actions"><button class="btn ghost small" id="clearChat" title="Reset conversation">${icon("x")} Clear chat</button></div></div>
    <div class="panel chat"><div class="chat-log" id="log"><div class="suggest">${prompts.map(p => `<button>${esc(p)}</button>`).join("")}</div></div>
      <form class="chat-input" id="f"><textarea id="m" rows="1" placeholder="Ask about rooms, timetables, bookings or the rules" aria-label="Message"></textarea>
        <button class="btn primary" id="send" aria-label="Send">${icon("send")}</button></form></div>`;
  const log = document.getElementById("log");
  log.querySelectorAll(".suggest button").forEach(b => (b.onclick = () => ask(b.textContent)));

  // Clean legacy shared storage key so older messages do not leak between roles
  try { localStorage.removeItem("CIT_CHAT_MESSAGES"); } catch (e) {}

  const userChatKey = `CIT_CHAT_MESSAGES_${user.username || user.faculty_id || user.role || 'anon'}`;

  // Load persistent chat history from localStorage scoped strictly to this account
  const storedChat = JSON.parse(localStorage.getItem(userChatKey) || "[]");
  if (storedChat.length) {
    storedChat.forEach(msg => {
      if (msg.role === "user") {
        add(esc(msg.content), "me");
      } else {
        add(`<div class="who">Assistant · ${words(msg.intent || "assistant")}</div>${formatMarkdown(msg.content)}`, "bot");
      }
      history.push({ role: msg.role, content: msg.content });
    });
  }

  document.getElementById("clearChat").onclick = () => {
    if (confirm("Clear this chat conversation?")) {
      localStorage.removeItem(userChatKey);
      history.length = 0;
      log.querySelectorAll(".msg").forEach(m => m.remove());
      CIT.toast("Chat conversation cleared");
    }
  };
  const m = document.getElementById("m");
  m.onkeydown = e => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); document.getElementById("f").requestSubmit(); } };
  document.getElementById("f").onsubmit = e => { e.preventDefault(); const t = m.value.trim(); if (t) { m.value = ""; ask(t); } };

  function formatMarkdown(text) {
    if (!text) return "";
    let s = esc(text);
    // Bold: **text**
    s = s.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
    // Italic: *text* or _text_
    s = s.replace(/(^|[^*])\*(?!\s)(.*?)(?!\s)\*(?=[^*]|$)/g, "$1<em>$2</em>");
    // Headers: ### Header, ## Header, # Header
    s = s.replace(/^### (.*$)/gim, '<h4 style="margin:8px 0 4px 0;font-size:14px;color:var(--text,#1e293b)">$1</h4>');
    s = s.replace(/^## (.*$)/gim, '<h3 style="margin:10px 0 4px 0;font-size:15px;color:var(--text,#1e293b)">$1</h3>');
    s = s.replace(/^# (.*$)/gim, '<h2 style="margin:12px 0 6px 0;font-size:16px;color:var(--text,#1e293b)">$1</h2>');
    // Bullet points: * Item or - Item
    s = s.replace(/^\s*[\*\-]\s+(.*$)/gim, '<li style="margin-left:16px;margin-bottom:3px">$1</li>');
    // Numbered lists: 1. Item
    s = s.replace(/^\s*(\d+)\.\s+(.*$)/gim, '<li style="margin-left:16px;margin-bottom:3px" value="$1">$2</li>');
    // Line breaks
    s = s.replace(/\n\n+/g, '<br><br>').replace(/\n/g, '<br>');
    return s;
  }

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
      history.push({ role: "user", content: text }, { role: "assistant", content: r.reply });
      
      // Save to local storage for persistence across tabs and refreshes scoped to user
      const chatSave = JSON.parse(localStorage.getItem(userChatKey) || "[]");
      chatSave.push({ role: "user", content: text });
      chatSave.push({ role: "assistant", content: r.reply, intent: r.intent });
      localStorage.setItem(userChatKey, JSON.stringify(chatSave));

      const sources = r.sources.map(s => `<span class="chip" title="${esc(s.section || "")}">S${s.n} ${esc(s.source)}${s.section ? " · " + esc(s.section) : ""}</span>`).join("");
      let action = "";
      if (r.action?.type === "open_booking_form") action = `<button class="btn small primary" data-act="form">Open the form</button>`;
      if (r.action?.type === "open_change_form") action = `<a class="btn small" href="reschedule.html?text=${encodeURIComponent(r.action.text)}">Go to Reschedule</a>`;
      pending.innerHTML = `<div class="who">Assistant · ${words(r.intent)}${r.used_llm ? "" : " · answered from the data directly"}</div>${formatMarkdown(r.reply)}
        <div class="meta">${sources}${r.link ? `<a class="btn small" href="${esc(r.link)}">Open</a>` : ""}${action}</div>`;
      pending.querySelector("[data-act=form]")?.addEventListener("click", () => { sessionStorage.setItem("cit_parsed", JSON.stringify(r.action.parsed)); location.href = "request.html"; });
    } catch (e) { pending.innerHTML = `<div class="who">Assistant</div>` + CIT.errorHtml(e); }
    log.scrollTop = log.scrollHeight;
  }
})();
