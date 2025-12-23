<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Waitlist Slug
    |--------------------------------------------------------------------------
    |
    | The slug of the default waitlist to use when no specific waitlist
    | is specified. This waitlist will be created automatically if it
    | doesn't exist.
    |
    */
    'default_slug' => env('WAITLIST_DEFAULT_SLUG', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Waitlist Model
    |--------------------------------------------------------------------------
    |
    | The model class used for waitlist entries. You can customize this
    | if you need to extend the base model with additional functionality.
    |
    */
    'model' => OffloadProject\Waitlist\Models\WaitlistEntry::class,

    /*
    |--------------------------------------------------------------------------
    | Waitlist Table Name
    |--------------------------------------------------------------------------
    |
    | The database table name used for storing waitlist entries.
    |
    */
    'table' => 'waitlist_entries',

    /*
    |--------------------------------------------------------------------------
    | Auto-Send Invitation Notification
    |--------------------------------------------------------------------------
    |
    | When set to true, the package will automatically send an invitation
    | notification when a waitlist entry is marked as invited.
    |
    */
    'auto_send_invitation' => true,

    /*
    |--------------------------------------------------------------------------
    | Notification Class
    |--------------------------------------------------------------------------
    |
    | The notification class that will be sent when a user is invited.
    | You can customize this to use your own notification class.
    |
    */
    'notification' => OffloadProject\Waitlist\Notifications\WaitlistInvited::class,

    /*
    |--------------------------------------------------------------------------
    | Email Verification
    |--------------------------------------------------------------------------
    |
    | These options control email verification for waitlist entries.
    | When enabled, users must verify their email before being invited.
    |
    */
    'verification' => [
        'enabled' => env('WAITLIST_VERIFICATION_ENABLED', false),
        'require_before_invite' => env('WAITLIST_REQUIRE_VERIFICATION', true),
        'notification' => OffloadProject\Waitlist\Notifications\VerifyWaitlistEmail::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Configuration for the package routes. When enabled, the package will
    | register verification routes automatically.
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'waitlist',
        'middleware' => ['web'],
    ],
];
