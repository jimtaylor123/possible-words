<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class UserRoleCommand extends Command
{
    protected $signature = 'user:role {email : The email of the user} {role : admin or user}';

    protected $description = 'Promote or demote a user to admin';

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->argument('role');

        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        if (! in_array($role, ['admin', 'user'], true)) {
            $this->error('Role must be "admin" or "user".');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        $isAdmin = $role === 'admin';

        if ($user->is_admin === $isAdmin) {
            $this->info("User '{$email}' is already {$role}.");

            return self::SUCCESS;
        }

        $user->update(['is_admin' => $isAdmin]);

        $action = $role === 'admin' ? 'promoted to admin' : 'demoted to user';

        $this->info("User '{$email}' has been {$action}.");

        return self::SUCCESS;
    }
}
