---
name: Laravel Waitlist
description: Conventions and APIs for the offload-project/laravel-waitlist package — multiple waitlists, entry status tracking, optional email verification, lifecycle events, mailing list sync (Mailchimp/Kit), and bridge into laravel-invite-only.
compatible_agents:
  - Claude Code
  - Cursor
tags:
  - laravel
  - php
  - waitlist
  - eloquent
  - notifications
  - invitations
  - mailing-list
  - mailchimp
---

## Context

`offload-project/laravel-waitlist` is a Laravel 11/12/13 package (PHP 8.3+) for managing one or many waitlists. It ships:

- A `Waitlist` Eloquent model (a named waitlist with a `slug`) and a `WaitlistEntry` model (a person waiting on a list).
- A `WaitlistService` (resolved via the `Waitlist` facade) with `for()`, `create()`, `add()`, `invite()`, `reject()`, `sendVerification()`, `verify()`, and query/count helpers.
- Optional email verification flow with a published `/waitlist/verify/{token}` route.
- Optional bridge into `offload-project/laravel-invite-only`: calling `Waitlist::invite()` creates a real `Invitation` (token, expiration, events) and persists the FK on `WaitlistEntry::$invitation_id`.
- Two notifications: `WaitlistInvited` (opt-in via `auto_send_invitation`) and `VerifyWaitlistEmail`.
- Lifecycle events in `OffloadProject\Waitlist\Events`: `WaitlistCreated`, `WaitlistEntryAdded`, `WaitlistEntryVerified`, `WaitlistEntryInvited`, `WaitlistEntryRejected`, plus `WaitlistEntrySubscribed`, `WaitlistEntryUnsubscribed`, and `MailingListSyncFailed`.
- A mailing list integration (`MailingList` facade, `MailingListManager`) that syncs entries to Mailchimp or Kit (ConvertKit) in a queued job, with `log` and `array` drivers and an `extend()` hook for others.
- Typed exceptions: `UnverifiedEntryException` (invite attempted on an unverified entry while verification gating is on) and `MailingListException` (driver/credential/list-id/API failures).

Apply this skill when working in a Laravel app that has `offload-project/laravel-waitlist` in `composer.json`, or when the user asks for help with `Waitlist`, `WaitlistEntry`, the `Waitlist` or `MailingList` facades, or waitlist flows in this package.

## Rules

### Facade usage

1. Use the `Waitlist` facade (`OffloadProject\Waitlist\Facades\Waitlist`) — do **not** instantiate `WaitlistService` directly. The facade is the supported entry point.
2. To target a specific waitlist, chain `Waitlist::for($slugOrIdOrModel)->...`. Without `for(...)`, calls operate on the default waitlist (auto-created on first use via `getDefault()`).
3. `Waitlist::for(...)` mutates internal state on a singleton service. For long-running processes (queue workers, Octane), call `for(...)` for every operation rather than relying on a previous context call sticking around.

### Waitlists vs. entries

4. Create waitlists with `Waitlist::create(string $name, string $slug, ?string $description = null, bool $isActive = true)`. The `slug` is the canonical identifier — that's what `for(...)` and `find(...)` expect.
5. Add entries via `Waitlist::for($slug)->add($name, $email, $metadata = [])`. Don't call `WaitlistEntry::create([...])` directly when you want the verification flow to run — `add()` automatically triggers `sendVerification()` when `waitlist.verification.enabled` is true.
6. The unique constraint on `waitlist_entries` is `['waitlist_id', 'email']`, **not** just `email`. The same person can join multiple waitlists.

### Inviting

7. Invite via `Waitlist::invite($entryOrId, $options = [])`. Do **not** call `$entry->markAsInvited()` by itself when you want notifications and an `Invitation` record — `invite()` creates the `laravel-invite-only` `Invitation`, links it via `invitation_id`, and marks the entry invited.
8. `$options` flows through to `InviteOnly::invite(...)`. Common keys: `'invited_by'` (Model or int — falls back to `auth()->user()`), `'role'`, `'metadata'`, `'expires_at'`. Don't duplicate keys you've set in `waitlist.invitable.metadata_mapper` — the explicit `$options` win via `array_merge`.
9. If `waitlist.verification.enabled` is true and `waitlist.verification.require_before_invite` is true, calling `invite()` on an unverified entry throws `UnverifiedEntryException`. Catch it explicitly in user-facing flows; don't bury it under a generic `\Throwable`.
10. The `WaitlistInvited` notification is **opt-in** (`waitlist.auto_send_invitation` defaults to `false`). The invitation notification from `laravel-invite-only` is sent regardless. Enable `auto_send_invitation` only when you want a second waitlist-branded email on top.

