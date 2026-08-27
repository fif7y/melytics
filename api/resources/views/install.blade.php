<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install melytics</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
      --bg: #fcfcfb; --surface: #f4f4f1; --ink: #0b0b0b; --ink-2: #52514e; --ink-3: #8a8984;
      --accent: #2a78d6; --accent-soft: rgba(42, 120, 214, 0.12);
      --up: #008300; --down: #e34948;
      --shadow: 0 1px 2px rgba(0,0,0,.04), 0 4px 16px rgba(0,0,0,.05);
      --display: 'Space Grotesk', ui-sans-serif, system-ui, sans-serif;
      --ease: cubic-bezier(0.16, 1, 0.3, 1);
    }
    @media (prefers-color-scheme: dark) {
      :root {
        color-scheme: dark;
        --bg: #131312; --surface: #1e1e1c; --ink: #f4f4ef; --ink-2: #c3c2b7; --ink-3: #7d7c75;
        --accent: #3987e5; --accent-soft: rgba(57, 135, 229, 0.16);
        --up: #35a55a; --down: #e66767;
        --shadow: 0 1px 2px rgba(0,0,0,.3), 0 4px 16px rgba(0,0,0,.25);
      }
    }
    * { box-sizing: border-box; margin: 0; }
    ::selection { background: var(--accent); color: #fff; }
    body {
      background: var(--bg); color: var(--ink); min-height: 100vh;
      font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
      -webkit-font-smoothing: antialiased;
      display: flex; align-items: center; justify-content: center; padding: 3rem 1.25rem 4rem;
    }
    .wrap { width: 100%; max-width: 30rem; }

    /* — Hero: typography is the design — */
    .brand {
      font-family: var(--display); font-weight: 500; font-size: .8rem;
      letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3);
    }
    .brand b { color: var(--ink); font-weight: 700; }
    h1 {
      font-family: var(--display); font-weight: 700;
      font-size: clamp(2.6rem, 8vw, 4.2rem); line-height: .98; letter-spacing: -0.035em;
      margin: .9rem 0 0; text-wrap: balance;
    }
    h1 .a { color: var(--accent); }
    .lede { color: var(--ink-2); font-size: .95rem; line-height: 1.55; margin: 1.1rem 0 2.2rem; max-width: 24rem; }

    /* — Card: de-boxed, fill + shadow — */
    .card { background: var(--surface); border-radius: 16px; box-shadow: var(--shadow); padding: 1.9rem 1.9rem 2rem; }

    .section {
      font-family: var(--display); font-size: .68rem; font-weight: 500;
      text-transform: uppercase; letter-spacing: .13em; color: var(--ink-3); margin: 1.7rem 0 .7rem;
    }
    .card > .section:first-child { margin-top: 0; }

    ul.checks { list-style: none; font-size: .85rem; }
    ul.checks li { padding: .32rem 0; color: var(--ink-2); }
    ul.checks li .row { display: flex; justify-content: space-between; gap: 1rem; }
    .ok { color: var(--up); } .fail { color: var(--down); font-weight: 600; }
    .fixhint { font-size: .76rem; color: var(--ink-2); background: var(--accent-soft); border-radius: 8px; padding: .5rem .65rem; margin-top: .35rem; line-height: 1.5; }

    label { display: block; font-size: .8rem; color: var(--ink-2); margin: .95rem 0 .35rem; }
    label .hint { color: var(--ink-3); }
    .fieldnote { font-size: .74rem; color: var(--ink-3); line-height: 1.5; margin-top: .4rem; padding: 0 .1rem; }
    input, select {
      width: 100%; padding: .6rem .75rem; font-size: .9rem; color: var(--ink);
      background: var(--bg); border: none; border-radius: 9px; outline: none;
      transition: box-shadow .25s var(--ease);
    }
    input:focus-visible, select:focus-visible { box-shadow: 0 0 0 2px var(--accent); }
    select {
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%238a8984' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right .8rem center; padding-right: 2.2rem;
    }
    .pw { position: relative; }
    .pw input { padding-right: 3.6rem; }
    .pw-toggle {
      position: absolute; right: .35rem; top: 50%; transform: translateY(-50%);
      padding: .28rem .55rem; font-size: .72rem; font-weight: 500; font-family: inherit;
      color: var(--ink-3); background: transparent; border: none; border-radius: 6px; cursor: pointer;
      transition: color .2s var(--ease), background .2s var(--ease);
    }
    .pw-toggle:hover { color: var(--ink); background: var(--accent-soft); }

    button[type=submit], a.go {
      display: block; width: 100%; margin-top: 1.6rem; padding: .78rem; text-align: center;
      font-family: var(--display); font-size: .95rem; font-weight: 500; letter-spacing: .01em;
      color: #fff; background: var(--accent); border: none; border-radius: 10px; cursor: pointer;
      text-decoration: none;
      transition: transform .35s var(--ease), box-shadow .35s var(--ease), opacity .2s;
    }
    button[type=submit]:hover:not(:disabled), a.go:hover { transform: translateY(-2px); box-shadow: 0 6px 20px color-mix(in srgb, var(--accent) 35%, transparent); }
    button[type=submit]:focus-visible, a.go:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
    button[type=submit]:disabled { opacity: .4; cursor: default; }

    .err { color: var(--down); font-size: .8rem; margin-top: 1rem; line-height: 1.5; }
    .note { font-size: .78rem; color: var(--ink-2); background: var(--accent-soft); border-radius: 8px; padding: .55rem .7rem; margin-top: 1rem; line-height: 1.55; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .78em; }
    p { font-size: .86rem; color: var(--ink-2); line-height: 1.55; }
    a { color: var(--accent); }

    /* — Success: numbered steps with ghost numerals, snippet as artifact — */
    .step { position: relative; padding-left: 3.1rem; margin-top: 1.9rem; }
    .step .n {
      position: absolute; left: 0; top: -.35rem;
      font-family: var(--display); font-weight: 700; font-size: 2rem; line-height: 1;
      color: color-mix(in srgb, var(--ink) 14%, transparent); letter-spacing: -0.03em;
      font-variant-numeric: tabular-nums;
    }
    .step h2 { font-family: var(--display); font-size: .95rem; font-weight: 500; margin-bottom: .35rem; }
    .copyblock { position: relative; margin-top: .6rem; }
    pre {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem;
      background: var(--bg); border-radius: 9px; padding: .75rem 4.4rem .75rem .85rem;
      overflow-x: auto; line-height: 1.55; user-select: all;
    }
    .copybtn {
      position: absolute; right: .4rem; top: .4rem;
      padding: .3rem .6rem; font-size: .7rem; font-weight: 500; font-family: inherit;
      color: var(--ink-2); background: var(--surface); border: none; border-radius: 6px; cursor: pointer;
      box-shadow: var(--shadow);
      transition: color .2s var(--ease), transform .2s var(--ease);
    }
    .copybtn:hover { color: var(--ink); transform: translateY(-1px); }
    .copybtn.done { color: var(--up); }

    /* — Load choreography: rise + fade, staggered — */
    @media (prefers-reduced-motion: no-preference) {
      .rise { opacity: 0; transform: translateY(18px); animation: rise .9s var(--ease) forwards; }
      .rise.d1 { animation-delay: .08s } .rise.d2 { animation-delay: .18s }
      .rise.d3 { animation-delay: .3s } .rise.d4 { animation-delay: .42s }
      @keyframes rise { to { opacity: 1; transform: none } }
      ul.checks li { opacity: 0; animation: rise .7s var(--ease) forwards; }
      ul.checks li:nth-child(1) { animation-delay: .45s } ul.checks li:nth-child(2) { animation-delay: .51s }
      ul.checks li:nth-child(3) { animation-delay: .57s } ul.checks li:nth-child(4) { animation-delay: .63s }
      ul.checks li:nth-child(5) { animation-delay: .69s } ul.checks li:nth-child(6) { animation-delay: .75s }
    }
  </style>
