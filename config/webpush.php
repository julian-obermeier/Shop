<?php

return [
    'subject'=>env('PUSH_VAPID_SUBJECT',env('APP_URL')),
    'public_key'=>env('PUSH_VAPID_PUBLIC_KEY'),
    'private_key'=>env('PUSH_VAPID_PRIVATE_KEY'),
];