### Verification

11. Trigger verification through `Waitlist::sendVerification($entry)` — it generates a fresh token (`generateVerificationToken()` overwrites any existing one) and sends `VerifyWaitlistEmail`. Don't roll your own token generation; use the package's so the verify route keeps working.
12. Confirm tokens via `Waitlist::verify($token)`. Returns the `WaitlistEntry` on success, `null` on unknown token. After verification the token is cleared (single-use).
13. Customize the verification notification via `waitlist.verification.notification` config; it receives the `WaitlistEntry` in its constructor. Read the token from `$entry->verification_token` and call `route('waitlist.verify', ['token' => $entry->verification_token])`.
14. The package's verify route is mounted under `waitlist.routes.prefix` (default `waitlist`) with `waitlist.routes.middleware` (default `['web']`). To use your own controller, set `waitlist.routes.enabled => false` and call `Waitlist::verify($token)` from your action.

### Status & checks

15. Entry statuses are the string literals `pending`, `invited`, `rejected`. Prefer `$entry->isPending()`, `isInvited()`, `isRejected()` over raw string comparisons.
16. Verification state lives on `verified_at` and `verification_token`. Check via `isVerified()` and `isPendingVerification()`; don't compare raw timestamps.
17. To "block until verified" UI gating, use `isPendingVerification()` (token set, not yet verified). `isVerified()` alone returns `false` for entries that never started verification — those two states are different.

### Invitable wiring

18. When the host app is inviting people to a specific entity (Team, Organization, Project), configure it once in `config/waitlist.php` under `invitable`:
    - `invitable.model` — class string; the package calls `::first()` on it. Use this only for single-tenant apps.
    - `invitable.resolver` — closure `fn(WaitlistEntry $entry) => Model|null` for the multi-tenant case. Pull the tenant ID from `$entry->metadata` or another column.
    - `invitable.metadata_mapper` — closure `fn(WaitlistEntry $entry) => array` to translate entry metadata into invitation metadata (e.g. `['role' => 'beta-tester']`).
19. Don't hard-code an invitable per call site. If different flows need different invitables, use the `resolver` closure with a discriminator in `metadata`.

### Events

20. Hook into the lifecycle with the package's own events rather than wrapping the facade or polling the table. They live in `OffloadProject\Waitlist\Events` and each carries the model as a readonly property (`$event->entry`, `$event->waitlist`).
21. The lifecycle events fire from the models (`WaitlistEntryAdded` via `$dispatchesEvents` on create; the rest from `markAsInvited()` / `markAsRejected()` / `markAsVerified()`), so listeners still run for code that bypasses the facade.
22. Use `Event::fake([SpecificEvent::class])` in tests, not a bare `Event::fake()` — a blanket fake also swallows `WaitlistEntryAdded`, which stops the mailing list listener from ever running.

### Mailing list sync

23. Turn it on with `waitlist.mailing_list.enabled` and pick a driver (`mailchimp`, `kit`, `log`, `array`). Connect a waitlist to a list with `Waitlist::for($slug)->connectMailingList($listId, $driver = null)` — the list id is a Mailchimp audience id, or a Kit form id (or tag id when `list_type` is `tag`). Waitlists with no list of their own fall back to the driver's configured `list_id`.
24. Don't build your own "subscribe on sign up" listener. Subscribing is automatic and follows the verification setting: with `waitlist.verification.enabled` off the entry syncs on `add()`, with it on the entry syncs after `Waitlist::verify()`. That ordering is deliberate — an unconfirmed address must never reach the newsletter.
25. For anything beyond subscribing — tagging on invite, removing on reject, moving between lists — listen for the lifecycle events and call `MailingList::tagEntry($entry, [...])` or `Waitlist::unsubscribeFromMailingList($entry)`. Don't add those side effects inside the host app's controllers.
26. Syncing runs through queued jobs (`SyncEntryToMailingList`, `UnsubscribeEntryFromMailingList`). Keep `mailing_list.queue.enabled` on in production so sign ups never block on the provider's API. Backfill existing rows with `php artisan waitlist:sync-mailing-list [slug] [--all] [--force]` or `Waitlist::for($slug)->syncMailingList()`.
27. Add a service the package doesn't ship by implementing `OffloadProject\Waitlist\Contracts\MailingListDriver` and registering it with `MailingList::extend('name', fn (array $config) => new YourDriver(...))` from a service provider. Don't fork the shipped drivers.
28. In tests use `MailingList::fake()`, which swaps in the in-memory `ArrayDriver`, runs syncs inline, and exposes `hasSubscriber()`, `subscribers()`, and `tagsFor()`. Reach for `Http::fake()` only when asserting the exact request a real driver sends.

