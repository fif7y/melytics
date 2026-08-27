<?php

namespace App\Http\Controllers;

use App\Mail\ResetPassword;
use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        abort_unless(config('melytics.registration'), 403, 'Registration is closed on this instance.');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($data);
        $this->sendVerification($user);

        return response()->json([
            'token' => $user->createToken('dashboard')->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'verified' => false],
        ], 201);
    }

    private function sendVerification(User $user): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addDay(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
        Mail::to($user->email)->send(new VerifyEmail($user, $url));
    }

    public function verify(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->email), $hash), 403);
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect(config('app.url').'/#/?verified=1');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if((bool) $user->email_verified_at, 400, 'Already verified.');
        $this->sendVerification($user);

        return response()->json(['ok' => true]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $token = Password::broker()->createToken($user);
            $url = config('app.url').'/#/reset?token='.$token.'&email='.urlencode($user->email);
            Mail::to($user->email)->send(new ResetPassword($user, $url));
        }

        // Same response either way — don't leak which emails exist
        return response()->json(['ok' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete(); // sign out everywhere
        });
        abort_unless($status === Password::PASSWORD_RESET, 422, __($status));

        return response()->json(['ok' => true]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        return response()->json([
            'token' => $user->createToken('dashboard')->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'verified' => (bool) $user->email_verified_at],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'verified' => (bool) $user->email_verified_at,
            // Instance health, surfaced as dashboard banners/hints.
            'cron_stale' => $cronStale = \App\Support\RollupHeartbeat::cronStale(300),
            'cron_line' => $cronStale
                ? (is_file(base_path('cron.sh'))
                    ? '/bin/sh '.base_path('cron.sh')
                    : 'cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1')
                : null,
            'mail_off' => config('mail.default') === 'log',
            'is_admin' => $user->id === User::min('id'),
            'version' => \App\Support\Version::current(),
            'update' => \App\Support\Version::updateAvailable(),
        ]);
    }
}
