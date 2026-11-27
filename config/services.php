<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firewall' => [
        'ca_bundle' => env('FIREWALL_CA_BUNDLE'),
    ],

    'github' => [
        'token'            => env('GITHUB_TOKEN'),
        'repository'       => env('GITHUB_REPOSITORY', 'tr0uble84/pfcentral'),
        // Set ALLOW_PRERELEASES=true in .env to include pre-releases in the update checker.
        // This overrides the per-instance toggle in Settings and is ideal for staging machines.
        // Leave unset (null) to fall back to the DB setting or the stable-only default.
        'allow_prereleases' => env('ALLOW_PRERELEASES'),  // null = unset (fallback to DB)
    ],

    // CFBTEL VPN for Windows: the GitHub repository whose releases the staff page links to.
    'vpn_windows' => [
        'repo' => env('VPN_WINDOWS_REPO', 'cfbtel/cfbtel-vpn-windows'),
    ],

    'vpn_macos' => [
        'repo' => env('VPN_MACOS_REPO', 'cfbtel/cfbtel-vpn-macos'),
    ],

];