### Don'ts

29. Don't run lifecycle changes via direct `update()` calls (`$entry->update(['status' => 'invited'])`). Use `markAsInvited()` / `markAsRejected()` / `markAsVerified()` so casts, side effects (timestamps, token clearing), and events stay consistent. Better still: drive everything through the facade.
30. Don't edit the published migrations to add columns — write a follow-up migration in the host app. The package may add columns in future releases and will assume the published schema.
31. Don't subclass `Waitlist` or `WaitlistEntry`; both are `final`. Add behavior on the host-app side via listeners on the package's events, or by extending the service via a custom binding in your app's container.

## Examples

### Single waitlist (no config)

```php
use OffloadProject\Waitlist\Facades\Waitlist;

$entry = Waitlist::add('John Doe', 'john@example.com', ['source' => 'landing-page']);

Waitlist::invite($entry, [
    'invited_by' => auth()->user(),
    'expires_at' => now()->addDays(14),
]);
```

### Multiple waitlists

```php
Waitlist::create('Beta Program', 'beta');
Waitlist::create('VIP Access', 'vip');

Waitlist::for('beta')->add('Jane Smith', 'jane@example.com');
Waitlist::for('vip')->add('Bob Wilson', 'bob@example.com');

$pendingBeta = Waitlist::for('beta')->getPending();
$vipCount    = Waitlist::for('vip')->count();
```

### Verification flow

```php
// config/waitlist.php
'verification' => [
    'enabled' => true,
    'require_before_invite' => true,
    'notification' => \OffloadProject\Waitlist\Notifications\VerifyWaitlistEmail::class,
],
```

```php
use OffloadProject\Waitlist\Exceptions\UnverifiedEntryException;
use OffloadProject\Waitlist\Facades\Waitlist;

$entry = Waitlist::add('John Doe', 'john@example.com');
// Verification email sent automatically.

try {
    Waitlist::invite($entry);
} catch (UnverifiedEntryException) {
    return back()->withErrors(['email' => 'Please verify your email first.']);
}
```

### Wiring an invitable model (team invitations)

```php
// config/waitlist.php
'invitable' => [
    'model'    => null,
    'resolver' => fn (\OffloadProject\Waitlist\Models\WaitlistEntry $entry) =>
        \App\Models\Team::find($entry->metadata['team_id'] ?? null),
    'metadata_mapper' => fn (\OffloadProject\Waitlist\Models\WaitlistEntry $entry) => [
        'role' => $entry->metadata['role'] ?? 'member',
    ],
],
```

Then:

```php
Waitlist::for('beta')->add('Jane', 'jane@example.com', [
    'team_id' => $team->id,
    'role'    => 'admin',
]);
```

When you later call `Waitlist::invite($entry)`, the resulting `laravel-invite-only` invitation is scoped to that team with `role=admin`.

### Custom verification notification

```php
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use OffloadProject\Waitlist\Models\WaitlistEntry;

class CustomVerifyWaitlistEmail extends Notification
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
            ->action('Verify Email', $url);
    }
}
```

```php
// config/waitlist.php
'verification' => [
    'enabled' => true,
    'notification' => \App\Notifications\CustomVerifyWaitlistEmail::class,
],
```

### Syncing sign-ups to Mailchimp

