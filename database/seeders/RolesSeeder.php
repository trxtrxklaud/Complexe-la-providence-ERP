<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'cashier' => [
                'display_name' => 'قابض',
                'permissions' => ['manage_payments', 'manage_students', 'view_students'],
            ],
            'accountant' => [
                'display_name' => 'إداري مالي',
                'permissions' => [
                    'manage_expenses',
                    'manage_treasury',
                    'manage_salaries',
                    'manage_employees',
                    'view_reports',
                ],
            ],
        ];

        foreach ($roles as $name => $data) {
            $role = Role::firstOrCreate(
                ['name' => $name],
                ['display_name' => $data['display_name']]
            );

            $role->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', $data['permissions'])->pluck('id')
            );
        }
    }
}
