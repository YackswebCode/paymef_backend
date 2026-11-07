<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the React application. Password reset emails link here so the
    | SPA can collect the new password and post it to the API.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Paystack
    |--------------------------------------------------------------------------
    |
    | Test/live credentials. The secret key is only ever used server-side; the
    | public key is safe to hand to the browser.
    |
    */

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'merchant_name' => env('PAYSTACK_MERCHANT_NAME', 'PayMef Education Finance'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bytid (identity verification)
    |--------------------------------------------------------------------------
    |
    | BVN/NIN lookups are billed per check, so they only run when the operator
    | enables the provider. The API key never leaves the server.
    |
    */

    'bytid' => [
        'enabled' => (bool) env('BYTID_ENABLED', false),
        'base_url' => env('BYTID_BASE_URL', 'https://api.bytid.com/v1'),
        'api_key' => env('BYTID_API_KEY'),
        'webhook_secret' => env('BYTID_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Partner bank rails
    |--------------------------------------------------------------------------
    |
    | PayMef does not fabricate account numbers. The provider stays "none"
    | until a commercial bank agreement is signed; requests are then recorded
    | as pending instead of returning a made-up NUBAN.
    |
    */

    'partner_bank' => [
        'provider' => env('PARTNER_BANK_PROVIDER', 'none'),
        'display_name' => env('PARTNER_BANK_DISPLAY_NAME', 'Partner Commercial Bank'),
    ],

];
