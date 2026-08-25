// melytics tracker — cookieless, <1KB gzipped.
// Config via the script tag: data-site (site key), data-api (ingest URL, default /api/echo on same origin).
(function () {
  var d = document, w = window, loc = w.location;
  var s = d.currentScript || d.querySelector('script[data-site]');
  if (!s) return;
  var site = s.getAttribute('data-site');
  var api = s.getAttribute('data-api') || '/api/echo';
  var last;

  function send(extra) {
    // Skip prerender and frames; localhost skipped unless data-dev present.
    if (w.__nomel || (!s.hasAttribute('data-dev') && /^(localhost|127\.|0\.0\.0\.0)/.test(loc.hostname))) return;
    var url = loc.href;
    if (url === last && !extra) return;
    last = url;
    var p = {
      k: site,
      u: url,
      r: d.referrer || null,
      w: w.innerWidth,
      z: Intl.DateTimeFormat().resolvedOptions().timeZone || null
    };
    if (extra) for (var k in extra) p[k] = extra[k];
    try {
      fetch(api, {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(p)
      });
    } catch (e) {}
  }

  // Custom events: melytics.track('signup', {plan:'pro'})
  w.melytics = {
    track: function (name, props) { send({ e: name, p: props || null }); },
    consent: function (ok) { w.__melc = !!ok; } // tier-2 gate, used by later features
  };

  // SPA route changes
  var push = history.pushState;
  if (push) {
    history.pushState = function () { push.apply(this, arguments); send(); };
    w.addEventListener('popstate', function () { send(); });
  }

  // Initial pageview (after paint so we never block)
  if (d.visibilityState === 'prerender') {
    d.addEventListener('visibilitychange', function f() {
      if (d.visibilityState === 'visible') { d.removeEventListener('visibilitychange', f); send(); }
    });
  } else send();
})();