</head>
<body>
  <main class="wrap">
    @if ($done)
      <header>
        <div class="brand rise"><b>melytics</b> · setup</div>
        <h1 class="rise d1">You’re <span style="color:var(--ink-3)">(almost)</span> <span class="a">live.</span></h1>
        <p class="lede rise d2">Three copy-pastes left — then {{ $site->domain }} has private, cookieless analytics.</p>
      </header>

      <div class="card rise d3">
        <div class="step" style="margin-top:.2rem">
          <span class="n">01</span>
          <h2>Sign in to your dashboard</h2>
          <p><a href="{{ $origin }}/app/">{{ $origin }}/app/</a> — the email and password you just chose.</p>
        </div>

        <div class="step">
          <span class="n">02</span>
          <h2>Add the snippet to {{ $site->domain }}</h2>
          <p>Paste it right before <code>&lt;/body&gt;</code>:</p>
          <div class="copyblock">
            <pre id="snip">&lt;script defer data-site="{{ $site->key }}" data-api="{{ $origin }}/api/echo" src="{{ $origin }}/m.js"&gt;&lt;/script&gt;</pre>
            <button type="button" class="copybtn" data-copy="snip">Copy</button>
          </div>
          <p class="note">On WordPress: Appearance → Theme File Editor → <code>footer.php</code>, or any
          “insert headers &amp; footers” plugin. Site builders (Squarespace, Webflow, Shopify…) have a
          “custom code” / “code injection” setting — paste it in the footer slot.</p>
        </div>

        <div class="step">
          <span class="n">03</span>
          <h2>Add the cron job</h2>
          <p>In your hosting panel’s Cron Jobs, set this to run <strong>every minute</strong> — it keeps stats rolling up:</p>
          <div class="copyblock">
            <pre id="cron">cd {{ $basePath }} &amp;&amp; php artisan schedule:run >> /dev/null 2>&1</pre>
            <button type="button" class="copybtn" data-copy="cron">Copy</button>
          </div>
        </div>

        <div class="section" style="margin-top:2rem">Check it worked</div>
        <p>Paste the snippet, visit your site once, then open the dashboard — you should see
        yourself under <strong>Live now</strong> right away, and the day’s numbers within a
        minute of the cron’s first run. Nothing after a few minutes? Recheck steps 02 and 03.</p>

        <a class="go" href="{{ $origin }}/app/">Open the dashboard</a>
      </div>
      <script>
        document.querySelectorAll('.copybtn').forEach(function (b) {
          b.addEventListener('click', function () {
            navigator.clipboard.writeText(document.getElementById(b.dataset.copy).textContent).then(function () {
              b.textContent = 'Copied'; b.classList.add('done')
              setTimeout(function () { b.textContent = 'Copy'; b.classList.remove('done') }, 1600)
            })
          })
        })
      </script>
    @else
      @php($allOk = collect($checks)->every('ok'))
      <header>
        <div class="brand rise"><b>melytics</b> · setup</div>
        <h1 class="rise d1">Let’s get<br>you <span class="a">live.</span></h1>
        <p class="lede rise d2">A couple of details and your analytics are running — no terminal, no database to create, nothing to configure by hand.</p>
      </header>

      <div class="card rise d3">
        <div class="section">Server check</div>
        <ul class="checks">
          @foreach ($checks as $c)
            <li>
              <div class="row">
                <span>{{ $c['label'] }}@if($c['detail']) <code>({{ $c['detail'] }})</code>@endif</span>
                <span class="{{ $c['ok'] ? 'ok' : 'fail' }}">{{ $c['ok'] ? '✓' : '✗' }}</span>
              </div>
              @unless ($c['ok'])<div class="fixhint">{{ $c['fix'] }}</div>@endunless
            </li>
          @endforeach
        </ul>

        <form method="post" action="/install">
          <div class="section">Set admin login</div>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required value="{{ $old['email'] ?? '' }}">
          <p class="fieldnote">Tip: use your Google address — you can turn on Google sign-in later
          from the dashboard (Account → Sign-in), and this account gets one-click login automatically.</p>
          <label for="password">Password <span class="hint">(8+ characters)</span></label>
          <div class="pw">
            <input id="password" name="password" type="password" minlength="8" required>
            <button type="button" class="pw-toggle" onclick="const p=document.getElementById('password'); p.type=p.type==='password'?'text':'password'; this.textContent=p.type==='password'?'Show':'Hide'">Show</button>
          </div>

          <div class="section">The site to track</div>
          <label for="site_name">Site name</label>
          <input id="site_name" name="site_name" required placeholder="My blog" value="{{ $old['site_name'] ?? '' }}">
          <label for="domain">Domain <span class="hint">— where you’ll paste the snippet</span></label>
          <input id="domain" name="domain" required placeholder="example.com" value="{{ $old['domain'] ?? '' }}">
          <label for="timezone">Timezone <span class="hint">— your daily stats roll over at this midnight</span></label>
          <select id="timezone" name="timezone">
            @foreach ($timezones as $tz => $label)
              <option value="{{ $tz }}">{{ $label }}</option>
            @endforeach
          </select>

          @if (! request()->isSecure() && ! in_array(request()->getHost(), ['localhost', '127.0.0.1']))
            <p class="note">You’re on <code>http://</code> — the snippet and dashboard links will use it too.
            If your domain has HTTPS, reopen this page at <code>https://</code> before installing.</p>
          @endif
          @if ($error)<p class="err">{{ $error }}</p>@endif
          <button type="submit" {{ $allOk ? '' : 'disabled' }}>Install melytics</button>
        </form>
      </div>
      <script>
        try {
          const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
          const sel = document.getElementById('timezone')
          if ([...sel.options].some(o => o.value === tz)) sel.value = tz
        } catch {}
      </script>
    @endif
  </main>
</body>
</html>
