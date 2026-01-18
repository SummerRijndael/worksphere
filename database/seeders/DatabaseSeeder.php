<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        // Create Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'password' => \Illuminate\Support\Facades\Hash::make('Xachgamb@01'),
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('administrator')) {
            $admin->assignRole('administrator');
        }

        // Create Test User
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'username' => 'testuser',
                'password' => \Illuminate\Support\Facades\Hash::make('Xachgamb@01'),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }
    }
}
