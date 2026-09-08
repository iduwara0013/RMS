<?php

return [
    // Use "mock" during development. Change to "sms" after an SMS gateway is integrated.
    'driver' => env('INTERNAL_OTP_DRIVER', 'mock'),
    'mock_code' => env('INTERNAL_OTP_MOCK_CODE', '123456'),
    'expires_minutes' => (int) env('INTERNAL_OTP_EXPIRES_MINUTES', 5),
    'session_hours' => (int) env('INTERNAL_SESSION_HOURS', 8),
];
