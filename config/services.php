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

    // A Meta app with Facebook Login; its redirect URI must be {APP_URL}/connect/meta/callback.
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
        // The Verify Token typed into the App Dashboard's webhook setup for {APP_URL}/webhooks/meta.
        // Subscribe the Page object to `feed` and the Instagram object to `comments` there; Pages are
        // subscribed to the app on connect, Instagram rides on its linked Page.
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
    ],

    // The Threads use case has its own app id and secret, apart from the Meta app's.
    'threads' => [
        'app_id' => env('THREADS_APP_ID'),
        'app_secret' => env('THREADS_APP_SECRET'),
    ],

    // A LinkedIn app with the "Sign In with LinkedIn using OpenID Connect" and "Share on LinkedIn" products.
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    ],

    // An X developer app with OAuth 2.0 user authentication ("Read and write"); callback {APP_URL}/connect/x/callback.
    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
    ],

    // A secret sign-in link for app store reviewers, /review-login/{token}, into a demo account. Unset = off.
    'review' => [
        'token' => env('REVIEW_LOGIN_TOKEN'),
    ],

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
