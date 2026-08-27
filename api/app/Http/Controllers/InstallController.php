<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

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

    public function show()
    {
        if (self::installed()) {
            return redirect('/');
        }

        return $this->form();
    }

    private function form(?string $error = null, array $old = [], int $status = 200)
    {
        return response()->view('install', [
            'checks' => $this->checks(),
            'done' => false,
            'error' => $error,
            'old' => $old,
            'timezones' => $this->timezones(),
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
        } catch (\Throwable $e) {
            report($e);

            return $this->form(
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
        $values = ['APP_KEY' => $key, 'APP_URL' => $request->getSchemeAndHttpHost()];
        $host = preg_replace('/^(www|stats|analytics)\./', '', $request->getHost());
        if (! in_array($host, ['localhost', '127.0.0.1'])) {
            $values['MAIL_FROM_ADDRESS'] = 'stats@'.$host;
        }
        \App\Support\EnvFile::set($values);
        config(['app.key' => $key]);
    }
}
