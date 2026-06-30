<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest is redirected to login when accessing admin route', function () {
    $this->get(route('admin.dashboard'))
        ->assertRedirect('/auth/google');
});

test('non-admin user gets 403 on admin route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertStatus(403);
});

test('admin user can access admin route', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
        );
});
