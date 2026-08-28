<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EnvFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

// Email delivery (Settings → Email): shared hosts' sendmail often lands in
// spam, so the admin can point melytics at any SMTP provider (Resend,
// Postmark, Brevo, …) with a guided in-app setup — no .env editing.
class MailSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return $this->status();
    }

    public function save(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'from_address' => 'required|email|max:255',
        ]);

        EnvFile::set([
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $data['host'],
            'MAIL_PORT' => (string) $data['port'],
            // 465 is implicit TLS ("smtps"); other ports negotiate STARTTLS.
            'MAIL_SCHEME' => (int) $data['port'] === 465 ? 'smtps' : null,
            'MAIL_USERNAME' => $data['username'],
            'MAIL_PASSWORD' => $data['password'],
            'MAIL_FROM_ADDRESS' => $data['from_address'],
        ]);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $data['host'],
            'mail.mailers.smtp.port' => (int) $data['port'],
            'mail.mailers.smtp.scheme' => (int) $data['port'] === 465 ? 'smtps' : null,
            'mail.mailers.smtp.username' => $data['username'],
            'mail.mailers.smtp.password' => $data['password'],
            'mail.from.address' => $data['from_address'],
        ]);

        return $this->status();
    }

    // "Turn off" = back to the host's sendmail (the pre-SMTP default), never
    // to 'log' — that would silently drop password resets too.
    public function remove(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        EnvFile::set([
            'MAIL_MAILER' => 'sendmail',
            'MAIL_HOST' => null,
            'MAIL_PORT' => null,
            'MAIL_SCHEME' => null,
            'MAIL_USERNAME' => null,
            'MAIL_PASSWORD' => null,
        ]);
        config(['mail.default' => 'sendmail']);

        return $this->status();
    }

    public function test(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $to = $request->user()->email;
        try {
            Mail::raw(
                "This is melytics confirming your email delivery works.\n\nDigests, alerts and password resets will go out this way.",
                fn ($m) => $m->to($to)->subject('melytics email test')
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Send failed: '.$e->getMessage()], 422);
        }

        return response()->json(['sent' => $to]);
    }

    private function status(): JsonResponse
    {
        $mailer = config('mail.default');

        return response()->json([
            'mailer' => $mailer,
            'host' => $mailer === 'smtp' ? config('mail.mailers.smtp.host') : null,
            'from' => config('mail.from.address'),
        ]);
    }

    private function assertAdmin(Request $request): void
    {
        // No roles yet: the first account (created by the installer) is the admin.
        abort_unless($request->user()->id === User::min('id'), 403, 'Only the admin account can change email settings.');
    }
}
