<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create all permissions from config
        $globalPermissions = config('roles.global_permissions', []);
        $teamPermissions = config('roles.team_permissions', []);
        $permissionGroups = array_merge_recursive($globalPermissions, $teamPermissions);
        $allPermissions = [];

        foreach ($permissionGroups as $group => $permissions) {
            foreach ($permissions as $permission => $description) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
                $allPermissions[] = $permission;
            }
        }

        $this->command->info('Created '.count($allPermissions).' permissions.');

        // Create roles and assign permissions
        $rolesConfig = config('roles.roles', []);

        foreach ($rolesConfig as $roleName => $roleConfig) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $rolePermissions = $roleConfig['permissions'] ?? [];

            // Ensure all assigned permissions exist
            if (! in_array('*', $rolePermissions)) {
                foreach ($rolePermissions as $perm) {
                    if (! in_array($perm, $allPermissions)) {
                        $this->command->warn("Permission '{$perm}' found in role '{$roleName}' but not in permission definitions. Creating it now.");
                        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
                        $allPermissions[] = $perm;
                    }
                }
            }

            // Handle wildcard permissions (administrator gets all)
            if (in_array('*', $rolePermissions)) {
                $role->syncPermissions($allPermissions);
                $this->command->info("Role '{$roleName}' granted all permissions.");
            } else {
                $role->syncPermissions($rolePermissions);
                $this->command->info("Role '{$roleName}' granted ".count($rolePermissions).' permissions.');
            }
        }

        $this->command->info('Roles and permissions seeded successfully!');
    }
}
