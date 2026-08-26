<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * يغطّي مرشّح created_by المُضاف إلى PaymentController@index — أساس صفحة
 * «ما تم استخلاصه» التي يرى فيها القابض استخلاصاته هو فقط. كما يؤكّد أن
 * القابض يصل إلى فهرس الدفعات (يكمّل CashierCollectionAuthTest دون تعديله).
 */
class PaymentsIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    /** يمنح المستخدم صلاحية بعينها ويُفعّله — نفس نمط makeCashier في عقد الصلاحيات. */
    private function grant(User $user, string $permission): User
    {
        $user->update(['is_active' => true]);
        $model = Permission::firstOrCreate(
            ['name' => $permission],
            ['display_name' => $permission, 'group' => 'Test']
        );
        $user->role->permissions()->syncWithoutDetaching($model->id);

        return $user->fresh(['role.permissions']);
    }

    public function test_created_by_filter_scopes_payments_to_that_user(): void
    {
        $enrollment = $this->makeEnrollment();

        $cashierA = $this->grant($this->makeUser('cashier_a'), 'manage_payments');
        $cashierB = $this->grant($this->makeUser('cashier_b'), 'manage_payments');

        // دفعتان للقابض A، وواحدة للقابض B.
        Payment::create([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 240,
            'payment_date'  => '2025-09-10',
            'method'        => 'cash',
            'created_by'    => $cashierA->id,
        ]);
        Payment::create([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 120,
            'payment_date'  => '2025-09-12',
            'method'        => 'cash',
            'created_by'    => $cashierA->id,
        ]);
        Payment::create([
            'student_id'    => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'amount'        => 300,
            'payment_date'  => '2025-09-15',
            'method'        => 'cash',
            'created_by'    => $cashierB->id,
        ]);

        Sanctum::actingAs($cashierA);

        $response = $this->getJson("/api/payments?created_by={$cashierA->id}&exclude_cancelled=1");

        $response->assertOk()->assertJsonPath('total', 2);

        // العلاقة createdBy المُحمَّلة تُسلسَل تحت مفتاح created_by نفسه، فنقرأ id منها.
        foreach ($response->json('data') as $row) {
            $this->assertSame($cashierA->id, $row['created_by']['id']);
        }
    }

    public function test_cashier_can_reach_payments_index(): void
    {
        $cashier = $this->makeCashier();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/payments?exclude_cancelled=1')->assertOk();
    }

    private function makeCashier(): User
    {
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);
        $permissions = ['manage_payments', 'manage_students', 'view_students'];
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'Test']);
            $user->role->permissions()->syncWithoutDetaching($permission->id);
        }

        return $user->fresh(['role.permissions']);
    }
}
