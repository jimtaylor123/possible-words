<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Admin area.
 *
 * Given only admins should manage the site,
 * When anyone visits the admin routes,
 * Then access is gated by the admin middleware and role.
 */
describe('admin dashboard access', function () {
    test('Given an admin, the dashboard renders', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    });

    test('Given a regular user, the dashboard is forbidden', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertStatus(403);
    });

    test('Given a guest, the dashboard redirects to login', function () {
        $this->get(route('admin.dashboard'))
            ->assertRedirect('/auth/google');
    });
});

describe('promoting users via artisan', function () {
    test('Given an email and admin role, the user is promoted', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->artisan('user:role', ['email' => $user->email, 'role' => 'admin'])
            ->assertSuccessful();

        expect($user->fresh()->is_admin)->toBeTrue();
    });

    test('Given an admin and user role, the user is demoted', function () {
        $user = User::factory()->create(['is_admin' => true]);

        $this->artisan('user:role', ['email' => $user->email, 'role' => 'user'])
            ->assertSuccessful();

        expect($user->fresh()->is_admin)->toBeFalse();
    });

    test('Given a missing email, the command fails', function () {
        $this->artisan('user:role', ['email' => 'nobody@example.com', 'role' => 'admin'])
            ->assertFailed();
    });
});

describe('admin account setup', function () {
    test('Given a fresh admin user, their role survives a reload', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertStatus(200);
        $admin->refresh();
        expect($admin->is_admin)->toBeTrue();
    });
});
