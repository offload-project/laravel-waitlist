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
- `created_at` / `updated_at` — Laravel timestamps

Indexed fields: `status`, `created_at`, `verification_token`
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
