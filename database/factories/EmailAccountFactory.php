<?php

namespace Database\Factories;

use App\Enums\EmailSyncStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'email' => $this->faker->unique()->safeEmail(),
            'provider' => 'custom',
            'auth_type' => 'password',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => $this->faker->userName(),
            'password' => 'secret',
            'is_active' => true,
            'is_verified' => true,
            'is_default' => false,
            'is_system' => false,
            'sync_status' => EmailSyncStatus::Pending,
        ];
    }
}
