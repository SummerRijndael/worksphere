<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ReviewPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'reviews.view',
            'reviews.create',
            'reviews.moderate',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Assign moderation permission to administrator
        $adminRole = Role::findByName('administrator', 'web');
        $adminRole->givePermissionTo('reviews.moderate');
    }
}
