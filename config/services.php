<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // SAP Business One Service Layer (SAP B1 versi SQL Server). Hanya diisi pada profil central.
    // Setiap CV di Indoguna Group punya instance/database SAP sendiri; nilai di "companies"
    // menimpa nilai bawaan untuk database tersebut, sehingga dua instance bisa berbeda server.
    'sap' => [
        'url' => env('SAP_SL_URL'),
        'username' => env('SAP_USERNAME'),
        'password' => env('SAP_PASSWORD'),
        'verify' => env('SAP_SL_VERIFY_TLS', true),
        'companies' => [
            'sap_solo' => [
                'url' => env('SAP_SOLO_SL_URL'),
                'username' => env('SAP_SOLO_USERNAME'),
                'password' => env('SAP_SOLO_PASSWORD'),
            ],
            'sap_lombok' => [
                'url' => env('SAP_LOMBOK_SL_URL'),
                'username' => env('SAP_LOMBOK_USERNAME'),
                'password' => env('SAP_LOMBOK_PASSWORD'),
            ],
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
