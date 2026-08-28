<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InstallController extends Controller
{
    // Registered middleware-less (routes/install.php): must work before an
    // APP_KEY or database exists, so no session, cookies, or CSRF here.
    public static function installed(): bool
    {
        if (file_exists(storage_path('installed.lock'))) {
            return true;
        }
        try {
            return Schema::hasTable('users') && User::query()->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    // A random secret written to storage/ on first visit to the install page.
    // Completing setup requires echoing it back, so only someone with file-system
    // access (i.e. the person who uploaded melytics) can claim the admin account —
    // not whoever reaches a freshly-uploaded public instance first. Deleted once
    // installed. Read via the hosting panel's file manager.
    private function installToken(): string
    {
        $path = storage_path('install-token.txt');
        if (! is_file($path) || trim((string) @file_get_contents($path)) === '') {
            @file_put_contents($path, $token = Str::random(40));
            @chmod($path, 0600);

            return $token;
        }

        return trim((string) file_get_contents($path));
    }

    // Loopback/private installs are already unreachable by an outsider, so show
    // the token inline there (frictionless dev/local setup) rather than making
    // the operator go read the file.
    private function tokenIsSafeToShow(Request $request): bool
    {
        return in_array($request->ip(), ['127.0.0.1', '::1'], true)
            || in_array($request->getHost(), ['localhost', '127.0.0.1'], true);
    }

    public function show(Request $request)
    {
        if (self::installed()) {
            return redirect('/');
        }

        return $this->form($request);
    }

    private function form(Request $request, ?string $error = null, array $old = [], int $status = 200)
    {
        $token = $this->installToken();

        return response()->view('install', [
            'checks' => $this->checks(),
            'done' => false,
            'error' => $error,
            'old' => $old,
            'timezones' => $this->timezones(),
            'shownToken' => $this->tokenIsSafeToShow($request) ? $token : null,
        ], $status);
    }

    // ['America/Toronto' => 'America/Toronto (UTC−4)', …] — current offset, DST included.
    private function timezones(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out = [];
        foreach (\DateTimeZone::listIdentifiers() as $tz) {
            $sec = $now->setTimezone(new \DateTimeZone($tz))->getOffset();
            $h = intdiv(abs($sec), 3600);
            $m = intdiv(abs($sec) % 3600, 60);
            $off = $sec === 0 ? '±0' : ($sec < 0 ? '−' : '+').$h.($m ? ':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
            $out[$tz] = "{$tz} (UTC{$off})";
        }

        return $out;
    }

    public function perform(Request $request)
    {
        if (self::installed()) {
            return redirect('/');
        }
        abort_unless(collect($this->checks())->every('ok'), 422, 'Server requirements not met.');

        // Setup-token gate: only someone who can read storage/install-token.txt
        // may create the admin account (see installToken()).
        if (! hash_equals($this->installToken(), (string) $request->input('setup_token'))) {
            return $this->form(
                $request,
                'That setup code doesn\'t match. Open storage/install-token.txt in your hosting file manager and paste the code exactly.',
                $request->only(['email', 'site_name', 'domain']),
                403,
            );
        }

        // No session on this route — render validation errors directly.
        try {
            $data = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:8',
                'site_name' => 'required|string|max:80',
                'domain' => 'required|string|max:190',
                'timezone' => 'nullable|timezone',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->form(
                $request,
                collect($e->errors())->flatten()->first(),
                $request->only(['email', 'site_name', 'domain']),
                422,
            );
        }

        try {
            $this->writeEnv($request);

            $db = database_path('database.sqlite');
            if (! file_exists($db)) {
                touch($db);
            }
            Artisan::call('migrate', ['--force' => true]);

            $user = User::forceCreate([
                'name' => 'Admin',
                'email' => $data['email'],
                'password' => $data['password'],
                'email_verified_at' => now(),
            ]);
            $site = $user->sites()->create([
                'name' => $data['site_name'],
                'domain' => preg_replace('#^https?://#', '', rtrim(trim($data['domain']), '/')),
                'timezone' => $data['timezone'] ?: 'UTC',
            ]);

            file_put_contents(storage_path('installed.lock'), now()->toIso8601String());
            @unlink(storage_path('install-token.txt')); // one-time secret, spent
        } catch (\Throwable $e) {
            report($e);

            return $this->form(
                $request,
                'Install hit a server error: "'.$e->getMessage().'" — nothing you did wrong. '
                .'Fix the cause (or ask your host), then reload this page and try again.',
                $request->only(['email', 'site_name', 'domain']),
                500,
            );
        }

        return view('install', [
            'done' => true,
            'site' => $site,
            'origin' => $request->getSchemeAndHttpHost(),
            'basePath' => base_path(),
            // Release installs ship cron.sh — inline cd-&&-artisan lines are
            // silently ignored by some shared hosts' cron (Hostinger et al.).
            'cronLine' => is_file(base_path('cron.sh'))
                ? '/bin/sh '.base_path('cron.sh')
                : 'cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
        ]);
    }

    private function checks(): array
    {
        $perms = 'In your panel\'s file manager, give this folder write permission (chmod 755 or 775).';

        return [
            ['label' => 'PHP 8.2 or newer', 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION,
                'fix' => 'Pick PHP 8.2 or newer in your hosting panel — usually under "Select PHP Version" or "PHP Configuration" — then reload this page.'],
            ['label' => 'SQLite driver (pdo_sqlite)', 'ok' => extension_loaded('pdo_sqlite'), 'detail' => null,
                'fix' => 'Enable the pdo_sqlite extension in your panel\'s PHP settings (same screen as the PHP version), then reload.'],
            ['label' => 'storage/ writable', 'ok' => is_writable(storage_path()), 'detail' => null, 'fix' => $perms],
            ['label' => 'bootstrap/cache/ writable', 'ok' => is_writable(base_path('bootstrap/cache')), 'detail' => null, 'fix' => $perms],
            ['label' => 'database/ writable', 'ok' => is_writable(database_path()), 'detail' => null, 'fix' => $perms],
            ['label' => '.env writable', 'ok' => is_writable(base_path('.env')) || is_writable(base_path()), 'detail' => null,
                'fix' => 'In your panel\'s file manager, make the .env file in the melytics folder writable (chmod 644 and owned by the web user).'],
        ];
    }

    private function writeEnv(Request $request): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            file_put_contents($path, "APP_NAME=melytics\nAPP_ENV=production\nAPP_DEBUG=false\nLOG_CHANNEL=daily\nDB_CONNECTION=sqlite\nSESSION_DRIVER=file\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\nMAIL_MAILER=log\n");
        }
        $key = 'base64:'.base64_encode(random_bytes(32));
        $values = [
            'APP_KEY' => $key,
            'APP_URL' => $request->getSchemeAndHttpHost(),
            // Only mark cookies Secure when installed over HTTPS — forcing it on an
            // http install would stop them being set at all.
            'SESSION_SECURE_COOKIE' => $request->isSecure() ? 'true' : 'false',
        ];
        $host = preg_replace('/^(www|stats|analytics)\./', '', $request->getHost());
        if (! in_array($host, ['localhost', '127.0.0.1'])) {
            $values['MAIL_FROM_ADDRESS'] = 'stats@'.$host;
        }
        \App\Support\EnvFile::set($values);
        config(['app.key' => $key]);
    }
}
