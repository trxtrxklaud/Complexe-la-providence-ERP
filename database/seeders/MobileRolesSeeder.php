<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/*
|--------------------------------------------------------------------------
| MobileRolesSeeder — أدوار وصلاحيات طبقة تطبيقات الجوال (إضافية بحتة)
|--------------------------------------------------------------------------
|
| منفصل تماماً عن RolesSeeder / PermissionsSeeder حتى لا نكسر عقودهما
| (خصوصاً RolesSeederTest). يضيف:
|   - صلاحيات جديدة للمعلّم والوليّ.
|   - دورَي teacher و parent وربطهما بصلاحياتهما.
| يستعمل firstOrCreate + syncWithoutDetaching فيبقى idempotent وآمناً
| لإعادة التشغيل، ولا يلمس دور admin/cashier/accountant ولا حِزَمها.
|
*/

class MobileRolesSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'view_own_children',    'display_name' => 'عرض بيانات الأبناء',      'group' => 'Mobile'],
            ['name' => 'view_own_sections',    'display_name' => 'عرض أقسام المعلّم',        'group' => 'Mobile'],
            ['name' => 'manage_attendance',    'display_name' => 'إدارة الحضور والغياب',     'group' => 'Mobile'],
            ['name' => 'manage_grades',        'display_name' => 'إدارة الأعداد والنتائج',   'group' => 'Mobile'],
            ['name' => 'view_announcements',   'display_name' => 'عرض الإعلانات',            'group' => 'Mobile'],
            ['name' => 'manage_announcements', 'display_name' => 'إدارة الإعلانات',          'group' => 'Mobile'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p['name']], $p);
        }

        $roles = [
            'teacher' => [
                'display_name' => 'معلّم',
                'permissions' => [
                    'view_own_sections',
                    'manage_attendance',
                    'manage_grades',
                    'view_announcements',
                    'manage_announcements',
                ],
            ],
            'parent' => [
                'display_name' => 'وليّ',
                'permissions' => [
                    'view_own_children',
                    'view_announcements',
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

        if ($this->command) {
            $this->command->info('✅ Mobile roles (teacher, parent) and permissions seeded.');
        }
    }
}
