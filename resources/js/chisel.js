/* Chisel: follows a run's output while it runs (Chisel → History → a run). Loaded on every
   CP page by Craft; does nothing unless the page has a [data-chisel-run] element. */
(function () {
  'use strict';

  function follow(el) {
    el.setAttribute('data-chisel-bound', '1');
    if (el.getAttribute('data-finished') === '1') return;

    var url = el.getAttribute('data-poll-url');
    var length = parseInt(el.getAttribute('data-length') || '0', 10);
    var byId = function (id) { return el.querySelector('#' + id); };
    var delay = 700;

    function tick() {
      if (!document.body.contains(el)) return; // navigated away
      fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'length=' + length, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          var out = byId('chisel-output');
          if (d.output !== null && out) {
            var atBottom = out.scrollTop + out.clientHeight >= out.scrollHeight - 8;
            out.textContent = d.output;
            if (atBottom) out.scrollTop = out.scrollHeight;
          }
          length = d.length;
          var status = byId('chisel-status');
          if (status) {
            status.textContent = d.statusLabel;
            status.className = 'chisel-status chisel-status--' + d.status;
          }
          if (byId('chisel-exit-code')) byId('chisel-exit-code').textContent = d.exitCode === null ? '—' : String(d.exitCode);
          if (byId('chisel-duration') && d.durationMs !== null) byId('chisel-duration').textContent = (d.durationMs / 1000).toFixed(2) + ' s';
          if (byId('chisel-waiting')) byId('chisel-waiting').hidden = d.status !== 'pending';
          if (byId('chisel-truncated')) byId('chisel-truncated').hidden = !d.truncated;
          if (byId('chisel-error')) {
            byId('chisel-error').textContent = d.error || '';
            byId('chisel-error').hidden = !d.error;
          }
          if (d.finished) {
            el.setAttribute('data-finished', '1');
            return;
          }
          delay = Math.min(delay + 150, 2500);
          setTimeout(tick, delay);
        })
        .catch(function () { setTimeout(tick, 5000); });
    }

    setTimeout(tick, 300);
  }

  function scan() {
    var els = document.querySelectorAll('[data-chisel-run]:not([data-chisel-bound])');
    for (var i = 0; i < els.length; i++) follow(els[i]);
  }

  // The CP renders with Vue/Inertia after this script runs, and navigates client-side.
  setInterval(scan, 500);
  document.addEventListener('inertia:navigate', function () { setTimeout(scan, 50); });
})();
