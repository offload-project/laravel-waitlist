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
    | notification when a waitlist entry is marked as invited. This is
    | disabled by default since laravel-invite-only handles notifications.
    |
    */
    'auto_send_invitation' => false,

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

    /*
    |--------------------------------------------------------------------------
    | Invitable Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how invitations are created via laravel-invite-only when
    | a waitlist entry is invited. The invitable model is the entity that
    | users are being invited to (e.g., Team, Organization, Application).
    |
    */
    'invitable' => [
        /*
        | The model class to use as the invitable. When set, all invitations
        | will be associated with this model type.
        | Example: \App\Models\Team::class
        */
        'model' => null,

        /*
        | A closure to resolve the specific invitable instance for each entry.
        | Receives the WaitlistEntry and should return a Model instance or null.
        | Example: fn(WaitlistEntry $entry) => Team::find($entry->metadata['team_id'])
        */
        'resolver' => null,

        /*
        | A closure to map entry data to invitation metadata.
        | Receives the WaitlistEntry and should return an array of metadata.
        | Example: fn(WaitlistEntry $entry) => ['role' => 'member']
        */
        'metadata_mapper' => null,
    ],
];
