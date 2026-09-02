/* ===========================================================================
   Progressive enhancement only — every action works with JavaScript off.
   Motion is deliberately restrained: entrance choreography on the main content
   blocks and nothing else, so the eye goes to the court, not the chrome.
   =========================================================================== */
(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------- offline state --- */
  /* Offline caching and "install to home screen" need a secure context.
     https:// and localhost qualify; a LAN address over plain http does not —
     there `navigator.serviceWorker` is not even defined. Report which case we
     are in rather than failing silently, so nobody assumes a phone on the LAN
     has offline support when it does not. */
  function reportOfflineState(state, detail) {
    var nodes = document.querySelectorAll('[data-offline-state]');
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].textContent = detail;
      nodes[i].setAttribute('data-offline-state', state);
    }
  }

  if (!window.isSecureContext) {
    reportOfflineState('insecure',
      'Not available on this address — offline caching and install need HTTPS. '
      + 'The app still works normally.');
  } else if (!('serviceWorker' in navigator)) {
    reportOfflineState('unsupported', 'This browser does not support offline caching.');
  } else {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').then(function () {
        return navigator.serviceWorker.ready;
      }).then(function () {
        reportOfflineState('active', 'Active — pages you have opened stay available offline.');
      }).catch(function (err) {
        reportOfflineState('failed',
          'Could not start: ' + (err && err.message ? err.message : 'unknown error'));
      });
    });
  }

  /* --------------------------------------------------- connection bar --- */
  /* A write that cannot reach the server is refused outright rather than
     queued, so knowing the state matters before the organizer taps anything. */
  var bar = null;
  function setOnline(online) {
    if (online) {
      if (bar) { bar.remove(); bar = null; }
      return;
    }
    if (bar) return;
    bar = document.createElement('div');
    bar.className = 'netbar';
    bar.setAttribute('role', 'status');
    bar.textContent = 'Offline — results cannot be saved until you reconnect';
    document.body.appendChild(bar);
  }
  window.addEventListener('online', function () { setOnline(true); });
  window.addEventListener('offline', function () { setOnline(false); });
  setOnline(navigator.onLine);

  /* -------------------------------------------------- entrance reveal --- */
  /* Classes are added by script, never in the markup, so a no-JS or
     reduced-motion visitor always gets fully rendered content. */
  if (!reduced && 'IntersectionObserver' in window) {
    var targets = document.querySelectorAll('main > .card, main > .qr-sheet > .qr-card, main > .plist > .prow');
    if (targets.length) {
      for (var i = 0; i < targets.length; i++) targets[i].classList.add('reveal');

      var io = new IntersectionObserver(function (entries) {
        var shown = 0;
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          var el = entry.target;
          // 40ms stagger, capped so a long list never crawls in.
          var delay = Math.min(shown * 40, 200);
          shown++;
          setTimeout(function () { el.classList.add('in'); }, delay);
          io.unobserve(el);
        });
      }, { threshold: 0.06, rootMargin: '0px 0px -40px 0px' });

      for (var j = 0; j < targets.length; j++) io.observe(targets[j]);
    }
  }

  /* ------------------------------------------------------- stat count --- */
  /* Numbers count up once on first view. Purely to draw the eye to the
     changing figures; values are correct in the markup before this runs. */
  if (!reduced && 'IntersectionObserver' in window) {
    var stats = document.querySelectorAll('.stat .v');
    var sio = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        sio.unobserve(el);
        var raw = el.textContent.trim();
        var m = raw.match(/^(\d+)(.*)$/);
        if (!m) return;
        var target = parseInt(m[1], 10);
        var suffix = m[2] || '';
        if (target < 2 || target > 999) return;
        var start = performance.now(), dur = 520;
        (function tick(now) {
          var p = Math.min(1, (now - start) / dur);
          var eased = 1 - Math.pow(1 - p, 3);           // ease-out cubic
          el.textContent = Math.round(target * eased) + suffix;
          if (p < 1) requestAnimationFrame(tick);
          else el.textContent = target + suffix;
        })(start);
      });
    }, { threshold: 0.4 });
    for (var k = 0; k < stats.length; k++) sio.observe(stats[k]);
  }

  /* ---------------------------------------------------- double submit --- */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    var btn = form.querySelector('button[type=submit], button:not([type])');
    if (!btn || btn.dataset.busy) return;
    btn.dataset.busy = '1';
    setTimeout(function () { btn.setAttribute('aria-disabled', 'true'); }, 0);
    setTimeout(function () {
      delete btn.dataset.busy;
      btn.removeAttribute('aria-disabled');
    }, 4000);
  });

  /* ------------------------------------------------------- board link --- */
  var board = document.getElementById('boardurl');
  if (board && navigator.clipboard) {
    board.addEventListener('click', function () {
      navigator.clipboard.writeText(board.value).then(function () {
        board.style.borderColor = 'var(--primary)';
        setTimeout(function () { board.style.borderColor = ''; }, 900);
      }, function () { /* selection already happened */ });
    });
  }

  /* ------------------------------------------------------ score entry --- */
  var first = document.querySelectorAll('input[name=s1]');
  for (var s = 0; s < first.length; s++) {
    (function (el) {
      el.addEventListener('input', function () {
        if (el.value.length >= 2) {
          var s2 = el.form && el.form.querySelector('input[name=s2]');
          if (s2) s2.focus();
        }
      });
    })(first[s]);
  }
}());
