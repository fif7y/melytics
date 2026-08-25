<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeUser extends Command
{
    protected $signature = 'melytics:user {email} {--name=Admin}';

    protected $description = 'Create a dashboard user (registration is intentionally closed)';

    public function handle(): int
    {
        $password = $this->secret('Password');
        if (! $password || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        User::create([
            'email' => $this->argument('email'),
            'name' => $this->option('name'),
            'password' => $password,
        ]);
        $this->info('User created.');

        return self::SUCCESS;
    }
}
