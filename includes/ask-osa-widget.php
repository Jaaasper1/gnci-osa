<?php
// Messenger-style chat head (bottom-right). Opens a real conversation with OSA staff/admin.
// Talks to student/ask-api.php. Shown on student pages.
require_once __DIR__ . '/csrf.php';
if (!function_exists('t')) { require_once __DIR__ . '/lang.php'; }
?>
<style>
  #osaChatWrap {
    position:fixed; right:24px; bottom:24px; z-index:9999;
    display:flex; flex-direction:column; align-items:flex-end; gap:12px;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  }
  #osaChatHead {
    position:relative; display:flex; align-items:center; gap:8px; padding:6px 16px 6px 6px;
    transition:padding .35s ease, gap .35s ease, background .2s, transform .2s;
    background:#6B1A2A; color:#fff; border:none; border-radius:999px; cursor:pointer;
    box-shadow:0 6px 18px rgba(0,0,0,0.18); font:600 14px system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  }
  #osaChatHead img { width:36px; height:36px; border-radius:50%; background:#fff; object-fit:cover; }
  #osaChatHead:hover { background:#561421; }
  #osaChatHead .osa-label { display:inline-block; max-width:120px; overflow:hidden; white-space:nowrap; opacity:1;
    transition:max-width .35s ease, opacity .25s ease; }
  /* Collapsed: the pill slides shut into a circle (logo only). Hover/focus/tap re-expands it. */
  #osaChatHead.collapsed { padding:6px; gap:0; }
  #osaChatHead.collapsed .osa-label { max-width:0; opacity:0; }
  #osaChatHead.collapsed:hover, #osaChatHead.collapsed:focus-visible { padding:6px 16px 6px 6px; gap:8px; }
  #osaChatHead.collapsed:hover .osa-label, #osaChatHead.collapsed:focus-visible .osa-label { max-width:120px; opacity:1; }
  #osaChatHead.collapsed .osa-dot { position:absolute; top:2px; right:2px; border:2px solid #fff; box-sizing:content-box; }
  #osaChatHead:active { transform:scale(.94); }
  #osaChatHead .osa-dot { width:9px; height:9px; border-radius:50%; background:#34D399; display:none; }
  #osaChatHead.has-new .osa-dot { display:inline-block; }

  #osaChatPanel {
    width:360px; max-width:calc(100vw - 32px); height:520px; max-height:calc(100vh - 120px);
    display:none; flex-direction:column; background:#fff; border:1px solid #DCDAD2; border-radius:18px; overflow:hidden;
    box-shadow:0 16px 40px rgba(0,0,0,0.18);
  }
  #osaChatPanel.open { display:flex; transform-origin:bottom right; animation:osaPop .25s ease-out; }
  @keyframes osaPop { from { opacity:0; transform:scale(.85) translateY(12px); } to { opacity:1; transform:none; } }
  @media (prefers-reduced-motion: reduce) {
    #osaChatHead, #osaChatHead .osa-label { transition:none; }
    #osaChatPanel.open { animation:none; }
  }

  .osa-head { background:#fff; border-bottom:1px solid #EFEEE8; padding:12px 14px; display:flex; align-items:center; gap:10px; }
  .osa-head img { width:38px; height:38px; border-radius:50%; object-fit:cover; border:1px solid #E5E3DC; }
  .osa-head .osa-title { font-weight:700; font-size:15px; color:#1F1E1D; line-height:1.2; }
  .osa-head .osa-sub { font-size:12px; color:#8A8880; }
  .osa-head .osa-actions { margin-left:auto; display:flex; gap:6px; align-items:center; }
  .osa-head a.osa-full { font-size:12px; color:#6B1A2A; font-weight:600; text-decoration:none; padding:4px 8px; border-radius:8px; }
  .osa-head a.osa-full:hover { background:#F6E7EA; }
  .osa-head .osa-close { background:none; border:none; color:#8A8880; font-size:22px; cursor:pointer; line-height:1; padding:0 4px; }

  .osa-body { flex:1; overflow-y:auto; padding:14px; display:flex; flex-direction:column; gap:10px; background:#FAF9F5; }
  .osa-empty { text-align:center; color:#8A8880; font-size:13px; margin:auto; padding:20px; }
  .osa-row { display:flex; flex-direction:column; max-width:82%; }
  .osa-row.mine { align-self:flex-end; align-items:flex-end; }
  .osa-row.theirs { align-self:flex-start; align-items:flex-start; }
  .osa-meta { font-size:10.5px; color:#8A8880; margin:0 4px 3px; }
  .osa-bubble { padding:9px 13px; font-size:14px; line-height:1.5; white-space:pre-wrap; word-break:break-word; border-radius:16px; }
  .osa-row.mine .osa-bubble { background:#6B1A2A; color:#fff; border-bottom-right-radius:5px; }
  .osa-row.theirs .osa-bubble { background:#fff; border:1px solid #E5E3DC; color:#1F1E1D; border-bottom-left-radius:5px; }

  .osa-foot { border-top:1px solid #EFEEE8; padding:10px 12px; background:#fff; }
  .osa-foot form { display:flex; gap:8px; align-items:flex-end; }
  .osa-foot textarea {
    flex:1; resize:none; border:1px solid #DCDAD2; border-radius:16px; padding:8px 12px; font:inherit; font-size:14px;
    line-height:1.4; max-height:110px; outline:none;
  }
  .osa-foot textarea:focus { border-color:#6B1A2A; }
  .osa-foot button { width:38px; height:38px; border-radius:50%; border:none; background:#6B1A2A; color:#fff; cursor:pointer; font-size:16px; flex-shrink:0; }
  .osa-foot button:disabled { opacity:.5; cursor:default; }
  .osa-err { font-size:12px; color:#B42318; margin-top:6px; min-height:14px; }
  .osa-ai { font-size:11px; color:#8A8880; margin-top:2px; }

  @media (max-width: 600px) { #osaChatPanel { width:calc(100vw - 32px); } }
</style>

<div id="osaChatWrap">
  <div id="osaChatPanel" role="dialog" aria-label="<?= htmlspecialchars(t('auto.chat_with_osa_office_f9a4', 'Chat with OSA Office'), ENT_QUOTES) ?>">
    <div class="osa-head">
      <img src="../assets/img/osa-logo.jpg" alt="OSA Logo">
      <div>
        <div class="osa-title"><?= t('auto.osa_office_8fa9', 'OSA Office') ?></div>
        <div class="osa-sub"><?= t('auto.common_questions_are_answered_automatica_db1c', 'Common questions are answered automatically') ?></div>
      </div>
      <div class="osa-actions">
        <a class="osa-full" href="messages.php"><?= t('auto.full_view_cb3d', 'Full view') ?></a>
        <button class="osa-close" type="button" id="osaChatClose" aria-label="<?= htmlspecialchars(t('auto.close_d3d2', 'Close'), ENT_QUOTES) ?>">&times;</button>
      </div>
    </div>

    <div class="osa-body" id="osaChatBody">
      <div class="osa-empty" id="osaChatEmpty"><?= t('auto.ask_a_question_common_questions_get_an_a_079a', 'Ask a question. Common questions get an automatic answer. Anything else goes to the OSA admin or staff.') ?></div>
    </div>

    <div class="osa-foot">
      <form id="osaChatForm" autocomplete="off">
        <textarea id="osaChatText" rows="1" maxlength="2000" placeholder="<?= htmlspecialchars(t('auto.write_a_message_ba3a', 'Write a message...'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('auto.message_4c2a', 'Message'), ENT_QUOTES) ?>"></textarea>
        <button type="submit" id="osaChatSend" aria-label="<?= htmlspecialchars(t('auto.send_9496', 'Send'), ENT_QUOTES) ?>"><i class="ti ti-send"></i></button>
      </form>
      <div class="osa-err" id="osaChatErr"></div>
      <div class="osa-ai" id="osaChatAi"></div>
    </div>
  </div>

  <button id="osaChatHead" type="button" aria-controls="osaChatPanel" aria-expanded="false">
    <img src="../assets/img/osa-logo.jpg" alt="">
    <span class="osa-label"><?= t('auto.ask_osa_11b4', 'Ask OSA') ?></span>
    <span class="osa-dot" aria-hidden="true"></span>
  </button>
</div>

<script>
(function () {
  const ENDPOINT = 'ask-api.php';
  let csrf = <?= json_encode(csrfToken()) ?>;

  const head  = document.getElementById('osaChatHead');
  const panel = document.getElementById('osaChatPanel');
  const close = document.getElementById('osaChatClose');
  const body  = document.getElementById('osaChatBody');
  const empty = document.getElementById('osaChatEmpty');
  const form  = document.getElementById('osaChatForm');
  const text  = document.getElementById('osaChatText');
  const send  = document.getElementById('osaChatSend');
  const err   = document.getElementById('osaChatErr');
  const aiEl  = document.getElementById('osaChatAi');

  let lastCount = -1, timer = null;

  function setErr(msg) { err.textContent = msg || ''; }
  function showAi(n) {
    if (typeof n !== 'number') return;
    aiEl.textContent = n > 0 ? ('AI questions left: ' + n) : __t('No AI questions left. Staff will reply.');
  }

  function render(messages) {
    // Rebuild only when something changed, so scrolling stays smooth.
    if (messages.length === lastCount) return;
    const stick = body.scrollTop + body.clientHeight >= body.scrollHeight - 40;
    body.querySelectorAll('.osa-row').forEach(n => n.remove());
    empty.style.display = messages.length ? 'none' : '';
    messages.forEach(m => {
      const row = document.createElement('div');
      row.className = 'osa-row ' + (m.mine ? 'mine' : 'theirs');
      const meta = document.createElement('div');
      meta.className = 'osa-meta';
      meta.textContent = m.name + ' · ' + m.at;
      const bubble = document.createElement('div');
      bubble.className = 'osa-bubble';
      bubble.textContent = m.body;          // textContent: never inserts HTML
      row.appendChild(meta);
      row.appendChild(bubble);
      body.appendChild(row);
    });
    lastCount = messages.length;
    if (stick || lastCount <= messages.length) body.scrollTop = body.scrollHeight;
  }

  async function load() {
    try {
      const r = await fetch(ENDPOINT, { credentials: 'same-origin', cache: 'no-store' });
      const raw = await r.text();
      let d;
      try { d = JSON.parse(raw); }
      catch (_) { setErr(__t('Server error (') + r.status + '): ' + raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120)); return; }
      if (!d.ok) { setErr(d.error || __t('Could not load messages.')); return; }
      csrf = d.csrf || csrf;
      setErr('');
      showAi(d.ai_left);
      render(d.messages || []);
    } catch (e) {
      setErr('Connection problem. Trying again...');
    }
  }

  async function sendMessage(text) {
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('body', text);
    const r = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' });
    const d = await r.json().catch(() => ({ ok: false, error: __t('Server error (') + r.status + '). Please try again.' }));
    if (!d.ok) throw new Error(d.error || __t('Could not send.'));
    csrf = d.csrf || csrf;
    showAi(d.ai_left);
    return d;
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const t = text.value.trim();
    if (!t) return;
    send.disabled = true;
    setErr('');
    try {
      await sendMessage(t);
      text.value = '';
      text.style.height = 'auto';
      lastCount = -1;      // force a redraw
      await load();
    } catch (ex) {
      setErr(ex.message);
    } finally {
      send.disabled = false;
      text.focus();
    }
  });

  // Enter sends, Shift+Enter adds a new line.
  text.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
  text.addEventListener('input', () => { text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight, 110) + 'px'; });

  function setOpen(open) {
    panel.classList.toggle('open', open);
    head.setAttribute('aria-expanded', String(open));
    clearInterval(timer);
    if (!open) expandHead(); else head.classList.remove('collapsed');
    if (open) {
      head.classList.remove('has-new');
      load();
      timer = setInterval(load, 8000);   // refresh while open so staff replies appear
      text.focus();
    }
  }
  head.addEventListener('click', () => setOpen(!panel.classList.contains('open')));

  // Auto-collapse into a circle: after 4s idle, or when the page is scrolled down.
  let collapseTimer = null;
  function expandHead() { head.classList.remove('collapsed'); clearTimeout(collapseTimer); collapseTimer = setTimeout(collapseHead, 4000); }
  function collapseHead() { if (!panel.classList.contains('open')) head.classList.add('collapsed'); }
  collapseTimer = setTimeout(collapseHead, 4000);
  window.addEventListener('scroll', () => { if (window.scrollY > 80) collapseHead(); else expandHead(); }, { passive: true });
  head.addEventListener('mouseenter', () => clearTimeout(collapseTimer));
  head.addEventListener('mouseleave', () => { collapseTimer = setTimeout(collapseHead, 2500); });
  head.addEventListener('touchstart', () => { if (head.classList.contains('collapsed')) expandHead(); }, { passive: true });
  close.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });

  // Light background check so the head shows a dot when staff replied while the chat is closed.
  setInterval(async () => {
    if (panel.classList.contains('open')) return;
    try {
      const r = await fetch(ENDPOINT + '?peek=1', { credentials: 'same-origin', cache: 'no-store' });
      const d = await r.json();
      if (d.ok && d.thread && d.messages.length && !d.messages[d.messages.length - 1].mine) head.classList.add('has-new');
    } catch (e) {}
  }, 60000);
})();
</script>
