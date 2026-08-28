<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\Level;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreClubsAndUsersTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year2026;
    private Level $levelL1;
    private Section $sectionA;
    private Role $adminRole;
    private Role $cashierRole;
    private Role $accountantRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year2026 = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-15',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $levelsToCreate = [
            ['code' => 'L1', 'name' => 'السنة الأولى', 'order' => 1],
            ['code' => 'L2', 'name' => 'السنة الثانية', 'order' => 2],
            ['code' => 'L3', 'name' => 'السنة الثالثة', 'order' => 3],
            ['code' => 'L4', 'name' => 'السنة الرابعة', 'order' => 4],
            ['code' => 'L5', 'name' => 'السنة الخامسة', 'order' => 5],
            ['code' => 'L6', 'name' => 'السنة السادسة', 'order' => 6],
            ['code' => 'PRE1', 'name' => 'روضة', 'order' => 7],
            ['code' => 'PRE2', 'name' => 'تمهيدي', 'order' => 8],
            ['code' => 'PRE3', 'name' => 'تحضيري', 'order' => 9],
        ];
        foreach ($levelsToCreate as $lvl) {
            $created = Level::firstOrCreate(['code' => $lvl['code']], $lvl);
            if ($lvl['code'] === 'L1') {
                $this->levelL1 = $created;
            }
        }

        $this->sectionA = Section::create([
            'level_id' => $this->levelL1->id,
            'name' => 'أ',
            'code' => 'L1-أ',
            'capacity' => 30,
        ]);

        FeeCategory::firstOrCreate(['code' => 'scolarite'], ['name' => 'الرسوم الدراسية', 'is_recurring' => true]);
        FeeCategory::firstOrCreate(['code' => 'CLUB'], ['name' => 'معاليم النوادي', 'is_recurring' => true]);

        $this->adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير النظام']);
        $this->cashierRole = Role::firstOrCreate(['name' => 'cashier'], ['display_name' => 'قابض']);
        $this->accountantRole = Role::firstOrCreate(['name' => 'accountant'], ['display_name' => 'إداري مالي']);

        $permissions = [
            'manage_users', 'enroll_student', 'view_students', 'manage_payments',
            'view_reports', 'manage_students', 'manage_employees', 'manage_salaries',
            'manage_expenses', 'manage_treasury', 'waive_fees', 'manage_user_permissions'
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p], ['display_name' => $p, 'group' => 'General']);
        }

        // Create existing admin user
        User::create([
            'email' => 'admin@laprovidence.ma',
            'first_name' => 'مدير',
            'last_name' => 'النظام',
            'username' => 'admin',
            'password' => bcrypt('AdminPassword123'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
        ]);
    }

    public function test_restore_clubs_and_users_dry_run_does_not_commit(): void
    {
        $this->artisan('legacy:restore-clubs-users', ['--dry-run' => true])
            ->assertSuccessful();

        // Ensure no new users or clubs were written in DB
        $this->assertEquals(1, User::count());
        $this->assertEquals(0, Club::count());
        $this->assertEquals(0, ClubSubscription::count());
        $this->assertEquals(0, ClubMonthlyFee::count());
    }

    public function test_restore_clubs_and_users_executes_successfully_and_is_idempotent(): void
    {
        $adminInitialPassword = User::where('username', 'admin')->first()->password;

        $this->artisan('legacy:restore-clubs-users')
            ->assertSuccessful();

        // 1. Check Users Restored
        $this->assertEquals(3, User::count());
        $fathia = User::where('username', 'fathia')->first();
        $this->assertNotNull($fathia);
        $this->assertEquals($this->cashierRole->id, $fathia->role_id);
        $this->assertTrue($fathia->is_active);

        $mohedine = User::where('username', 'Mr.mohedine')->first();
        $this->assertNotNull($mohedine);
        $this->assertEquals($this->accountantRole->id, $mohedine->role_id);

        // Admin password was NOT overwritten
        $this->assertEquals($adminInitialPassword, User::where('username', 'admin')->first()->password);

        // 2. Check Overrides
        $overrides = UserPermissionOverride::where('user_id', $mohedine->id)->get();
        $this->assertGreaterThanOrEqual(1, $overrides->count());

        // 3. Check Clubs Restored
        $this->assertCount(2, Club::all());
        $this->assertDatabaseHas('clubs', ['name' => 'الحساب الذهني']);
        $this->assertDatabaseHas('clubs', ['name' => 'الروبوتيك']);

        // 4. Check Idempotency (running a 2nd time doesn't duplicate)
        $this->artisan('legacy:restore-clubs-users')
            ->assertSuccessful();

        $this->assertEquals(3, User::count());
        $this->assertCount(2, Club::all());
    }
}
