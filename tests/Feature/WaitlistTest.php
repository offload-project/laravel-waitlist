<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use OffloadProject\Waitlist\Facades\Waitlist;
use OffloadProject\Waitlist\Models\WaitlistEntry;
use OffloadProject\Waitlist\Notifications\WaitlistInvited;

test('can add user to default waitlist via facade', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($entry)->toBeInstanceOf(WaitlistEntry::class)
        ->and($entry->name)->toBe('John Doe')
        ->and($entry->email)->toBe('john@example.com')
        ->and($entry->status)->toBe('pending')
        ->and($entry->waitlist_id)->not->toBeNull();

    $this->assertDatabaseHas('waitlist_entries', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
});

test('can create multiple waitlists', function () {
    $beta = Waitlist::create('Beta Program', 'beta', 'Early access beta program');
    $launch = Waitlist::create('Launch List', 'launch', 'Product launch waitlist');

    expect($beta->slug)->toBe('beta')
        ->and($launch->slug)->toBe('launch');

    $this->assertDatabaseHas('waitlists', ['slug' => 'beta']);
    $this->assertDatabaseHas('waitlists', ['slug' => 'launch']);
});

test('can add users to specific waitlists', function () {
    $beta = Waitlist::create('Beta', 'beta');
    $launch = Waitlist::create('Launch', 'launch');

    $betaEntry = Waitlist::for('beta')->add('John Doe', 'john@example.com');
    $launchEntry = Waitlist::for('launch')->add('Jane Doe', 'jane@example.com');

    expect($betaEntry->waitlist_id)->toBe($beta->id)
        ->and($launchEntry->waitlist_id)->toBe($launch->id);
});

test('same email can join different waitlists', function () {
    $beta = Waitlist::create('Beta', 'beta');
    $launch = Waitlist::create('Launch', 'launch');

    Waitlist::for('beta')->add('John Doe', 'john@example.com');
    Waitlist::for('launch')->add('John Doe', 'john@example.com');

    expect(WaitlistEntry::where('email', 'john@example.com')->count())->toBe(2);
});

test('cannot add same email twice to same waitlist', function () {
    $beta = Waitlist::create('Beta', 'beta');

    Waitlist::for('beta')->add('John Doe', 'john@example.com');

    $this->expectException(Illuminate\Database\QueryException::class);
    Waitlist::for('beta')->add('John Doe', 'john@example.com');
});

test('can get pending entries for specific waitlist', function () {
    $beta = Waitlist::create('Beta', 'beta');
    $launch = Waitlist::create('Launch', 'launch');

    Waitlist::for('beta')->add('User 1', 'user1@example.com');
    Waitlist::for('beta')->add('User 2', 'user2@example.com');
    Waitlist::for('launch')->add('User 3', 'user3@example.com');

    $betaPending = Waitlist::for('beta')->getPending();
    $launchPending = Waitlist::for('launch')->getPending();

    expect($betaPending)->toHaveCount(2)
        ->and($launchPending)->toHaveCount(1);
});

test('can count entries per waitlist', function () {
    $beta = Waitlist::create('Beta', 'beta');
    $launch = Waitlist::create('Launch', 'launch');

    Waitlist::for('beta')->add('User 1', 'user1@example.com');
    Waitlist::for('beta')->add('User 2', 'user2@example.com');
    Waitlist::for('launch')->add('User 3', 'user3@example.com');

    expect(Waitlist::for('beta')->count())->toBe(2)
        ->and(Waitlist::for('launch')->count())->toBe(1);
});

test('can add user with metadata', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com', [
        'referral_source' => 'twitter',
        'interest' => 'premium',
    ]);

    expect($entry->metadata)->toBe([
        'referral_source' => 'twitter',
        'interest' => 'premium',
    ]);
});

test('can invite user from waitlist', function () {
    Notification::fake();

    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::invite($entry);

    $entry->refresh();

    expect($entry->status)->toBe('invited')
        ->and($entry->invited_at)->not->toBeNull();

    Notification::assertSentTo($entry, WaitlistInvited::class);
});

test('can invite user by id', function () {
    Notification::fake();

    $entry = Waitlist::add('Jane Doe', 'jane@example.com');
    Waitlist::invite($entry->id);

    $entry->refresh();

    expect($entry->status)->toBe('invited')
        ->and($entry->invited_at)->not->toBeNull();
});

test('can reject user from waitlist', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::reject($entry);

    $entry->refresh();

    expect($entry->status)->toBe('rejected');
});

test('can reject user by id', function () {
    $entry = Waitlist::add('Jane Doe', 'jane@example.com');
    Waitlist::reject($entry->id);

    $entry->refresh();

    expect($entry->status)->toBe('rejected');
});

