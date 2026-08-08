<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_does_not_receive_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('cashier', ['manage_payments', 'manage_students']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // بيانات التلاميذ والمتخلَّد تبقى متاحة للقابض
        $this->assertArrayHasKey('total_students', $data);
        $this->assertArrayHasKey('outstanding_balance', $data);

        // الجرد النقدي محجوب تماماً على من لا يملك manage_treasury/view_reports
        $this->assertArrayNotHasKey('cash', $data);
        $this->assertArrayNotHasKey('treasury_balance', $data);
        $this->assertArrayNotHasKey('financial_summary', $data);
    }

    public function test_report_viewer_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('report_viewer', ['view_reports']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_treasury_manager_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('treasurer', ['manage_treasury']));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // manage_treasury وحدها تكفي — إثبات دلالة "أو"
        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    public function test_admin_super_role_receives_financial_data(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions('admin', []));
        $this->makeAcademicYear();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // الدور الخارق يتجاوز الفحص التفصيلي كما في CheckPermission
        $this->assertArrayHasKey('cash', $data);
        $this->assertArrayHasKey('treasury_balance', $data);
        $this->assertArrayHasKey('financial_summary', $data);
    }

    /**
     * @param  array<int,string>  $permissions
     */
    private function makeUserWithPermissions(string $roleName, array $permissions): User
    {
        $user = $this->makeUser($roleName);
        $user->update(['is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => 'Test']
            );
            $user->role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $user;
    }
}
