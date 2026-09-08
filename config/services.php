<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // قناة إرسال رموز التحقّق (OTP): manual تُرجع الرمز في استجابة
    // request-otp (وضع الإطلاق بلا تكلفة SMS)، وأي قيمة أخرى تُعطّل
    // إرجاعه — عندئذٍ لا يصل الرمز إلا عبر مزوّد الرسائل القادم.
    'otp' => [
        'channel' => env('OTP_CHANNEL', 'manual'),
    ],

    // مزوّد الرسائل القصيرة (Twilio) — التنبيهات ورموز التحقّق.
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
