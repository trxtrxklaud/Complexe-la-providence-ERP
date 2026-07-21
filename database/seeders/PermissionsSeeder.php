<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Models\Role;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'manage_users',    'display_name' => 'إدارة المستخدمين', 'group' => 'Users'],
            ['name' => 'enroll_student',  'display_name' => 'تسجيل طالب',       'group' => 'Students'],
            ['name' => 'view_students',   'display_name' => 'عرض الطلاب',       'group' => 'Students'],
            ['name' => 'manage_payments', 'display_name' => 'إدارة المدفوعات',  'group' => 'Finance'],
            ['name' => 'view_reports',    'display_name' => 'عرض التقارير',      'group' => 'Finance'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p['name']], $p);
        }

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->permissions()->syncWithoutDetaching(
                Permission::pluck('id')
            );
        }

        $this->command->info('✅ Permissions seeded and assigned to admin.');
    }
}
