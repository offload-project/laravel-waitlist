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
    | Mailing List Integration
    |--------------------------------------------------------------------------
    |
    | Push waitlist entries to a newsletter service such as Mailchimp, Kit
    | or Audienceful.
    | Connect a waitlist to a list with:
    |
    |     Waitlist::for('beta')->connectMailingList('audience-id');
    |
    | Entries are subscribed automatically: on sign up, or — when email
    | verification is enabled — once the address has been verified.
    |
    */
    'mailing_list' => [
        'enabled' => env('WAITLIST_MAILING_LIST_ENABLED', false),

        /*
        | The driver used by waitlists that have not picked one themselves.
        | Ships with: mailchimp, kit, audienceful, log, array.
        */
        'default' => env('WAITLIST_MAILING_LIST_DRIVER', 'log'),

        /*
        | Subscribe entries automatically. Turn this off to sync by hand with
        | Waitlist::subscribeToMailingList($entry) or by listening for events.
        */
        'auto_subscribe' => true,

        /*
        | Send new contacts a confirmation email before subscribing them.
        | Honoured by Mailchimp and Audienceful — Kit uses the opt-in setting
        | of the form itself.
        */
        'double_optin' => env('WAITLIST_MAILING_LIST_DOUBLE_OPTIN', false),

        /*
        | Tags applied to every subscriber the package creates.
        */
        'tags' => [],

        /*
        | A closure mapping an entry to provider fields (Mailchimp merge
        | fields, Kit or Audienceful custom fields). Note that a config file
        | containing a closure cannot be cached with `php artisan config:cache`.
        | Example: fn(WaitlistEntry $entry) => ['SOURCE' => $entry->metadata['source'] ?? null]
        */
        'attributes' => null,

        /*
        | Syncing happens in a queued job so sign ups never wait on the API.
        */
        'queue' => [
            'enabled' => true,
            'connection' => env('WAITLIST_MAILING_LIST_QUEUE_CONNECTION'),
            'queue' => env('WAITLIST_MAILING_LIST_QUEUE'),
        ],

        /*
        | HTTP timeout in seconds, and how many times a failed request is
        | retried before the job itself is retried.
        */
        'timeout' => 10,
        'retries' => 2,

        'drivers' => [
            'mailchimp' => [
                'key' => env('MAILCHIMP_API_KEY'),
                // Data centre prefix, e.g. "us14". Derived from the key when null.
                'server' => env('MAILCHIMP_SERVER_PREFIX'),
                // Fallback audience id for waitlists with no list of their own.
                'list_id' => env('MAILCHIMP_LIST_ID'),
            ],

            'kit' => [
                'key' => env('KIT_API_KEY'),
                // Whether a list id refers to a Kit "form" or a "tag".
                'list_type' => env('KIT_LIST_TYPE', 'form'),
                'list_id' => env('KIT_FORM_ID'),
            ],

            'audienceful' => [
                'key' => env('AUDIENCEFUL_API_KEY'),
                // Whether a list id refers to an Audienceful "publication" or a "tag".
                'list_type' => env('AUDIENCEFUL_LIST_TYPE', 'publication'),
                'list_id' => env('AUDIENCEFUL_PUBLICATION_ID'),
            ],

            'log' => [
                'channel' => env('WAITLIST_MAILING_LIST_LOG_CHANNEL'),
                'list_id' => 'log',
            ],

            // Used by MailingList::fake(), which sets its own list id.
            'array' => [],
        ],
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
