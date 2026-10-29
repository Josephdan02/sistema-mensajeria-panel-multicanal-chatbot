<?php

return [

    'clinic_agenda' => [
        'driver' => env('AGENDA_SERVICE', 'mock'),
    ],

    // WhatsApp Cloud API (Meta). `driver` = 'mock' solo loguea; 'api' envía
    // de verdad a graph.facebook.com. Mismo patrón que AGENDA_SERVICE.
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'mock'),
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'timeout' => env('WHATSAPP_TIMEOUT', 10),
    ],

    'telegram' => [
        'driver' => env('TELEGRAM_DRIVER', 'api'),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org/bot'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'timeout' => env('TELEGRAM_TIMEOUT', 10),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    /*
    | Supabase (PostgreSQL + Storage privado) para los adjuntos de los canales
    | externos. `url` es la URL del proyecto, `service_role_key` es la clave de
    | servidor: NUNCA debe salir del backend (ni al navegador, ni a los logs).
    | El bucket debe ser privado; el acceso se hace con URLs firmadas de corta
    | duración generadas por el servidor.
    */
    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
        'bucket' => env('SUPABASE_STORAGE_BUCKET', 'chatbot-attachments'),
        'signed_url_ttl' => (int) env('SUPABASE_SIGNED_URL_TTL', 120),
        'timeout' => (int) env('SUPABASE_TIMEOUT', 30),
    ],

    'clinic_api' => [
        'url' => env('CLINIC_API_URL'),
        'key' => env('CLINIC_API_KEY'),
        'timeout' => env('CLINIC_API_TIMEOUT', 10),
    ],

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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

];
