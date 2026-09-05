<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * يثبت أن الوليّ يرى أبناءه فقط (مطابقة الهاتف المطبَّع) ولا يصل ابن غيره (403).
 */
class MobileParentScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_sees_only_own_children(): void
    {
        [$parent, $ownStudent, $enrollment] = $this->makeParentWithChild('20111222');

        // ابن وليّ آخر (هاتف مختلف تماماً).
        $otherEnrollment = $this->makeEnrollment();
        $otherEnrollment->student->update(['guardian_phone' => '99888777']);

        Sanctum::actingAs($parent);

        $response = $this->getJson('/api/mobile/parent/children')->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($ownStudent->id, $ids);
        $this->assertNotContains($otherEnrollment->student_id, $ids);
    }

    public function test_parent_cannot_read_other_childs_ledger(): void
    {
        [$parent] = $this->makeParentWithChild('20111222');

        $other = $this->makeEnrollment();
        $other->student->update(['guardian_phone' => '99888777']);

        Sanctum::actingAs($parent);

        $this->getJson("/api/mobile/parent/children/{$other->student_id}/ledger")
            ->assertForbidden();
    }

    public function test_parent_cannot_read_other_childs_receipts(): void
    {
        [$parent] = $this->makeParentWithChild('20111222');

        $other = $this->makeEnrollment();
        $other->student->update(['guardian_phone' => '99888777']);

        Sanctum::actingAs($parent);

        $this->getJson("/api/mobile/parent/children/{$other->student_id}/receipts")
            ->assertForbidden();
    }

    public function test_non_parent_role_is_blocked(): void
    {
        $cashier = $this->makeUser('cashier');
        $cashier->update(['is_active' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/mobile/parent/children')->assertForbidden();
    }

    /** @return array{0: User, 1: Student, 2: Enrollment} */
    private function makeParentWithChild(string $phone): array
    {
        $role = Role::firstOrCreate(['name' => 'parent'], ['display_name' => 'parent']);
        $perm = Permission::firstOrCreate(
            ['name' => 'view_own_children'],
            ['display_name' => 'view_own_children', 'group' => 'Mobile']
        );
        $role->permissions()->syncWithoutDetaching($perm->id);

        $parent = User::create([
            'first_name' => 'وليّ',
            'last_name' => $phone,
            'username' => 'parent_'.$phone,
            'email' => 'parent_'.$phone.'@parent.local',
            'phone' => $phone,
            'password' => 'secret123',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $enrollment = $this->makeEnrollment();
        $enrollment->student->update(['guardian_phone' => $phone]);

        return [$parent->fresh(['role.permissions']), $enrollment->student->fresh(), $enrollment];
    }
}
