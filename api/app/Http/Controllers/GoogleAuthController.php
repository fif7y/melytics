<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Plain OAuth2 (no Socialite — its Guzzle constraint conflicts with ours).
// Token-auth SPA: state rides a signed cookie-less nonce in the URL is overkill
// here; we use Google's recommended `state` echo with a short-lived cache entry.
class GoogleAuthController extends Controller
{
    public static function enabled(): bool
    {
        return (bool) (config('melytics.google.client_id') && config('melytics.google.client_secret'));
    }

    public function redirect(Request $request)
    {
        abort_unless(self::enabled(), 404);

        $state = Str::random(40);
        cache()->put('google_oauth_state:'.$state, true, now()->addMinutes(10));

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('melytics.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request)
    {
        abort_unless(self::enabled(), 404);

        $state = (string) $request->query('state');
        if (! $request->query('code') || ! cache()->pull('google_oauth_state:'.$state)) {
            return $this->toApp(['google_error' => 'Sign-in was cancelled or expired — try again.']);
        }

        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('melytics.google.client_id'),
            'client_secret' => config('melytics.google.client_secret'),
            'code' => $request->query('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);
        if (! $token->ok()) {
            return $this->toApp(['google_error' => 'Google rejected the sign-in — check the instance\'s OAuth credentials.']);
        }

        $info = Http::withToken($token->json('access_token'))
            ->get('https://openidconnect.googleapis.com/v1/userinfo');
        $email = $info->json('email');
        if (! $email || ! $info->json('email_verified')) {
            return $this->toApp(['google_error' => 'Google didn\'t return a verified email address.']);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            if (! config('melytics.registration')) {
                return $this->toApp(['google_error' => 'No account for '.$email.' — registration is closed on this instance.']);
            }
            $user = User::forceCreate([
                'name' => $info->json('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(40), // unusable; they can set one via reset
                'email_verified_at' => now(),   // Google already verified it
            ]);
        } elseif (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->toApp(['token' => $user->createToken('dashboard')->plainTextToken]);
    }

    // In-app setup (Account → Google sign-in): the first account (the install
    // admin) may persist or clear the OAuth client credentials — no .env editing.
    public function saveSettings(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'client_id' => 'required|string|max:200|regex:/\.apps\.googleusercontent\.com$/',
            'client_secret' => 'required|string|max:200',
        ]);

        \App\Support\EnvFile::set([
            'GOOGLE_CLIENT_ID' => $data['client_id'],
            'GOOGLE_CLIENT_SECRET' => $data['client_secret'],
        ]);
        config(['melytics.google' => ['client_id' => $data['client_id'], 'client_secret' => $data['client_secret']]]);

        return response()->json(['google' => true]);
    }

    public function removeSettings(Request $request)
    {
        $this->assertAdmin($request);
        \App\Support\EnvFile::set(['GOOGLE_CLIENT_ID' => null, 'GOOGLE_CLIENT_SECRET' => null]);
        config(['melytics.google' => ['client_id' => null, 'client_secret' => null]]);

        return response()->json(['google' => false]);
    }

    private function assertAdmin(Request $request): void
    {
        // No roles yet: the first account (created by the installer) is the admin.
        abort_unless($request->user()->id === User::min('id'), 403, 'Only the admin account can change sign-in settings.');
    }

    private function redirectUri(): string
    {
        return url('/api/auth/google/callback');
    }

    // Hash-router SPA: params must live inside the fragment for the login view.
    private function toApp(array $params)
    {
        $base = file_exists(public_path('app/index.html')) ? url('/app/') : rtrim(config('app.url'), '/').'/';

        return redirect($base.'#/login?'.http_build_query($params));
    }
}
