<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class ShieldSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $rolesWithPermissions = '[{"name":"super_admin","guard_name":"web","permissions":["create_application","create_city","create_clinic","create_doctor","create_review","create_role","delete_any_application","delete_any_city","delete_any_clinic","delete_any_doctor","delete_any_review","delete_any_role","delete_application","delete_city","delete_clinic","delete_doctor","delete_review","delete_role","force_delete_any_application","force_delete_any_city","force_delete_any_clinic","force_delete_any_doctor","force_delete_any_review","force_delete_application","force_delete_city","force_delete_clinic","force_delete_doctor","force_delete_review","reorder_application","reorder_city","reorder_clinic","reorder_doctor","reorder_review","replicate_application","replicate_city","replicate_clinic","replicate_doctor","replicate_review","restore_any_application","restore_any_city","restore_any_clinic","restore_any_doctor","restore_any_review","restore_application","restore_city","restore_clinic","restore_doctor","restore_review","update_application","update_city","update_clinic","update_doctor","update_review","update_role","view_any_application","view_any_city","view_any_clinic","view_any_doctor","view_any_review","view_any_role","view_application","view_city","view_clinic","view_doctor","view_review","view_role"]}]';
        $directPermissions = '[]';

        static::makeRolesWithPermissions($rolesWithPermissions);
        static::makeDirectPermissions($directPermissions);

        $this->command->info('Shield Seeding Completed.');
    }

    protected static function makeRolesWithPermissions(string $rolesWithPermissions): void
    {
        if (! blank($rolePlusPermissions = json_decode($rolesWithPermissions, true))) {
            /** @var Model $roleModel */
            $roleModel = Utils::getRoleModel();
            /** @var Model $permissionModel */
            $permissionModel = Utils::getPermissionModel();

            foreach ($rolePlusPermissions as $rolePlusPermission) {
                $role = $roleModel::firstOrCreate([
                    'name' => $rolePlusPermission['name'],
                    'guard_name' => $rolePlusPermission['guard_name'],
                ]);

                if (! blank($rolePlusPermission['permissions'])) {
                    $permissionModels = collect($rolePlusPermission['permissions'])
                        ->map(fn ($permission) => $permissionModel::firstOrCreate([
                            'name' => $permission,
                            'guard_name' => $rolePlusPermission['guard_name'],
                        ]))
                        ->all();

                    $role->syncPermissions($permissionModels);
                }
            }
        }
    }

    public static function makeDirectPermissions(string $directPermissions): void
    {
        if (! blank($permissions = json_decode($directPermissions, true))) {
            /** @var Model $permissionModel */
            $permissionModel = Utils::getPermissionModel();

            foreach ($permissions as $permission) {
                if ($permissionModel::whereName($permission)->doesntExist()) {
                    $permissionModel::create([
                        'name' => $permission['name'],
                        'guard_name' => $permission['guard_name'],
                    ]);
                }
            }
        }
    }
}
