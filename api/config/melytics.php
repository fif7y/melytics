<?php

return [
    // Open self-serve registration. Off by default: a personal instance stays
    // invite-only (accounts via melytics:make-user); a distribution install
    // sets MELYTICS_REGISTRATION=true.
    'registration' => env('MELYTICS_REGISTRATION', false),

    // "Continue with Google" on the sign-in screen. Optional: create an OAuth
    // client at console.cloud.google.com (redirect URI: <APP_URL>/api/auth/google/callback)
    // and set both vars; the button appears automatically once they're present.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],
];
