<?php

return [
    // Open self-serve registration. Off by default: a personal instance stays
    // invite-only (accounts via melytics:make-user); a distribution install
    // sets MELYTICS_REGISTRATION=true.
    'registration' => env('MELYTICS_REGISTRATION', false),
];
