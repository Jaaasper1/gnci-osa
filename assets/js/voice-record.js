window.__t = window.__t || function (s) { return s; };
/* GNC-OSA Portal — Voice message recorder.
   Adds a mic button to any form marked data-voice-form. Click once to start,
   click again to stop: the recording is attached as the "voice" file and the
   form is submitted. Uses the browser's MediaRecorder (needs HTTPS or localhost). */
(function () {
  if (!navigator.mediaDevices || !window.MediaRecorder) return; // unsupported: no button

  var style = document.createElement('style');
  style.textContent =
    '.voice-row{display:flex;align-items:center;gap:8px;margin:6px 0 10px;flex-wrap:wrap;}' +
    '.voice-status{font-size:12px;color:#6B7280;}' +
    '.voice-btn.recording{background:#DC2626;border-color:#DC2626;color:#fff;}' +
    '.voice-btn.recording i{animation:voicePulse 1s infinite;}' +
    '@keyframes voicePulse{50%{opacity:.35;}}' +
    '.msg-audio{display:block;max-width:100%;height:40px;margin:4px 0;}';
  document.head.appendChild(style);

  var MAX_MS = 120000; // 2 minutes

  document.querySelectorAll('[data-voice-form]').forEach(setup);

  function setup(form) {
    var btn = form.querySelector('[data-voice-btn]');
    var input = form.querySelector('[data-voice-input]');
    var status = form.querySelector('[data-voice-status]');
    if (!btn || !input) return;

    var label = btn.querySelector('span');
    var idleText = label ? label.textContent : __t('Voice message');
    var rec = null, chunks = [], stream = null, timer = null;

    btn.addEventListener('click', function () {
      if (rec) { rec.stop(); return; }
      start();
    });

    function start() {
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
        stream = s;
        chunks = [];
        var mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']
          .filter(function (t) { return MediaRecorder.isTypeSupported(t); })[0] || '';
        rec = mime ? new MediaRecorder(s, { mimeType: mime }) : new MediaRecorder(s);
        rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = function () { finish(rec.mimeType || mime || 'audio/webm'); };
        rec.start();
        btn.classList.add('recording');
        if (label) label.textContent = 'Stop & send';
        if (status) status.textContent = 'Nagre-record...';
        timer = setTimeout(function () { if (rec && rec.state !== 'inactive') rec.stop(); }, MAX_MS);
      }).catch(function () {
        if (status) status.textContent = 'Hindi ma-access ang mic. Payagan ito sa browser.';
      });
    }

    function finish(type) {
      clearTimeout(timer);
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      var blob = new Blob(chunks, { type: type });
      rec = null;
      reset();
      if (blob.size < 1000) {
        if (status) status.textContent = 'Masyadong maikli. Subukan ulit.';
        return;
      }
      var ext = type.indexOf('ogg') > -1 ? 'ogg' : (type.indexOf('mp4') > -1 ? 'm4a' : 'webm');
      var dt = new DataTransfer();
      dt.items.add(new File([blob], 'voice.' + ext, { type: type }));
      input.files = dt.files;
      if (status) status.textContent = 'Ipinapadala...';
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    }

    function reset() {
      btn.classList.remove('recording');
      if (label) label.textContent = idleText;
    }
  }
})();