```php
// config/waitlist.php
'mailing_list' => [
    'enabled' => true,
    'default' => 'mailchimp',
    'drivers' => [
        'mailchimp' => [
            'key' => env('MAILCHIMP_API_KEY'),   // suffix carries the data centre, e.g. -us14
            'list_id' => env('MAILCHIMP_LIST_ID'),
        ],
    ],
],
```

```php
// One audience per waitlist (optional — otherwise the config list_id is used).
Waitlist::for('beta')->connectMailingList('a1b2c3d4e5');

// Subscribed automatically, on add or on verification depending on the config.
Waitlist::for('beta')->add('Jane Smith', 'jane@example.com');
```

### Reacting to the lifecycle

```php
use Illuminate\Support\Facades\Event;
use OffloadProject\Waitlist\Events\WaitlistEntryInvited;
use OffloadProject\Waitlist\Events\WaitlistEntryRejected;
use OffloadProject\Waitlist\Facades\MailingList;
use OffloadProject\Waitlist\Facades\Waitlist;

Event::listen(fn (WaitlistEntryInvited $event) => MailingList::tagEntry($event->entry, ['invited']));
Event::listen(fn (WaitlistEntryRejected $event) => Waitlist::unsubscribeFromMailingList($event->entry));
```

### Testing a mailing list flow

```php
use OffloadProject\Waitlist\Facades\MailingList;

$mailingList = MailingList::fake();

Waitlist::add('John Doe', 'john@example.com');

expect($mailingList->hasSubscriber('john@example.com'))->toBeTrue();
```

### Disabling package routes (own controller)

```php
// config/waitlist.php
'routes' => ['enabled' => false],
```

```php
Route::get('/welcome/{token}', function (string $token) {
    $entry = Waitlist::verify($token);

    return $entry === null
        ? redirect('/')->withErrors(['token' => 'Invalid or expired link.'])
        : redirect('/welcome')->with('entry', $entry);
})->name('waitlist.verify');
```

## Anti-patterns

- ❌ `WaitlistEntry::create([...])` for new sign-ups when verification should run. Use `Waitlist::add(...)` so the verification flow fires when enabled.
- ❌ `$entry->update(['status' => 'invited'])` instead of `Waitlist::invite($entry)`. The direct update skips the `laravel-invite-only` invitation, the token, the notification, and the FK linkage.
- ❌ Catching `\Throwable` or `\Exception` around `Waitlist::invite()`. Catch `UnverifiedEntryException` (and the invite-only typed exceptions) so each failure mode produces a tailored response.
- ❌ Toggling `waitlist.auto_send_invitation` to `true` without also customizing `waitlist.notification`. By default both `WaitlistInvited` and the invite-only invitation notification will fire — two emails per invite.
- ❌ Subclassing `Waitlist` or `WaitlistEntry`. Both are `final`; extend behavior via the package's events or a custom service binding.
- ❌ Calling a mailing list API from a controller after `Waitlist::add(...)`. Subscribing is already automatic — a manual call double-subscribes and skips the queue.
- ❌ Subscribing unverified entries when verification is on (e.g. by listening for `WaitlistEntryAdded` yourself). Listen for `WaitlistEntryVerified` instead, or just let the package do it.
- ❌ Writing a mailing list `list_id` into `waitlist_entries.metadata`. Lists belong to the waitlist — store them with `connectMailingList()`, which lives in the `waitlists.settings` column.
- ❌ Putting a closure in `mailing_list.attributes` and then running `php artisan config:cache`. Config files containing closures cannot be cached.
- ❌ Hard-coding `'invited_by' => auth()->user()` at every call site. Omit it and let `WaitlistService` fall back to `auth()->user()` automatically. Pass it explicitly only when you need a different actor (admin acting on behalf, console command, etc.).
- ❌ Editing files inside `vendor/offload-project/laravel-waitlist`. All extension points are exposed via `config/waitlist.php`.
- ❌ Sharing one email between waitlists via a single global `email` unique constraint. The package already supports a person on multiple waitlists — the constraint is `['waitlist_id', 'email']`. Don't add app-level deduplication that fights this.

## References

- Repository: <https://github.com/offload-project/laravel-waitlist>
- README: <https://github.com/offload-project/laravel-waitlist/blob/main/README.md>
- Companion package — Laravel Invite Only: <https://github.com/offload-project/laravel-invite-only>
