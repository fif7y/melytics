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

    // Behind a CDN/reverse proxy, PHP sees the edge IP, so the visitor hash
    // collapses onto a few proxy IPs (breaks uniqueness). Set this to the header
    // your proxy fills with the real client IP (e.g. CF-Connecting-IP on
    // Cloudflare, X-Real-IP on nginx) to restore it. IMPORTANT: only set this
    // when the origin is locked to the proxy — otherwise a direct-to-origin
    // request can spoof the header. Left unset, we use the connecting IP as-is.
    'ip_header' => env('MELYTICS_IP_HEADER'),
];
