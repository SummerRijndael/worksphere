<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class VerifyUser extends Command
{
    protected $signature = 'user:verify {email}';

    public function handle()
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user) {
            $user->markEmailAsVerified();
            $this->info("User verified.");
        } else {
            $this->error("User not found.");
        }
    }
}
