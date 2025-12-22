<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use OffloadProject\Waitlist\Facades\Waitlist;
use OffloadProject\Waitlist\Models\WaitlistEntry;
use OffloadProject\Waitlist\Notifications\WaitlistInvited;

test('can add user to waitlist via facade', function () {
    $entry = Waitlist::add('John Doe', 'john@example.com');

    expect($entry)->toBeInstanceOf(WaitlistEntry::class)
        ->and($entry->name)->toBe('John Doe')
        ->and($entry->email)->toBe('john@example.com')
        ->and($entry->status)->toBe('pending');

    $this->assertDatabaseHas('waitlist_entries', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
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

    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    Waitlist::invite($entry);

    $entry->refresh();

    expect($entry->status)->toBe('invited')
        ->and($entry->invited_at)->not->toBeNull();

    Notification::assertSentTo($entry, WaitlistInvited::class);
});

test('can invite user by id', function () {
    Notification::fake();

    $entry = WaitlistEntry::create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Waitlist::invite($entry->id);

    $entry->refresh();

    expect($entry->status)->toBe('invited')
        ->and($entry->invited_at)->not->toBeNull();
});

test('can reject user from waitlist', function () {
    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    Waitlist::reject($entry);

    $entry->refresh();

    expect($entry->status)->toBe('rejected');
});

test('can reject user by id', function () {
    $entry = WaitlistEntry::create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Waitlist::reject($entry->id);

    $entry->refresh();

    expect($entry->status)->toBe('rejected');
});

test('can get all pending entries', function () {
    WaitlistEntry::create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'invited']);

    $pending = Waitlist::getPending();

    expect($pending)->toHaveCount(2);
});

test('can get all invited entries', function () {
    WaitlistEntry::create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'invited']);
    WaitlistEntry::create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'invited']);

    $invited = Waitlist::getInvited();

    expect($invited)->toHaveCount(2);
});

test('can get all entries', function () {
    WaitlistEntry::create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'invited']);

    $all = Waitlist::getAll();

    expect($all)->toHaveCount(2);
});

test('can get entry by email', function () {
    WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    $entry = Waitlist::getByEmail('john@example.com');

    expect($entry)->not->toBeNull()
        ->and($entry->email)->toBe('john@example.com');
});

test('returns null when email not found', function () {
    $entry = Waitlist::getByEmail('nonexistent@example.com');

    expect($entry)->toBeNull();
});

test('can check if email exists in waitlist', function () {
    WaitlistEntry::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    expect(Waitlist::exists('john@example.com'))->toBeTrue()
        ->and(Waitlist::exists('nonexistent@example.com'))->toBeFalse();
});

test('can count total entries', function () {
    WaitlistEntry::create(['name' => 'User 1', 'email' => 'user1@example.com']);
    WaitlistEntry::create(['name' => 'User 2', 'email' => 'user2@example.com']);

    expect(Waitlist::count())->toBe(2);
});

test('can count pending entries', function () {
    WaitlistEntry::create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'invited']);

    expect(Waitlist::countPending())->toBe(2);
});

test('can count invited entries', function () {
    WaitlistEntry::create(['name' => 'User 1', 'email' => 'user1@example.com', 'status' => 'pending']);
    WaitlistEntry::create(['name' => 'User 2', 'email' => 'user2@example.com', 'status' => 'invited']);
    WaitlistEntry::create(['name' => 'User 3', 'email' => 'user3@example.com', 'status' => 'invited']);

    expect(Waitlist::countInvited())->toBe(2);
});

test('model has pending status check', function () {
    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'pending',
    ]);

    expect($entry->isPending())->toBeTrue()
        ->and($entry->isInvited())->toBeFalse()
        ->and($entry->isRejected())->toBeFalse();
});

test('model has invited status check', function () {
    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'invited',
    ]);

    expect($entry->isPending())->toBeFalse()
        ->and($entry->isInvited())->toBeTrue()
        ->and($entry->isRejected())->toBeFalse();
});

test('model has rejected status check', function () {
    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'rejected',
    ]);

    expect($entry->isPending())->toBeFalse()
        ->and($entry->isInvited())->toBeFalse()
        ->and($entry->isRejected())->toBeTrue();
});

test('model can mark as invited', function () {
    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'pending',
    ]);

    $entry->markAsInvited();

    expect($entry->status)->toBe('invited')
        ->and($entry->invited_at)->not->toBeNull();
});

test('model can mark as rejected', function () {
    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'pending',
    ]);

    $entry->markAsRejected();

    expect($entry->status)->toBe('rejected');
});

test('can disable auto notification on invite', function () {
    Notification::fake();
    config(['waitlist.auto_send_invitation' => false]);

    $entry = WaitlistEntry::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    Waitlist::invite($entry);

    Notification::assertNothingSent();
});