test('can get all pending entries', function () {
    Waitlist::add('User 1', 'user1@example.com');
    Waitlist::add('User 2', 'user2@example.com');

    $entry3 = Waitlist::add('User 3', 'user3@example.com');
    Waitlist::invite($entry3);

    $pending = Waitlist::getPending();

    expect($pending)->toHaveCount(2);
});

test('can get all invited entries', function () {
    $entry1 = Waitlist::add('User 1', 'user1@example.com');
    $entry2 = Waitlist::add('User 2', 'user2@example.com');
    Waitlist::add('User 3', 'user3@example.com');

    Waitlist::invite($entry1);
    Waitlist::invite($entry2);

    $invited = Waitlist::getInvited();

    expect($invited)->toHaveCount(2);
});

test('can get all entries', function () {
    Waitlist::add('User 1', 'user1@example.com');
    Waitlist::add('User 2', 'user2@example.com');

    $all = Waitlist::getAll();

    expect($all)->toHaveCount(2);
});

test('can get entry by email', function () {
    Waitlist::add('John Doe', 'john@example.com');

    $entry = Waitlist::getByEmail('john@example.com');

    expect($entry)->not->toBeNull()
        ->and($entry->email)->toBe('john@example.com');
});

test('returns null when email not found', function () {
    $entry = Waitlist::getByEmail('nonexistent@example.com');

    expect($entry)->toBeNull();
});

test('can check if email exists in waitlist', function () {
    Waitlist::add('John Doe', 'john@example.com');

    expect(Waitlist::exists('john@example.com'))->toBeTrue()
        ->and(Waitlist::exists('nonexistent@example.com'))->toBeFalse();
});

test('can count total entries', function () {
    Waitlist::add('User 1', 'user1@example.com');
    Waitlist::add('User 2', 'user2@example.com');

    expect(Waitlist::count())->toBe(2);
});

test('can count pending entries', function () {
    Waitlist::add('User 1', 'user1@example.com');
    Waitlist::add('User 2', 'user2@example.com');

    $entry3 = Waitlist::add('User 3', 'user3@example.com');
    Waitlist::invite($entry3);

    expect(Waitlist::countPending())->toBe(2);
});

test('can count invited entries', function () {
    $entry1 = Waitlist::add('User 1', 'user1@example.com');
    $entry2 = Waitlist::add('User 2', 'user2@example.com');
    Waitlist::add('User 3', 'user3@example.com');

    Waitlist::invite($entry1);
    Waitlist::invite($entry2);

    expect(Waitlist::countInvited())->toBe(2);
});

test('model has pending status check', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($entry->isPending())->toBeTrue()
        ->and($entry->isInvited())->toBeFalse()
        ->and($entry->isRejected())->toBeFalse();
});

test('model has invited status check', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::invite($entry);

    $entry->refresh();

    expect($entry->isPending())->toBeFalse()
        ->and($entry->isInvited())->toBeTrue()
        ->and($entry->isRejected())->toBeFalse();
});

test('model has rejected status check', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::reject($entry);

    $entry->refresh();

    expect($entry->isPending())->toBeFalse()
        ->and($entry->isInvited())->toBeFalse()
        ->and($entry->isRejected())->toBeTrue();
});

test('model can mark as invited', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');
    $entry->markAsInvited();

    expect($entry->status)->toBe('invited')
        ->and($entry->invited_at)->not->toBeNull();
});

test('model can mark as rejected', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');
    $entry->markAsRejected();

    expect($entry->status)->toBe('rejected');
});

test('can disable auto notification on invite', function () {
    Notification::fake();
    config(['waitlist.auto_send_invitation' => false]);

    $entry = Waitlist::add('John Doe', 'john@example.com');
    Waitlist::invite($entry);

    Notification::assertNothingSent();
});

test('waitlist model has relationship with entries', function () {
    $beta = Waitlist::create('Beta', 'beta');

    Waitlist::for('beta')->add('User 1', 'user1@example.com');
    Waitlist::for('beta')->add('User 2', 'user2@example.com');

    expect($beta->entries)->toHaveCount(2);
});

test('entry model has relationship with waitlist', function () {
    $beta = Waitlist::create('Beta', 'beta');
    $entry = Waitlist::for('beta')->add('John Doe', 'john@example.com');

    expect($entry->waitlist)->not->toBeNull()
        ->and($entry->waitlist->slug)->toBe('beta');
});

test('can activate and deactivate waitlist', function () {
    $beta = Waitlist::create('Beta', 'beta');

    expect($beta->is_active)->toBeTrue();

    $beta->deactivate();
    expect($beta->is_active)->toBeFalse();

    $beta->activate();
    expect($beta->is_active)->toBeTrue();
});

test('can find waitlist by slug', function () {
    Waitlist::create('Beta', 'beta');

    $found = Waitlist::find('beta');

    expect($found)->not->toBeNull()
        ->and($found->slug)->toBe('beta');
});
