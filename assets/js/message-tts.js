window.__t = window.__t || function (s) { return s; };
/* GNC-OSA Portal — Read-aloud (text-to-speech) for message bubbles.
   Uses the browser's built-in Web Speech API (no external library, no internet call).
   Adds a speaker button to every .msg-bubble in the thread. Works for both
   the student view and the OSA staff view. */
(function () {
  if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) return;

  var ICON_PLAY = '<i class="ti ti-volume"></i>';
  var ICON_STOP = '<i class="ti ti-player-stop"></i>';
  var current = null; // { btn: HTMLElement }

  // Tagalog detection: if the message has common Tagalog words, read it as fil-PH.
  var TL_WORDS = /\b(ang|ng|mga|sa|ko|ako|ikaw|po|naman|hindi|yung|nila|namin|natin|ay|lang|din|rin|kasi|pero|para|ito|iyan|iyon|kami|tayo|sila|may|wala)\b/i;

  // Choose the most natural-sounding voice the device has.
  // Edge/Windows __t("Natural") and __t("Online") voices sound the most human;
  // then Google/Microsoft voices; otherwise any voice for that language.
  var LANG_RE = { 'fil-PH': /^(fil|tl)/i, 'en-US': /^en/i };
  var QUALITY = [/natural|online/i, /google|microsoft/i];
  function pickVoice(lang) {
    var voices = window.speechSynthesis.getVoices() || [];
    var match = voices.filter(function (v) { return LANG_RE[lang].test(v.lang); });
    if (!match.length) return null;
    for (var i = 0; i < QUALITY.length; i++) {
      var best = match.find(function (v) { return QUALITY[i].test(v.name); });
      if (best) return best;
    }
    return match[0];
  }

  function stopSpeaking() {
    window.speechSynthesis.cancel();
    if (current) {
      current.btn.classList.remove('is-speaking');
      current.btn.innerHTML = ICON_PLAY;
      current.btn.setAttribute('aria-label', __t('Read aloud'));
      current.btn.title = 'Basahin';
      current = null;
    }
  }

  function speak(text, btn) {
    // Clicking the same button again stops playback
    if (current && current.btn === btn) { stopSpeaking(); return; }
    stopSpeaking();

    var lang = TL_WORDS.test(text) ? 'fil-PH' : 'en-US';
    var u = new SpeechSynthesisUtterance(text);
    u.lang = lang;
    u.voice = pickVoice(lang);
    u.rate = 0.92;   // a little slower sounds more natural than the default
    u.pitch = 1.0;

    btn.classList.add('is-speaking');
    btn.innerHTML = ICON_STOP;
    btn.setAttribute('aria-label', __t('Stop reading'));
    btn.title = 'Itigil';
    current = { btn: btn };

    u.onend = u.onerror = function () {
      if (current && current.btn === btn) stopSpeaking();
    };
    window.speechSynthesis.speak(u);
  }

  function addButtons() {
    var bubbles = document.querySelectorAll('.msg-bubble');
    bubbles.forEach(function (bubble) {
      if (bubble.dataset.ttsReady) return;
      bubble.dataset.ttsReady = '1';

      var text = (bubble.innerText || bubble.textContent || '').trim();
      if (!text) return;

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'msg-tts';
      btn.innerHTML = ICON_PLAY;
      btn.title = 'Basahin';
      btn.setAttribute('aria-label', __t('Read aloud'));
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        speak(text, btn);
      });

      // Put the button next to the sender/time line when there is one
      var row = bubble.closest('.msg-row');
      var meta = row ? row.querySelector('.msg-meta') : null;
      if (meta) {
        meta.appendChild(btn);
      } else {
        bubble.parentNode.insertBefore(btn, bubble);
      }
    });
  }

  var style = document.createElement('style');
  style.textContent =
    '.msg-tts{border:none;background:none;cursor:pointer;color:#6B7280;padding:2px 6px;' +
    'border-radius:6px;font-size:14px;margin-left:6px;vertical-align:middle;line-height:1;}' +
    '.msg-tts:hover{color:#6B1A2A;background:#F9EEF0;}' +
    '.msg-tts.is-speaking{color:#fff;background:#6B1A2A;}';
  document.head.appendChild(style);

  // Some browsers load voices late; nothing to wait for, but stop speech on page leave.
  window.addEventListener('pagehide', stopSpeaking);
  // Voice list loads late in some browsers; pickVoice() reads it on each click.
  if (window.speechSynthesis) window.speechSynthesis.onvoiceschanged = function () {};

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addButtons);
  } else {
    addButtons();
  }
})();