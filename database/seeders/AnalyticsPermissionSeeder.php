<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AnalyticsPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permission if it doesn't exist
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view_analytics']);

        // Assign to Administrator (Super Admin)
        $admin = \Spatie\Permission\Models\Role::where('name', 'administrator')->first();
        if ($admin) {
            $admin->givePermissionTo($permission);
        }

        // Project Manager might need it?
        $pm = \Spatie\Permission\Models\Role::where('name', 'project_manager')->first();
        if ($pm) {
            $pm->givePermissionTo($permission);
        }
    }
}
