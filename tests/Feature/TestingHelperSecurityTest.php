<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('testing helpers reject non-testing environments even when the legacy flag is enabled', function () {
    config(['app.e2e_auth_enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    $this->post('/testing/login', [
        'email' => 'attacker@example.test',
        'is_admin' => 1,
    ])->assertNotFound();
});
