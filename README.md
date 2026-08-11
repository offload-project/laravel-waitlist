# Laravel Waitlist

[![Latest Version on Packagist](https://img.shields.io/packagist/v/offload-project/laravel-waitlist.svg?style=flat-square)](https://packagist.org/packages/offload-project/laravel-waitlist)
[![Tests](https://img.shields.io/github/actions/workflow/status/offload-project/laravel-waitlist/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/offload-project/laravel-waitlist/actions/workflows/tests.yml)
[![Build](https://img.shields.io/github/actions/workflow/status/offload-project/laravel-waitlist/release.yml?label=build&style=flat-square)](https://github.com/offload-project/laravel-waitlist/actions/workflows/release.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/offload-project/laravel-waitlist.svg?style=flat-square)](https://packagist.org/packages/offload-project/laravel-waitlist)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE.md)

A simple and flexible waitlist package for Laravel applications. Manage multiple waitlists with ease — perfect for beta
programs, product launches, feature access, and more.

This package provides the core functionality without imposing any UI or API structure, giving you complete freedom to
implement your own controllers, views, and API endpoints.

## Features

- **Multiple waitlists** — Create and manage as many waitlists as you need
- **Simple facade API** — Clean, intuitive interface for managing waitlist entries
- **Status tracking** — Pending, invited, and rejected states
- **Email verification** — Optional opt-in verification before inviting users
- **Event-driven notifications** — Automatic invite + verification notifications, fully customizable
- **Mailing list sync** — Push entries to Mailchimp or Kit (ConvertKit), or plug in a driver of your own
- **Events** — Hook into every step of the lifecycle, from sign up to invite
- **Metadata support** — Store custom data with each entry
- **Invite-only integration** — Optional bridge into `offload-project/laravel-invite-only` for token-based flows
- **Type-safe** — Full PHPStan compliance

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
    - [Single Waitlist](#single-waitlist)
    - [Multiple Waitlists](#multiple-waitlists)
- [Usage](#usage)
    - [Full API](#full-api)
    - [Working With the Model](#working-with-the-model)
    - [Custom Controller / Livewire](#custom-controller--livewire)
    - [Custom Notifications](#custom-notifications)
    - [Email Verification](#email-verification)
    - [Mailing List Integration](#mailing-list-integration)
    - [Events](#events)
- [Configuration](#configuration)
- [Database Schema](#database-schema)
- [API Reference](#api-reference)
- [AI Coding Assistant Skill](#ai-coding-assistant-skill)
- [Testing](#testing)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Requirements

- PHP 8.3+
- Laravel 11/12/13

## Installation

```bash
composer require offload-project/laravel-waitlist

php artisan vendor:publish --tag="waitlist-config"
php artisan vendor:publish --tag="waitlist-migrations"
php artisan migrate
```

## Quick Start

### Single Waitlist

If you only need one waitlist, just start using it — a default waitlist is created automatically:

```php
use OffloadProject\Waitlist\Facades\Waitlist;

// Add users to the default waitlist
$entry = Waitlist::add('John Doe', 'john@example.com');

// Invite a user (sends notification automatically)
Waitlist::invite($entry);

// Get statistics
$total = Waitlist::count();
$pending = Waitlist::countPending();
```

### Multiple Waitlists

Create and manage multiple waitlists for different purposes:

```php
use OffloadProject\Waitlist\Facades\Waitlist;

// Create separate waitlists
$beta = Waitlist::create('Beta Program', 'beta', 'Early access to new features');
$launch = Waitlist::create('Product Launch', 'launch', 'Get notified when we launch');
$vip = Waitlist::create('VIP Access', 'vip', 'Premium tier waitlist');

// Add users to specific waitlists
Waitlist::for('beta')->add('John Doe', 'john@example.com');
Waitlist::for('launch')->add('Jane Smith', 'jane@example.com');
Waitlist::for('vip')->add('Bob Wilson', 'bob@example.com');

// Same person can join multiple waitlists
Waitlist::for('beta')->add('Alice Johnson', 'alice@example.com');
Waitlist::for('launch')->add('Alice Johnson', 'alice@example.com');

// Get entries for a specific waitlist
$betaEntries = Waitlist::for('beta')->getPending();
$launchCount = Waitlist::for('launch')->count();

// Invite users from a specific waitlist
$entry = Waitlist::for('beta')->getByEmail('john@example.com');
Waitlist::invite($entry);
```

## Usage

### Full API

```php
use OffloadProject\Waitlist\Facades\Waitlist;

// Create waitlists
$beta = Waitlist::create('Beta Program', 'beta', 'Description');
$waitlist = Waitlist::find('beta'); // Find by slug

// Add users
$entry = Waitlist::for('beta')->add('John Doe', 'john@example.com');

// Add with metadata
$entry = Waitlist::for('launch')->add('Jane Doe', 'jane@example.com', [
    'referral_source' => 'twitter',
    'interest' => 'premium',
    'company' => 'Acme Inc',
]);

// Invite and reject
Waitlist::invite($entry);        // By model
Waitlist::invite($entryId);      // By ID
Waitlist::reject($entry);
Waitlist::reject($entryId);

// Pass options through to the underlying invitation
$entry = Waitlist::for('beta')->getByEmail('john@example.com');

Waitlist::invite($entry, [
    'invited_by' => $admin,        // Model or int; falls back to auth()->user()
    'role' => 'beta-tester',
    'metadata' => ['cohort' => 'wave-3'],
    'expires_at' => now()->addDays(14),
]);

// Query entries
$pending = Waitlist::for('beta')->getPending();
$invited = Waitlist::for('beta')->getInvited();
$all = Waitlist::for('beta')->getAll();
$entry = Waitlist::for('beta')->getByEmail('john@example.com');

// Check existence
if (Waitlist::for('beta')->exists('john@example.com')) {
    // User is on the beta waitlist
}

// Get statistics
$total = Waitlist::for('beta')->count();
$pending = Waitlist::for('beta')->countPending();
$invited = Waitlist::for('beta')->countInvited();

// Manage waitlist status
$beta->activate();
$beta->deactivate();
$beta->isActive(); // true/false
```

### Working With the Model

You can also work directly with the `WaitlistEntry` model:

```php
use OffloadProject\Waitlist\Models\WaitlistEntry;

// Create an entry
$entry = WaitlistEntry::create([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
    'metadata' => ['source' => 'landing-page'],
]);

// Check status
if ($entry->isPending()) {
    // Entry is pending
}

if ($entry->isInvited()) {
    // Entry has been invited
}

if ($entry->isRejected()) {
    // Entry was rejected
}

// Update status
$entry->markAsInvited();
$entry->markAsRejected();

// Query entries
$pending = WaitlistEntry::where('status', 'pending')->get();
$recent = WaitlistEntry::latest()->take(10)->get();
```

### Custom Controller / Livewire

Since this package doesn't include controllers, you can create your own to fit your needs:

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OffloadProject\Waitlist\Facades\Waitlist;

class WaitlistController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:waitlist_entries,email',
        ]);

        $entry = Waitlist::add(
            $validated['name'],
            $validated['email']
        );

        return response()->json([
            'message' => 'Successfully added to waitlist!',
            'data' => $entry,
        ], 201);
    }

    public function stats()
    {
        return response()->json([
            'total' => Waitlist::count(),
            'pending' => Waitlist::countPending(),
            'invited' => Waitlist::countInvited(),
        ]);
    }
}
```

Example Livewire component:

```php
namespace App\Livewire;

use Livewire\Component;
use OffloadProject\Waitlist\Facades\Waitlist;

class WaitlistForm extends Component
{
    public $name = '';
    public $email = '';
    public $success = false;

    public function submit()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:waitlist_entries,email',
        ]);

        Waitlist::add($this->name, $this->email);

        $this->success = true;
        $this->reset(['name', 'email']);
    }

    public function render()
    {
        return view('livewire.waitlist-form');
    }
}
```

### Custom Notifications

Publish the config file and change the notification class:

```php
// config/waitlist.php
'notification' => \App\Notifications\CustomWaitlistInvited::class,
```

Create your custom notification:

```php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use OffloadProject\Waitlist\Models\WaitlistEntry;

class CustomWaitlistInvited extends Notification
{
    public function __construct(public WaitlistEntry $entry) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to Our Platform!')
            ->greeting("Hi {$this->entry->name}!")
            ->line('Great news! Your wait is over.')
            ->action('Get Started', url('/register'))
            ->line('We can\'t wait to see what you build!');
    }
}
```

To send invitations manually instead of automatically, disable auto-send:

```php
// config/waitlist.php
'auto_send_invitation' => false,
```

```php
use OffloadProject\Waitlist\Notifications\WaitlistInvited;

$entry = Waitlist::getByEmail('john@example.com');
$entry->notify(new WaitlistInvited($entry));
```

### Email Verification

Optionally require users to verify their email before they can be invited:

```php
// config/waitlist.php
'verification' => [
    'enabled' => true,  // Enable email verification
    'require_before_invite' => true,  // Block invites until verified
],
```

Or via environment variables:

```env
WAITLIST_VERIFICATION_ENABLED=true
WAITLIST_REQUIRE_VERIFICATION=true
```

When verification is enabled:

```php
use OffloadProject\Waitlist\Facades\Waitlist;

// Adding an entry automatically sends a verification email
$entry = Waitlist::add('John Doe', 'john@example.com');

// Check verification status
$entry->isVerified();           // false initially
$entry->isPendingVerification(); // true after verification email sent

// Manually send/resend verification email
Waitlist::sendVerification($entry);

// Verify programmatically (normally handled by the verification route)
Waitlist::verify($token);

// Attempting to invite an unverified entry throws an exception
// when require_before_invite is true
try {
    Waitlist::invite($entry);
} catch (\OffloadProject\Waitlist\Exceptions\UnverifiedEntryException $e) {
    // Handle unverified entry
}
```

The package provides a verification route at `/waitlist/verify/{token}` by default. Configure the routes in your config:

```php
// config/waitlist.php
'routes' => [
    'enabled' => true,        // Set to false to define your own routes
    'prefix' => 'waitlist',   // URL prefix
    'middleware' => ['web'],  // Middleware to apply
],
```

To customize the verification notification:

```php
// config/waitlist.php
'verification' => [
    'enabled' => true,
    'require_before_invite' => true,
    'notification' => \App\Notifications\CustomVerifyEmail::class,
],
```

```php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use OffloadProject\Waitlist\Models\WaitlistEntry;

class CustomVerifyEmail extends Notification
{
    public function __construct(public WaitlistEntry $entry) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = route('waitlist.verify', ['token' => $this->entry->verification_token]);

        return (new MailMessage)
            ->subject('Confirm your spot on the waitlist')
            ->greeting("Hi {$this->entry->name}!")
            ->line('Please verify your email to secure your place.')
            ->action('Verify Email', $url);
    }
}
```

### Mailing List Integration

Sync entries into a newsletter service as they join. Mailchimp and Kit (ConvertKit) ship with the package, and you can
register a driver for anything else.

```dotenv
WAITLIST_MAILING_LIST_ENABLED=true
WAITLIST_MAILING_LIST_DRIVER=mailchimp

MAILCHIMP_API_KEY=1234567890abcdef-us14
```

Then point a waitlist at a list — the id is whatever the provider calls it:

```php
use OffloadProject\Waitlist\Facades\Waitlist;

Waitlist::for('beta')->connectMailingList('a1b2c3d4e5');          // a Mailchimp audience
Waitlist::for('launch')->connectMailingList('7654321', 'kit');    // a Kit form, on a different driver
```

Each waitlist can sync into a different list, and into a different service. Waitlists that aren't connected fall back to
the driver's `list_id` in the config, so a single-list setup needs no `connectMailingList()` call at all.

From there, entries subscribe themselves — and the timing follows your verification setting:

| `verification.enabled` | The entry is subscribed             |
|------------------------|-------------------------------------|
| `false`                | as soon as they join the waitlist   |
| `true`                 | once they have confirmed their email |

That way an unconfirmed address never reaches your newsletter. Syncing runs in a queued job, so sign ups never wait on
the provider's API.

#### Drivers

| Driver      | The list id is                                       | Notes                                                                     |
|-------------|------------------------------------------------------|---------------------------------------------------------------------------|
| `mailchimp` | an audience id                                       | Contacts are upserted, so an existing unsubscribe is never overridden       |
| `kit`       | a form id, or a tag id with `list_type` set to `tag` | Kit unsubscribes are account-wide; double opt-in follows the form's setting |
| `log`       | anything                                             | Writes what would have been sent to the log — handy for local work          |
| `array`     | anything                                             | In-memory, used by `MailingList::fake()` in tests                           |

#### Backfilling existing entries

```bash
php artisan waitlist:sync-mailing-list                # the default waitlist
php artisan waitlist:sync-mailing-list beta           # one waitlist
php artisan waitlist:sync-mailing-list --all          # every waitlist
php artisan waitlist:sync-mailing-list --all --force  # include entries already synced
```

Or from code, which returns the number of entries queued:

```php
Waitlist::for('beta')->syncMailingList();
```

#### Syncing by hand

Set `mailing_list.auto_subscribe` to `false` and drive it yourself:

```php
use OffloadProject\Waitlist\Facades\MailingList;

Waitlist::subscribeToMailingList($entry);
Waitlist::unsubscribeFromMailingList($entry);

MailingList::tagEntry($entry, ['beta-cohort']);
```

#### Custom fields

Map entry data onto Mailchimp merge fields or Kit custom fields:

```php
// config/waitlist.php
'attributes' => fn (WaitlistEntry $entry) => [
    'SOURCE' => $entry->metadata['referral_source'] ?? 'direct',
],
```

Note that a config file holding a closure cannot be cached with `php artisan config:cache`.

#### Adding a driver

Implement `OffloadProject\Waitlist\Contracts\MailingListDriver` and register it from a service provider:

```php
MailingList::extend('brevo', fn (array $config) => new BrevoDriver($config['key']));
```

`$config` is whatever sits under `waitlist.mailing_list.drivers.brevo`.

#### Testing

`MailingList::fake()` swaps in the in-memory driver and runs syncs inline:

```php
$mailingList = MailingList::fake();

Waitlist::add('John Doe', 'john@example.com');

expect($mailingList->hasSubscriber('john@example.com'))->toBeTrue();
```

### Events

Every step of the lifecycle fires an event, so you can hook in without wrapping the facade:

| Event                       | Fired when                                          | Payload                        |
|-----------------------------|-----------------------------------------------------|--------------------------------|
| `WaitlistCreated`           | a waitlist is created                               | `$waitlist`                    |
| `WaitlistEntryAdded`        | someone joins a waitlist                            | `$entry`                       |
| `WaitlistEntryVerified`     | an entry confirms their email                       | `$entry`                       |
| `WaitlistEntryInvited`      | an entry is invited                                 | `$entry`                       |
| `WaitlistEntryRejected`     | an entry is rejected                                | `$entry`                       |
| `WaitlistEntrySubscribed`   | an entry reaches the mailing list                   | `$entry`, `$subscriber`, `$driver` |
| `WaitlistEntryUnsubscribed` | an entry is removed from the mailing list           | `$entry`, `$driver`            |
| `MailingListSyncFailed`     | a sync could not be completed                       | `$entry`, `$driver`, `$exception` |

They all live in `OffloadProject\Waitlist\Events`. Tagging people in your newsletter as they get invited, for example:

```php
use Illuminate\Support\Facades\Event;
use OffloadProject\Waitlist\Events\WaitlistEntryInvited;
use OffloadProject\Waitlist\Facades\MailingList;

Event::listen(function (WaitlistEntryInvited $event) {
    MailingList::tagEntry($event->entry, ['invited']);
});
```

The lifecycle events fire from the model, so they also cover `$entry->markAsInvited()` and friends — not just the facade.

## Configuration

```php
return [
    // The model class used for waitlist entries
    'model' => \OffloadProject\Waitlist\Models\WaitlistEntry::class,

    // Database table name
    'table' => 'waitlist_entries',

    // Auto-send invitation notifications
    'auto_send_invitation' => true,

    // Notification class for invitations
    'notification' => \OffloadProject\Waitlist\Notifications\WaitlistInvited::class,

    // Email verification settings
    'verification' => [
        'enabled' => false,  // Enable/disable email verification
        'require_before_invite' => true,  // Require verification before inviting
        'notification' => \OffloadProject\Waitlist\Notifications\VerifyWaitlistEmail::class,
    ],

    // Route configuration
    'routes' => [
        'enabled' => true,  // Enable package routes
        'prefix' => 'waitlist',  // URL prefix
        'middleware' => ['web'],  // Middleware
    ],

    // Mailing list integration
    'mailing_list' => [
        'enabled' => false,  // Turn syncing on
        'default' => 'log',  // mailchimp, kit, log, array — or your own
        'auto_subscribe' => true,  // Subscribe entries automatically
        'double_optin' => false,  // Mailchimp only; Kit follows the form's setting
        'tags' => [],  // Applied to every subscriber the package creates
        'attributes' => null,  // Closure mapping an entry to provider fields
        'queue' => [
            'enabled' => true,  // Sync in a queued job
            'connection' => null,
            'queue' => null,
        ],
        'timeout' => 10,  // HTTP timeout in seconds
        'retries' => 2,  // Retries per request
        'drivers' => [
            'mailchimp' => [
                'key' => env('MAILCHIMP_API_KEY'),
                'server' => env('MAILCHIMP_SERVER_PREFIX'),  // Derived from the key when null
                'list_id' => env('MAILCHIMP_LIST_ID'),  // Fallback audience
            ],
            'kit' => [
                'key' => env('KIT_API_KEY'),
                'list_type' => env('KIT_LIST_TYPE', 'form'),  // form or tag
                'list_id' => env('KIT_FORM_ID'),
            ],
        ],
    ],
];
```

## Database Schema

### `waitlists` table

- `id` — Primary key
- `name` — Waitlist name
- `slug` — Unique identifier for referencing the waitlist
- `description` — Optional description
- `is_active` — Whether the waitlist is active (default: `true`)
- `settings` — JSON field for custom settings
- `created_at` / `updated_at` — Laravel timestamps

Indexed fields: `slug`, `is_active`

### `waitlist_entries` table

- `id` — Primary key
- `waitlist_id` — Foreign key to the waitlist (nullable for default waitlist)
- `name` — User's name
- `email` — User's email (unique per waitlist)
- `status` — Status: `pending`, `invited`, or `rejected`
- `invited_at` — Timestamp when invited
- `metadata` — JSON field for custom data
- `verification_token` — Token for email verification (nullable)
- `verified_at` — Timestamp when email was verified (nullable)
- `invitation_id` — Optional FK to `offload-project/laravel-invite-only` invitation (nullable)
- `mailing_list_driver` — The driver the entry was synced with (nullable)
- `mailing_list_subscriber_id` — The subscriber's id on the mailing list service (nullable)
- `mailing_list_synced_at` — Timestamp of the last successful sync (nullable)
- `created_at` / `updated_at` — Laravel timestamps

Indexed fields: `status`, `created_at`, `verification_token`, `mailing_list_synced_at`
Unique constraint: `['waitlist_id', 'email']` (same email can join multiple waitlists)

## API Reference

### Facade Methods

```php
// Managing waitlists
Waitlist::create(string $name, string $slug, ?string $description = null, bool $isActive = true): Waitlist
Waitlist::find(string $slug): ?Waitlist
Waitlist::for(string|int|Waitlist $waitlist): self  // Set waitlist context
Waitlist::getDefault(): Waitlist

// Adding entries (uses current waitlist context or default)
Waitlist::add(string $name, string $email, array $metadata = []): WaitlistEntry

// Managing status
Waitlist::invite(int|WaitlistEntry $entry, array $options = []): WaitlistEntry
Waitlist::reject(int|WaitlistEntry $entry): WaitlistEntry

// Email verification
Waitlist::sendVerification(int|WaitlistEntry $entry): WaitlistEntry
Waitlist::verify(string $token): ?WaitlistEntry

// Retrieving entries (uses current waitlist context or default)
Waitlist::getPending(): Collection
Waitlist::getInvited(): Collection
Waitlist::getAll(): Collection
Waitlist::getByEmail(string $email): ?WaitlistEntry

// Checking existence
Waitlist::exists(string $email): bool

// Counting
Waitlist::count(): int
Waitlist::countPending(): int
Waitlist::countInvited(): int

// Mailing list (uses current waitlist context or default)
Waitlist::connectMailingList(string $listId, ?string $driver = null): Waitlist
Waitlist::disconnectMailingList(): Waitlist
Waitlist::subscribeToMailingList(int|WaitlistEntry $entry): WaitlistEntry
Waitlist::unsubscribeFromMailingList(int|WaitlistEntry $entry): WaitlistEntry
Waitlist::syncMailingList(bool $force = false): int
```

### MailingList Facade Methods

```php
use OffloadProject\Waitlist\Facades\MailingList;

MailingList::enabled(): bool
MailingList::driver(?string $name = null): MailingListDriver
MailingList::for(?Waitlist $waitlist = null): MailingListDriver
MailingList::driverNameFor(?Waitlist $waitlist = null): string
MailingList::listIdFor(?Waitlist $waitlist = null): ?string
MailingList::tagEntry(WaitlistEntry $entry, array $tags): void
MailingList::extend(string $name, Closure $callback): MailingListManager
MailingList::fake(?string $listId = null): ArrayDriver  // Testing
```

Each driver implements `MailingListDriver`:

```php
$driver->subscribe(WaitlistEntry $entry, string $listId, array $options = []): Subscriber
$driver->unsubscribe(WaitlistEntry $entry, string $listId): void
$driver->tag(WaitlistEntry $entry, string $listId, array $tags): void
$driver->find(string $email, string $listId): ?Subscriber
```

### Waitlist Model Methods

```php
// Relationships
$waitlist->entries(): HasMany

// Status checks
$waitlist->isActive(): bool

// Status updates
$waitlist->activate(): self
$waitlist->deactivate(): self

// Mailing list
$waitlist->mailingListId(): ?string
$waitlist->mailingListDriver(): ?string
$waitlist->connectMailingList(string $listId, ?string $driver = null): self
$waitlist->disconnectMailingList(): self
```

### WaitlistEntry Model Methods

```php
// Status checks
$entry->isPending(): bool
$entry->isInvited(): bool
$entry->isRejected(): bool

// Verification checks
$entry->isVerified(): bool
$entry->isPendingVerification(): bool

// Status updates
$entry->markAsInvited(): self
$entry->markAsRejected(): self
$entry->markAsVerified(): self
$entry->generateVerificationToken(): self

// Mailing list
$entry->isSubscribedToMailingList(): bool
$entry->markAsSubscribed(string $driver, string $subscriberId): self
$entry->markAsUnsubscribed(): self
```

## AI Coding Assistant Skill

This package ships a [Laravel Boost](https://skills.laravel.cloud/) skill so coding assistants (Claude Code, Cursor,
etc.) follow the package's conventions when generating code. Install it in your app with:

```bash
php artisan boost:add-skill offload-project/laravel-waitlist
```

The skill source lives at [`skills/SKILL.md`](skills/SKILL.md).

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please see the documents below before getting started.

- [Contributing Guide](CONTRIBUTING.md) — setup, workflow, commit conventions, and PR process
- [Code of Conduct](CODE_OF_CONDUCT.md) — expectations for participation in this project

## Security

- [Security Policy](SECURITY.md) — how to report a vulnerability privately

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
