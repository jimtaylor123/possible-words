<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('user:role promotes a user to admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->artisan('user:role', ['email' => $user->email, 'role' => 'admin'])
        ->assertSuccessful();

    $this->assertTrue($user->fresh()->is_admin);
});

test('user:role demotes an admin back to user', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->artisan('user:role', ['email' => $user->email, 'role' => 'user'])
        ->assertSuccessful();

    $this->assertFalse($user->fresh()->is_admin);
});

test('user:role returns error for non-existent email', function () {
    $this->artisan('user:role', ['email' => 'nobody@example.com', 'role' => 'admin'])
        ->assertFailed();
});

test('user:role returns error for invalid role', function () {
    $user = User::factory()->create();

    $this->artisan('user:role', ['email' => $user->email, 'role' => 'superadmin'])
        ->assertFailed();
});

test('user:role returns error for invalid email', function () {
    $this->artisan('user:role', ['email' => 'not-an-email', 'role' => 'admin'])
        ->assertFailed();
});
