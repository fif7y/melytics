// melytics tracker — cookieless, <1KB gzipped.
// Config via the script tag: data-site (site key), data-api (ingest URL, default /api/echo on same origin).
(function () {
  var d = document, w = window, loc = w.location;
  var s = d.currentScript || d.querySelector('script[data-site]');
  if (!s) return;
  var site = s.getAttribute('data-site');
  var api = s.getAttribute('data-api') || '/api/echo';
  var last, mid;

  // Tier-2: persistent id only after melytics.consent(true); survives via localStorage
  try { if (localStorage.__mlc) mid = localStorage.__mlid; } catch (e) {}

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
    if (mid) p.i = mid;
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
    consent: function (ok) { // tier-2 gate: grants/revokes the persistent id
      try {
        if (ok) {
          localStorage.__mlc = 1;
          mid = localStorage.__mlid || (localStorage.__mlid = Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
        } else {
          delete localStorage.__mlc;
          delete localStorage.__mlid;
          mid = 0;
        }
      } catch (e) {}
    }
  };

  // SPA route changes
  var push = history.pushState;
  if (push) {
    history.pushState = function () { push.apply(this, arguments); send(); };
    w.addEventListener('popstate', function () { send(); });
  }

  // Web Vitals (LCP, CLS, INP, TTFB) — one '__vitals' event when the page hides
  if (w.PerformanceObserver) {
    var lcp, cls = 0, inp = 0, vsent;
    var po = function (type, cb, opts) {
      try {
        var o = new PerformanceObserver(cb);
        opts = opts || {};
        opts.type = type;
        opts.buffered = true;
        o.observe(opts);
      } catch (e) {}
    };
    po('largest-contentful-paint', function (l) {
      var es = l.getEntries();
      if (es.length) lcp = es[es.length - 1].startTime;
    });
    po('layout-shift', function (l) {
      l.getEntries().forEach(function (e) { if (!e.hadRecentInput) cls += e.value; });
    });
    po('event', function (l) {
      l.getEntries().forEach(function (e) { if (e.duration > inp) inp = e.duration; });
    }, { durationThreshold: 40 });
    d.addEventListener('visibilitychange', function () {
      if (d.visibilityState !== 'hidden' || vsent || lcp == null) return;
      vsent = 1;
      var nav = performance.getEntriesByType('navigation')[0];
      send({ e: '__vitals', p: {
        lcp: Math.round(lcp),
        cls: Math.round(cls * 1000) / 1000,
        inp: Math.round(inp),
        ttfb: nav ? Math.round(nav.responseStart) : null
      } });
    });
  }

  // Heartbeat: keeps "Live now" honest while a visible tab idles on one page
  setInterval(function () {
    if (d.visibilityState === 'visible') send({ e: '__ping' });
  }, 120000);

  // Initial pageview (after paint so we never block)
  if (d.visibilityState === 'prerender') {
    d.addEventListener('visibilitychange', function f() {
      if (d.visibilityState === 'visible') { d.removeEventListener('visibilitychange', f); send(); }
    });
  } else send();
})();
