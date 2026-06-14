---
name: Laravel Waitlist
description: Conventions and APIs for the offload-project/laravel-waitlist package — multiple waitlists, entry status tracking, optional email verification, and bridge into laravel-invite-only.
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
---

## Context

`offload-project/laravel-waitlist` is a Laravel 11/12/13 package (PHP 8.3+) for managing one or many waitlists. It ships:

- A `Waitlist` Eloquent model (a named waitlist with a `slug`) and a `WaitlistEntry` model (a person waiting on a list).
- A `WaitlistService` (resolved via the `Waitlist` facade) with `for()`, `create()`, `add()`, `invite()`, `reject()`, `sendVerification()`, `verify()`, and query/count helpers.
- Optional email verification flow with a published `/waitlist/verify/{token}` route.
- Optional bridge into `offload-project/laravel-invite-only`: calling `Waitlist::invite()` creates a real `Invitation` (token, expiration, events) and persists the FK on `WaitlistEntry::$invitation_id`.
- Two notifications: `WaitlistInvited` (opt-in via `auto_send_invitation`) and `VerifyWaitlistEmail`.
- A typed `UnverifiedEntryException` thrown when invite is attempted on an unverified entry while verification gating is on.

Apply this skill when working in a Laravel app that has `offload-project/laravel-waitlist` in `composer.json`, or when the user asks for help with `Waitlist`, `WaitlistEntry`, the `Waitlist` facade, or waitlist flows in this package.

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

### Don'ts

20. Don't run lifecycle changes via direct `update()` calls (`$entry->update(['status' => 'invited'])`). Use `markAsInvited()` / `markAsRejected()` / `markAsVerified()` so casts and side effects (timestamps, token clearing) stay consistent. Better still: drive everything through the facade.
21. Don't edit the published migrations to add columns — write a follow-up migration in the host app. The package may add columns in future releases and will assume the published schema.
22. Don't subclass `Waitlist` or `WaitlistEntry`; both are `final`. Add behavior on the host-app side via event listeners on the underlying `laravel-invite-only` events, or by extending the service via a custom binding in your app's container.

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
- ❌ Subclassing `Waitlist` or `WaitlistEntry`. Both are `final`; extend behavior via events on `laravel-invite-only` or a custom service binding.
- ❌ Hard-coding `'invited_by' => auth()->user()` at every call site. Omit it and let `WaitlistService` fall back to `auth()->user()` automatically. Pass it explicitly only when you need a different actor (admin acting on behalf, console command, etc.).
- ❌ Editing files inside `vendor/offload-project/laravel-waitlist`. All extension points are exposed via `config/waitlist.php`.
- ❌ Sharing one email between waitlists via a single global `email` unique constraint. The package already supports a person on multiple waitlists — the constraint is `['waitlist_id', 'email']`. Don't add app-level deduplication that fights this.

## References

- Repository: <https://github.com/offload-project/laravel-waitlist>
- README: <https://github.com/offload-project/laravel-waitlist/blob/main/README.md>
- Companion package — Laravel Invite Only: <https://github.com/offload-project/laravel-invite-only>
