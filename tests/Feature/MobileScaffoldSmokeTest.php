<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileScaffoldSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tables_exist(): void
    {
        foreach (['section_teacher', 'otp_codes', 'device_tokens', 'attendances', 'student_results', 'announcements'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "missing table: $t");
        }
        $this->assertTrue(Schema::hasColumn('employees', 'user_id'), 'employees.user_id missing');
    }

    public function test_mobile_roles_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\MobileRolesSeeder::class);
        $this->seed(\Database\Seeders\MobileRolesSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'teacher']);
        $this->assertDatabaseHas('roles', ['name' => 'parent']);
        $this->assertDatabaseHas('permissions', ['name' => 'manage_attendance']);
        $this->assertSame(1, \App\Models\Role::where('name', 'parent')->count());
    }
}
