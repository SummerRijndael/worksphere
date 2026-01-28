<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Reset Global Roles and Permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Remove legacy global roles
        $legacyRoles = ['project_manager', 'client', 'operator']; // 'operator' was requested to be removed from global if it existed
        Role::whereIn('name', $legacyRoles)->delete();

        // Seed new Global Roles (Administrator, IT Support, User)
        // We rely on RolesAndPermissionsSeeder logic, but we can do it explicitly here to be safe and immediate
        // Seed new Global Roles (Administrator, IT Support, User)
        // We rely on RolesAndPermissionsSeeder logic, but we can do it explicitly here to be safe and immediate
        $rolesConfig = config('roles.roles');
        $globalPermissions = config('roles.global_permissions', []);
        $teamPermissions = config('roles.team_permissions', []);
        $permissionGroups = array_merge_recursive($globalPermissions, $teamPermissions);

        // Create all permissions first
        $allPermissions = collect($permissionGroups)->flatMap(function ($group) {
            return array_keys($group);
        })->values();

        foreach ($allPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        foreach ($rolesConfig as $name => $config) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            // Assign Permissions
            if (isset($config['permissions'])) {
                if (in_array('*', $config['permissions'])) {
                    $role->givePermissionTo(Permission::all());
                } else {
                    $role->syncPermissions($config['permissions']);
                }
            }
        }

        // 2. Reset Team Roles
        // Truncate team_roles and team_role_permissions
        // Checks foreign key constraints? Usually cascade, but let's be safe.
        Schema::disableForeignKeyConstraints();
        DB::table('team_role_permissions')->truncate();
        DB::table('team_roles')->truncate();
        Schema::enableForeignKeyConstraints();

        // 3. Migrate Teams
        // For each team: create default roles, map existing members
        Team::chunk(100, function ($teams) {
            foreach ($teams as $team) {
                // Create new default roles for the team
                $team->createDefaultRoles();

                // Map existing members to new roles
                $members = DB::table('team_user')->where('team_id', $team->id)->get();

                foreach ($members as $member) {
                    $newRoleSlug = $this->mapLegacyRole($member->role);
                    $newTeamRole = $team->roles()->where('slug', $newRoleSlug)->first();

                    if ($newTeamRole) {
                        DB::table('team_user')
                            ->where('id', $member->id) // pivot id usually
                            ->update([
                                'role' => $newRoleSlug,
                                'team_role_id' => $newTeamRole->id,
                            ]);
                    }
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // It's a destructive one-way migration effectively.
        // We could define logic to restore old roles, but user said "don't worry about existing data".
    }

    protected function mapLegacyRole(string $oldRole): string
    {
        $oldRole = strtolower($oldRole);

        return match ($oldRole) {
            'owner' => 'team_lead', // Owner becomes Team Lead
            'admin' => 'subject_matter_expert', // Admin becomes SME
            'member' => 'operator', // Member becomes Operator (Default)
            'viewer' => 'operator', // Viewer becomes Operator
            default => 'operator',
        };
    }
};
