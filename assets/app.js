/* Progressive enhancement only — every action works without JavaScript. */
(function () {
  'use strict';

  // Register the service worker so the app installs as a PWA and already-seen
  // pages stay readable without a connection.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {
        /* Not fatal — the app works fine without it. */
      });
    });
  }

  // Tell the organizer the moment the connection drops. A write that cannot
  // reach the server is refused outright rather than queued, so knowing the
  // state matters before they tap anything.
  var banner = null;
  function setOnline(online) {
    if (online) {
      if (banner) { banner.remove(); banner = null; }
      return;
    }
    if (banner) return;
    banner = document.createElement('div');
    banner.className = 'netbar';
    banner.setAttribute('role', 'status');
    banner.textContent = 'Offline — results cannot be saved until you reconnect';
    document.body.appendChild(banner);
  }
  window.addEventListener('online', function () { setOnline(true); });
  window.addEventListener('offline', function () { setOnline(false); });
  setOnline(navigator.onLine);

  // Guard against a double submit on a slow connection.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    var btn = form.querySelector('button[type=submit], button:not([type])');
    if (!btn || btn.dataset.busy) return;
    btn.dataset.busy = '1';
    setTimeout(function () {
      btn.setAttribute('aria-disabled', 'true');
    }, 0);
    setTimeout(function () {
      delete btn.dataset.busy;
      btn.removeAttribute('aria-disabled');
    }, 4000);
  });

  // Tap the board link to copy it.
  var board = document.getElementById('boardurl');
  if (board && navigator.clipboard) {
    board.addEventListener('click', function () {
      navigator.clipboard.writeText(board.value).then(function () {
        var was = board.style.borderColor;
        board.style.borderColor = '#34d399';
        setTimeout(function () { board.style.borderColor = was; }, 900);
      }, function () { /* selection already happened */ });
    });
  }

  // Keep the live score inputs on the numeric keypad and move on when filled.
  document.querySelectorAll('input[name=s1]').forEach(function (el) {
    el.addEventListener('input', function () {
      if (el.value.length >= 2) {
        var s2 = el.form && el.form.querySelector('input[name=s2]');
        if (s2) s2.focus();
      }
    });
  });
}());
