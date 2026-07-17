<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Live Chat');
sales_header($me, 'Live Chat — Agent Console');
sales_nav('chat');
?>
<main class="p-2 space-y-2">
  <div id="chatKpis" class="kstrip"></div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-2" style="height:calc(100vh - 168px)">
    <!-- inbox -->
    <div class="card flex flex-col overflow-hidden">
      <div class="p-2 border-b flex items-center justify-between gap-2">
        <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap">Inbox</span>
        <span class="flex items-center gap-1.5">
          <div id="searchTextWrap" class="relative w-28">
            <i class="fa-solid fa-magnifying-glass absolute left-2 top-1/2 -translate-y-1/2 text-gray-300 text-[9px]"></i>
            <input id="chatSearch" oninput="onSearch()" placeholder="Search…"
              class="w-full border rounded-full pl-6 pr-2 py-1 text-[10px]">
          </div>
          <input id="chatSearchDate" type="date" onchange="onSearch()" class="hidden w-28 border rounded-full px-2 py-1 text-[10px]">
          <select id="searchField" onchange="onSearchFieldChange()" class="ctl text-[10px] py-0.5">
            <option value="name">Name</option>
            <option value="company">Company</option>
            <option value="phone">Number</option>
            <option value="date">Date</option>
          </select>
          <span id="waitBadge" class="hidden text-[10px] bg-red-500 text-white rounded-full px-2 py-0.5"></span>
          <button id="bellBtn" onclick="toggleBell()" class="text-gray-300 hover:text-indigo-500"
            title="Alert me on new chats"><i class="fa-regular fa-bell"></i></button>
        </span>
      </div>
      <div class="flex border-b text-[11px]">
        <button onclick="setFilter('active')" data-filter-tab="active"
          class="flex-1 px-2 py-1.5 font-medium text-indigo-600 border-b-2 border-indigo-600">Active <span
            id="cntActive" class="text-[9px] text-gray-400"></span></button>
        <button onclick="setFilter('missed')" data-filter-tab="missed"
          class="flex-1 px-2 py-1.5 font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700">Missed
          <span id="cntMissed" class="text-[9px] text-gray-400"></span></button>
        <button onclick="setFilter('closed')" data-filter-tab="closed"
          class="flex-1 px-2 py-1.5 font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700">Closed
          <span id="cntClosed" class="text-[9px] text-gray-400"></span></button>
      </div>
      <div id="inbox" class="flex-1 overflow-y-auto"></div>
      <div id="inboxPager" class="hidden border-t p-1.5 flex items-center justify-between text-[10px] text-gray-500">
        <button onclick="pagePrev()" class="ctl px-2 py-0.5"><i class="fa-solid fa-chevron-left"></i></button>
        <span id="inboxPagerLabel"></span>
        <button onclick="pageNext()" class="ctl px-2 py-0.5"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
    <!-- conversation -->
    <div class="card flex flex-col overflow-hidden lg:col-span-2">
      <div id="chatHead" class="p-2 border-b relative">
        <div class="text-sm text-gray-400 flex items-center justify-between">
          <span id="chatHeadLabel">Select a chat to start</span>
          <span class="flex items-center gap-2">
            <button id="transferBtn" onclick="toggleTransferMenu()" title="Transfer this chat"
              class="hidden text-gray-500 hover:text-indigo-600 text-xs whitespace-nowrap"><i
                class="fa-solid fa-right-left"></i> Transfer</button>
            <button id="summarizeBtn" onclick="summarizeChat()" title="AI summary of this chat"
              class="hidden text-indigo-500 hover:text-indigo-700 text-xs whitespace-nowrap"><i
                class="fa-solid fa-wand-magic-sparkles"></i> Summarize</button>
          </span>
        </div>
        <div id="chatHeadDetails" class="text-[11px] text-gray-500 mt-0.5 flex items-center gap-3 flex-wrap"></div>
        <div id="transferMenu"
          class="hidden absolute right-2 top-9 z-20 bg-white border rounded-lg shadow-lg w-56 max-h-72 overflow-y-auto text-xs">
        </div>
      </div>
      <div id="readOnlyBanner"
        class="hidden bg-amber-50 border-b border-amber-200 text-amber-800 text-[11px] px-3 py-1.5 flex items-center gap-2">
        <i class="fa-solid fa-eye"></i>
        <span id="readOnlyBannerText">View-only — this chat is assigned to another agent.</span>
      </div>
      <div id="thread" class="flex-1 overflow-y-auto p-3 space-y-2 bg-gray-50 text-sm"></div>
      <div id="composer" class="hidden border-t p-2 flex gap-2">
        <input type="file" id="fileIn" class="hidden" onchange="uploadFile()">
        <button onclick="document.getElementById('fileIn').click()" title="Attach a file"
          class="w-9 h-9 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200"><i
            class="fa-solid fa-paperclip text-xs"></i></button>
        <input id="msgIn" placeholder="Type a reply…" class="flex-1 border rounded-full px-3 py-1.5 text-sm"
          onkeydown="if(event.key==='Enter')send()">
        <button onclick="send()" class="w-9 h-9 rounded-full bg-indigo-600 text-white"><i
            class="fa-solid fa-paper-plane text-xs"></i></button>
        <button onclick="closeChat()" title="End chat"
          class="w-9 h-9 rounded-full bg-gray-200 text-gray-600 hover:bg-red-100 hover:text-red-600"><i
            class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="missedToolbar" class="hidden border-t p-2 bg-amber-50">
        <div id="missedReasonNote" class="hidden text-[11px] text-orange-700 px-1 pb-1.5">
          <i class="fa-solid fa-clock mr-1"></i>Customer left after waiting — no agent accepted the chat in time.
        </div>
        <div class="flex gap-2">
        <input id="remarkIn" placeholder="Add a remark (e.g. called the customer, no answer)…"
          class="flex-1 border rounded-full px-3 py-1.5 text-sm" onkeydown="if(event.key==='Enter')addRemark()">
        <button onclick="addRemark()"
          class="px-3 h-9 rounded-full bg-amber-600 text-white text-xs whitespace-nowrap">Add remark</button>
        <button onclick="closeMissed()" title="Mark this missed chat as closed"
          class="w-9 h-9 rounded-full bg-gray-200 text-gray-600 hover:bg-red-100 hover:text-red-600 flex-shrink-0"><i
            class="fa-solid fa-xmark"></i></button>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- modal -->
<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i
          class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
  const CURRENT_ROLE = <?= json_encode(sales_role()) ?>;
  const CURRENT_EMP_ID = <?= json_encode((int)($me['_employee_id'] ?? 0)) ?>;
  let cur = null, lastId = 0, pollT = null, filter = 'active', q = '', page = 1, pages = 1, searchT = null;
  const stColor = { visitor: 'bg-white border text-gray-700', agent: 'bg-indigo-600 text-white', bot: 'bg-gray-100 text-gray-500', system: 'bg-amber-50 text-amber-700 text-[11px] italic' };

  function setFilter(f) {
    filter = f; page = 1;
    document.querySelectorAll('[data-filter-tab]').forEach(b => {
      const on = b.dataset.filterTab === f;
      b.classList.toggle('text-indigo-600', on); b.classList.toggle('border-indigo-600', on);
      b.classList.toggle('text-gray-500', !on); b.classList.toggle('border-transparent', !on);
    });
    loadInbox();
  }
  function onSearchFieldChange() {
    const field = document.getElementById('searchField').value;
    const isDate = field === 'date';
    document.getElementById('searchTextWrap').classList.toggle('hidden', isDate);
    document.getElementById('chatSearchDate').classList.toggle('hidden', !isDate);
    document.getElementById('chatSearch').placeholder =
      field === 'company' ? 'Search by company…' : field === 'phone' ? 'Search by number…' : 'Search by name…';
    document.getElementById('chatSearch').value = '';
    document.getElementById('chatSearchDate').value = '';
    q = ''; page = 1; loadInbox();
  }
  function onSearch() {
    clearTimeout(searchT);
    searchT = setTimeout(() => { q = document.getElementById('chatSearch').value.trim(); page = 1; loadInbox(); }, 300);
  }
  function pagePrev() { if (page > 1) { page--; loadInbox(); } }
  function pageNext() { if (page < pages) { page++; loadInbox(); } }

  async function loadInbox() {
    const { data } = await apiGet('chat.php', { action: 'inbox', filter, q, page });
    notifyWaiting(data.waiting);
    const kc = data.counts || {};
    // kc.waiting comes from the always-computed counts (not the per-filter list), so it stays
    // accurate even while looking at the Missed/Closed tab instead of Active.
    const badge = document.getElementById('waitBadge');
    if (kc.waiting) { badge.textContent = kc.waiting + ' waiting'; badge.classList.remove('hidden'); }
    else badge.classList.add('hidden');
    updateAlarm(kc.waiting || 0);

    document.getElementById('chatKpis').innerHTML = [
      ['Active', kc.active, false], ['Unread', kc.unread, kc.unread > 0], ['Missed', kc.missed, kc.missed > 0], ['Closed (today)', kc.closed_today, false],
    ].map(([l, v, warn]) => `<div><div class="kv ${warn ? 'text-red-600' : ''}">${v || 0}</div><div class="kl">${l}</div></div>`).join('');
    document.getElementById('cntActive').textContent = `(${(kc.active || 0) + (kc.waiting || 0)})`;
    document.getElementById('cntMissed').textContent = `(${kc.missed || 0})`;
    document.getElementById('cntClosed').textContent = `(${kc.closed_total || 0})`;

    const item = (s, kind) => `
    <div data-id="${s.id}" data-name="${esc(s.visitor_name || '')}"
         onclick="${kind === 'waiting' ? `pickup(${s.id})` : `openChat(this.dataset.id, this.dataset.name)`}"
         class="px-2 py-2 border-b cursor-pointer hover:bg-gray-50 ${cur === s.id ? 'bg-indigo-50' : ''}">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold">${esc(s.visitor_name || 'Visitor')}</span>
        ${kind === 'waiting' ? '<span class="text-[9px] font-semibold bg-red-100 text-red-600 rounded px-1.5 py-0.5 animate-pulse">Currently free</span>'
        : kind === 'missed' ? '<span class="text-[9px] font-semibold bg-amber-100 text-amber-700 rounded px-1.5 py-0.5">Missed</span>'
          : kind === 'closed' ? '<span class="text-[9px] font-semibold bg-gray-100 text-gray-500 rounded px-1.5 py-0.5">Closed</span>'
            : '<span class="text-[9px] font-semibold bg-green-100 text-green-700 rounded px-1.5 py-0.5"><i class="fa-solid fa-headset text-[8px]"></i> You</span>'}
      </div>
      <div class="text-[11px] text-gray-500 truncate">${esc(s.last_msg || '')}</div>
      <div class="text-[10px] text-gray-400">${esc(s.visitor_phone || '')}</div>
    </div>`;

    const pager = document.getElementById('inboxPager');
    if (filter === 'active') {
      pages = data.active.pages;
      document.getElementById('inbox').innerHTML =
        (data.waiting.length ? '<div class="px-2 py-1 text-[10px] uppercase text-gray-400 bg-gray-50">Waiting</div>' + data.waiting.map(s => item(s, 'waiting')).join('') : '') +
        (data.active.rows.length ? '<div class="px-2 py-1 text-[10px] uppercase text-gray-400 bg-gray-50">My active</div>' + data.active.rows.map(s => item(s, 'active')).join('') : '') ||
        '<div class="p-3 text-center text-gray-400 text-xs">No chats yet</div>';
      pager.classList.toggle('hidden', data.active.total <= data.active.per);
      document.getElementById('inboxPagerLabel').textContent = `Page ${data.active.page} of ${data.active.pages} (${data.active.total} active)`;
    } else if (filter === 'missed') {
      pages = data.missed.pages;
      document.getElementById('inbox').innerHTML = data.missed.rows.map(s => item(s, 'missed')).join('')
        || '<div class="p-3 text-center text-gray-400 text-xs">No missed chats</div>';
      pager.classList.toggle('hidden', data.missed.total <= data.missed.per);
      document.getElementById('inboxPagerLabel').textContent = `Page ${data.missed.page} of ${data.missed.pages} (${data.missed.total} missed)`;
    } else if (filter === 'closed') {
      pages = data.closed.pages;
      document.getElementById('inbox').innerHTML = data.closed.rows.map(s => item(s, 'closed')).join('')
        || '<div class="p-3 text-center text-gray-400 text-xs">No closed chats yet</div>';
      pager.classList.toggle('hidden', data.closed.total <= data.closed.per);
      document.getElementById('inboxPagerLabel').textContent = `Page ${data.closed.page} of ${data.closed.pages} (${data.closed.total} closed)`;
    }
  }
  async function pickup(id) {
    const r = await apiSend('chat.php?action=pickup', { session_id: id });
    if (r.success) { toast('Chat picked up'); openChat(id); loadInbox(); }
    else toast(r.error || 'Already taken', false);
  }
  function attachmentHtml(name, url, mime) {
    const isImg = (mime || '').startsWith('image/');
    if (isImg) return `<a href="${url}" target="_blank"><img src="${url}" class="rounded max-w-[180px] max-h-[180px]"></a>`;
    return `<a href="${url}" target="_blank" class="underline"><i class="fa-solid fa-paperclip mr-1"></i>${esc(name)}</a>`;
  }
  /* Same WhatsApp-style per-message time + "Today/Yesterday/date" separator as sales-whatsapp.php,
     applied here so Live Chat matches (shared page — covers Sales and Service execs alike). */
  function fmtTime(ts) {
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T'));
    return isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  function dateKey(ts) {
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T'));
    return isNaN(d.getTime()) ? '' : d.toDateString();
  }
  function dateLabel(ts) {
    const d = new Date(String(ts).replace(' ', 'T'));
    const today = new Date(), yest = new Date(); yest.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === yest.toDateString()) return 'Yesterday';
    return d.toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' });
  }
  function dateSeparatorHtml(ts) {
    return `<div class="flex justify-center my-2"><span class="bg-gray-200 text-gray-600 text-[10px] font-medium rounded px-2 py-0.5 shadow-sm">${esc(dateLabel(ts))}</span></div>`;
  }
  let _lastMsgDateKey = null;
  function bubble(m) {
    if (m.sender === 'system') return `<div class="text-center text-[11px] text-amber-600 italic">${esc(m.body)}</div>`;
    if (m.sender === 'note') {
      const mineNote = +m.created_by === +CURRENT_EMP_ID;
      const meta = [m.created_by_name, fmtTime(m.created_at)].filter(Boolean).join(' · ') + (m.edited_at ? ' (edited)' : '');
      return `<div class="text-center text-[11px] bg-amber-100 text-amber-800 rounded px-2 py-1 mx-4">
        <i class="fa-solid fa-note-sticky mr-1"></i>${esc(m.body)}
        ${mineNote ? `<button data-id="${m.id}" data-body="${esc(m.body)}" onclick="editRemark(this.dataset.id,this.dataset.body)" title="Edit your remark" class="ml-1 text-amber-700 hover:text-amber-900"><i class="fa-solid fa-pen text-[10px]"></i></button>` : ''}
        <div class="text-[9px] text-amber-600 mt-0.5">${esc(meta)}</div>
      </div>`;
    }
    const mine = m.sender === 'agent';
    const content = m.attachment_url ? attachmentHtml(m.body, m.attachment_url, m.attachment_type) : esc(m.body);
    const time = fmtTime(m.created_at);
    return `<div class="flex ${mine ? 'justify-end' : 'justify-start'}"><div class="${stColor[m.sender]} rounded-2xl px-3 py-1.5 max-w-[75%] shadow-sm">
      ${content}
      <div class="text-[10px] ${mine ? 'text-indigo-100' : 'text-gray-400'} text-right mt-0.5 select-none">${time}</div>
    </div></div>`;
  }
  async function uploadFile() {
    const input = document.getElementById('fileIn');
    const file = input.files[0]; if (!file || !cur) { input.value = ''; return; }
    const fd = new FormData();
    fd.append('session_id', cur);
    fd.append('file', file);
    try {
      const r = await fetch(API + 'chat-upload.php?action=a_upload', { method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN }, body: fd });
      const j = await r.json();
      if (j.success) pull(); else toast(j.error || 'Upload failed', false);
    } catch (e) { toast('Upload failed', false); }
    input.value = '';
  }
  /* Paint the selected row NOW, straight off the click. The inbox markup (and with it the
     `cur === s.id ? 'bg-indigo-50'` highlight) is only rebuilt inside loadInbox(), which runs
     on a 5s interval — so without this the blue selection appeared seconds after the click and
     the page read as laggy/unresponsive. loadInbox() still re-applies the same class on its
     next pass; this just stops the UI waiting on it. */
  function highlightInbox(id) {
    document.querySelectorAll('#inbox [data-id]').forEach(el =>
      el.classList.toggle('bg-indigo-50', +el.dataset.id === +id));
  }
  async function openChat(id, name) {
    // Stop polling the previously opened chat first
    if (pollT) {
      clearInterval(pollT);
      pollT = null;
    }
    cur = +id;   // dataset.id arrives as a string — keep `cur` numeric so `cur === s.id` still matches on re-render
    highlightInbox(cur);
    lastId = 0;
    _lastMsgDateKey = null;
    shownTicketAttachmentId = null;
    document.getElementById('thread').innerHTML = '';
    document.getElementById('composer')
      .classList.add('hidden');
    document.getElementById('missedToolbar')
      .classList.add('hidden');
    document.getElementById('readOnlyBanner')
      .classList.add('hidden');
    hideTransferMenu();
    // Immediate placeholder (avoids a blank header while session_info is in flight) — the real
    // name + full contact/email/company strip below replaces this the moment it loads, so a
    // chat opened without a name passed in (e.g. from Pickup or a ?open= deep link) never gets
    // stuck showing "Chat #<id>".
    document.getElementById('chatHeadLabel').innerHTML =
      `<span class="font-semibold text-gray-700">${esc(name || 'Loading…')
      }</span>
        <span class="text-[11px] text-gray-400">· live</span>`;
    document.getElementById('chatHeadDetails').innerHTML = '';
    document.getElementById('summarizeBtn')
      .classList.remove('hidden');
    document.getElementById('transferBtn')
      .classList.remove('hidden');
    loadChatHeadDetails(cur);
    await pull();
    // Start polling only for the newly opened chat
    pollT = setInterval(pull, 3000);
  }
  /* Customer detail strip under the header: name / phone / email / company — everything an
     agent needs to identify who they're talking to, at a glance, without leaving the chat.
     Every field is always shown (a "—" placeholder if the customer never gave it) rather than
     silently dropped, so the strip's shape stays predictable and nothing looks "missing" —
     it wraps onto a second line on its own (flex-wrap) if all four don't fit on one. */
  async function loadChatHeadDetails(id) {
    try {
      const { data } = await apiGet('chat.php', { action: 'session_info', session_id: id });
      if (cur !== id) return;
      document.getElementById('chatHeadLabel').innerHTML =
        `<span class="font-semibold text-gray-700">${esc(data.visitor_name || 'Visitor')}</span>
         <span class="text-[11px] text-gray-400">· live</span>`;
      const field = (icon, val) => `<span><i class="fa-solid ${icon} text-gray-400"></i> ${val ? esc(val) : '<span class="text-gray-300">—</span>'}</span>`;
      document.getElementById('chatHeadDetails').innerHTML =
        field('fa-phone', data.visitor_phone) + field('fa-envelope', data.visitor_email) + field('fa-building', data.company);
    } catch (e) { }
  }
  function ticketAttachmentBubble(a) {
    if (!a || !a.url) {
      return '';
    }
    const content = attachmentHtml(
      a.name || 'Transaction attachment',
      a.url,
      a.type || ''
    );
    return `
        <div class="flex justify-start">
            <div class="
                bg-white
                border
                rounded-2xl
                px-3
                py-2
                max-w-[75%]
                shadow-sm
            ">
                <div class="
                    text-[10px]
                    text-gray-400
                    mb-1
                ">
                    Transaction attachment
                </div>
                ${content}
            </div>
        </div>
    `;
  }
  async function summarizeChat() {
    if (!cur) return;
    const btn = document.getElementById('summarizeBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Summarizing…';
    const r = await apiSend('chat.php?action=a_summarize', { session_id: cur });
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Summarize';
    if (!r.success) return toast(r.error || 'Summarize failed', false);
    openModal(`${renderAiSummary(r.data.summary)}<div class="text-[10px] text-gray-400 mt-2">via ${esc(r.data.source)}</div>`, 'Chat summary');
  }
  // Guards against overlapping calls: the 3s setInterval(pull) keeps running in the background
  // while send() also calls pull() directly for immediate feedback after posting. If both land
  // in flight at once, they both read the same stale `lastId`, both get the same new message(s)
  // back in their response, and both append a duplicate bubble to the DOM before either one
  // updates `lastId` — the message the agent just sent shows up twice on their own screen (the
  // visitor's widget isn't affected, since it polls independently via v_poll, not this pull()).
  let _pulling = false;
  async function pull() {
    if (!cur || _pulling) return;
    _pulling = true;
    const requestSessionId = cur;
    try {
    const { data } = await apiGet(
      'chat.php',
      {
        action: 'a_poll',
        session_id: requestSessionId,
        since: lastId
      }
    );
    if (cur !== requestSessionId) {
      return;
    }
    const thread =
      document.getElementById('thread');
    /* =========================
       TRANSACTION ATTACHMENT
       ========================= */
    if (
      data.ticket_attachment &&
      data.ticket_attachment.id !==
      shownTicketAttachmentId
    ) {
      thread.insertAdjacentHTML(
        'beforeend',
        ticketAttachmentBubble(
          data.ticket_attachment
        )
      );
      shownTicketAttachmentId =
        data.ticket_attachment.id;
    }
    /* =========================
       NORMAL CHAT MESSAGES
       ========================= */
    if (data.messages.length) {
      data.messages.forEach(m => {
        const k = dateKey(m.created_at);
        if (k && k !== _lastMsgDateKey) {
          thread.insertAdjacentHTML('beforeend', dateSeparatorHtml(m.created_at));
          _lastMsgDateKey = k;
        }
        thread.insertAdjacentHTML(
          'beforeend',
          bubble(m)
        );
        lastId = m.id;
      });
    }
    thread.scrollTop = thread.scrollHeight;
    const banner = document.getElementById('readOnlyBanner');
    const missedToolbar = document.getElementById('missedToolbar');
    document.getElementById('transferBtn').classList.toggle('hidden', data.status === 'closed' || data.status === 'missed');
    if (data.status === 'closed') {
      document.getElementById('composer').classList.add('hidden');
      missedToolbar.classList.add('hidden');
      banner.classList.add('hidden');
      clearInterval(pollT);
    } else if (data.status === 'missed') {
      // Missed chats are read-only for the normal composer — only a remark or closing it out.
      document.getElementById('composer').classList.add('hidden');
      banner.classList.add('hidden');
      missedToolbar.classList.remove('hidden');
    } else if (!data.can_write) {
      // Team-wide read, owner-only write: anyone in the department can watch this chat, but
      // only its current owner (shown here) may actually type into it.
      document.getElementById('composer').classList.add('hidden');
      missedToolbar.classList.add('hidden');
      document.getElementById('readOnlyBannerText').textContent =
        `View-only — this chat is currently assigned to ${data.owner_name || 'another agent'}.`;
      banner.classList.remove('hidden');
    } else {
      document.getElementById('composer').classList.remove('hidden');
      missedToolbar.classList.add('hidden');
      banner.classList.add('hidden');
    }
    } finally {
      _pulling = false;
    }
  }
  async function send() {
    const el = document.getElementById('msgIn'); const b = el.value.trim(); if (!b || !cur) return;
    el.value = '';
    const r = await apiSend('chat.php?action=a_post', { session_id: cur, body: b });
    if (!r.success) { toast(r.error || 'Could not send — refreshing…', false); el.value = b; }
    pull();
  }

  /* ---- Transfer: dropdown of every other agent (own dept by default) with an online/offline dot ---- */
  async function toggleTransferMenu() {
    const menu = document.getElementById('transferMenu');
    if (!menu.classList.contains('hidden')) { hideTransferMenu(); return; }
    if (!cur) return;
    menu.innerHTML = '<div class="p-3 text-gray-400">Loading agents…</div>';
    menu.classList.remove('hidden');
    const { data } = await apiGet('chat.php', { action: 'agents', vertical: 'all' });
    if (!data.length) { menu.innerHTML = '<div class="p-3 text-gray-400">No other agents found</div>'; return; }
    menu.innerHTML = data.map(a => `
    <div data-id="${a.id}" data-name="${esc(a.full_name || '')}" onclick="transferTo(this.dataset.id, this.dataset.name)" class="px-3 py-2 flex items-center gap-2 hover:bg-gray-50 cursor-pointer border-b last:border-b-0">
      <span class="w-2 h-2 rounded-full flex-shrink-0 ${a.is_online == 1 ? 'bg-green-500' : 'bg-red-400'}"></span>
      <span class="flex-1 truncate">${esc(a.full_name)}</span>
      <span class="text-[10px] text-gray-400 uppercase">${esc((a.role || '').replace('_', ' '))}</span>
    </div>`).join('');
  }
  function hideTransferMenu() { document.getElementById('transferMenu').classList.add('hidden'); }
  async function transferTo(agentId, name) {
    if (!cur) return;
    hideTransferMenu();
    if (!confirm(`Transfer this chat to ${name}? You'll lose the ability to reply once it's transferred.`)) return;
    const r = await apiSend('chat.php?action=transfer', { session_id: cur, agent_id: agentId });
    if (!r.success) return toast(r.error || 'Transfer failed', false);
    toast(`Transferred to ${name}`);
    pull();
    loadInbox();
  }
  document.addEventListener('click', (e) => {
    const menu = document.getElementById('transferMenu');
    const btn = document.getElementById('transferBtn');
    if (!menu || menu.classList.contains('hidden')) return;
    if (!menu.contains(e.target) && e.target !== btn && !btn.contains(e.target)) hideTransferMenu();
  });
  async function closeChat() {
    if (!cur || !confirm('End this chat? Time will be logged to your timesheet.')) return;
    const r = await apiSend('chat.php?action=close', { session_id: cur });
    if (r.success) { toast('Chat closed — ' + Math.round((r.data.duration_sec || 0) / 60) + ' min logged'); document.getElementById('composer').classList.add('hidden'); loadInbox(); }
  }
  async function addRemark() {
    const el = document.getElementById('remarkIn'); const b = el.value.trim(); if (!b || !cur) return;
    el.value = '';
    const r = await apiSend('chat.php?action=missed_remark', { session_id: cur, body: b });
    if (!r.success) { toast(r.error || 'Could not add remark', false); el.value = b; return; }
    pull();
  }
  async function editRemark(id, current) {
    const next = prompt('Edit your remark:', current);
    if (next === null || next.trim() === '' || next === current) return;
    const r = await apiSend('chat.php?action=edit_remark', { message_id: id, body: next.trim() });
    if (!r.success) return toast(r.error || 'Could not edit remark', false);
    toast('Remark updated');
    // The edited message already has an id <= lastId, so an incremental pull() won't re-fetch
    // it — reset the cursor and reload the whole thread so the edited text/"" (edited)" shows.
    lastId = 0; _lastMsgDateKey = null;
    document.getElementById('thread').innerHTML = '';
    pull();
  }
  async function closeMissed() {
    if (!cur || !confirm('Mark this missed chat as closed?')) return;
    const r = await apiSend('chat.php?action=missed_close', { session_id: cur });
    if (!r.success) return toast(r.error || 'Could not close', false);
    toast('Chat closed');
    document.getElementById('missedToolbar').classList.add('hidden');
    loadInbox();
  }
  /* ---- new-chat sound + desktop alert -----------------------------------------------------
     The alarm (chime()) is mandatory and Service-only: it rings the moment a chat lands in the
     waiting queue and keeps repeating every few seconds until SOMEONE picks it up (whoever
     clicks first wins — pickup() is an atomic UPDATE ... WHERE status='waiting', so only the
     first request succeeds; loadInbox() then sees an empty waiting[] and the alarm stops for
     everyone). Sales/managers/admin never hear it — Sales doesn't run an incoming live-chat
     queue day-to-day; a Service exec who decides a chat is actually sales-related transfers it
     to a Sales exec instead (see ?action=transfer), who then just replies normally, no alarm
     needed. The bell icon only toggles the *desktop Notification popup*, which any role may want. */
  let _seenWait = null, alarmT = null;
  // Desktop popup is on by default (agents shouldn't have to discover the bell icon first) —
  // only actually off once someone explicitly toggles it off.
  function bellOn() { return localStorage.getItem('chatBell') !== '0'; }
  function toggleBell() {
    const on = !bellOn(); localStorage.setItem('chatBell', on ? '1' : '0');
    if (on && 'Notification' in window && Notification.permission !== 'granted') Notification.requestPermission();
    updateBell(); toast(on ? 'Alerts on 🔔' : 'Alerts off');
  }
  function updateBell() { const b = document.getElementById('bellBtn'); if (b) b.innerHTML = bellOn() ? '<i class="fa-solid fa-bell text-indigo-500"></i>' : '<i class="fa-regular fa-bell"></i>'; }
  /* Browsers (Chrome/Firefox/Safari) refuse to let audio actually play from an AudioContext
     until the page has seen at least one real user gesture (click/keydown/touch) — creating a
     fresh context per chime and calling start() with no prior gesture just produces silence,
     no error. So: keep ONE context, and unlock/resume it the moment the agent interacts with
     the page anywhere, well before a real incoming chat needs to ring. */
  let _audioCtx = null;
  function getAudioCtx() {
    if (!_audioCtx) {
      try { _audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
      catch (e) { return null; }
    }
    if (_audioCtx.state === 'suspended') _audioCtx.resume().catch(() => {});
    return _audioCtx;
  }
  ['click', 'keydown', 'touchstart'].forEach(evt =>
    document.addEventListener(evt, getAudioCtx, { once: true, passive: true }));
  /* A soft two-note chime (not a harsh single beep) — closer to a professional notification tone. */
  function chime() {
    const ctx = getAudioCtx();
    if (!ctx) return;
    try {
      const tone = (freq, start, dur) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.value = freq;
        o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, start);
        g.gain.exponentialRampToValueAtTime(0.28, start + 0.03);
        g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
        o.start(start); o.stop(start + dur + 0.05);
      };
      const now = ctx.currentTime;
      tone(1046.5, now, 0.22);          // C6
      tone(1318.5, now + 0.16, 0.30);   // E6
    } catch (e) { }
  }
  function notifyWaiting(waiting) {
    const ids = new Set((waiting || []).map(w => w.id));
    if (_seenWait === null) { _seenWait = ids; return; }
    const fresh = (waiting || []).filter(w => !_seenWait.has(w.id));
    _seenWait = ids;
    if (fresh.length && bellOn() && 'Notification' in window && Notification.permission === 'granted')
      fresh.forEach(w => {
        const notif = new Notification('New chat waiting', { body: (w.visitor_name || 'A visitor') + ' is currently free — click to pick up' });
        notif.onclick = () => { window.focus(); setFilter('active'); pickup(w.id); notif.close(); };
      });
  }
  /* Service-only, mandatory, repeats until the waiting queue is empty (see comment above). */
  function updateAlarm(waitingCount) {
    if (CURRENT_ROLE !== 'service_exec') return;
    if (waitingCount > 0 && !alarmT) {
      chime();
      alarmT = setInterval(chime, 4000);
    } else if (waitingCount === 0 && alarmT) {
      clearInterval(alarmT); alarmT = null;
    }
  }
  /* deep link from a Leads row's Live Chat icon: sales-chat.php?open=<session_id>&tab=<status> —
     lands on whichever tab the chat actually lives in (active/missed/closed) instead of always
     assuming Active, so a closed or missed chat opens read-only in its own tab. */
  function openParam() {
    const params = new URLSearchParams(location.search);
    const id = params.get('open');
    const tab = params.get('tab');
    if (!id) return;
    if (['active', 'missed', 'closed'].includes(tab) && tab !== filter) setFilter(tab);
    openChat(+id);
  }

  updateBell();
  // Ask for the desktop-notification permission up front (bell defaults to on) instead of
  // waiting for the agent to find and click the bell icon — otherwise the popup silently
  // never fires because permission was never granted.
  if (bellOn() && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  loadInbox();
  openParam();
  setInterval(loadInbox, 5000);
</script>

</html>