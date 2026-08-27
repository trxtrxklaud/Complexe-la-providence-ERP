<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\FeeCategory;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->student = Student::create([
            'student_code' => 'PRV-DRY-001',
            'first_name' => 'ياسين',
            'last_name' => 'بن صالح',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $category = FeeCategory::create([
            'name' => 'معاليم النوادي',
            'code' => 'CLUB',
            'is_recurring' => true,
        ]);

        $this->club = Club::create([
            'name' => 'نادي الروبوتك',
            'fee_category_id' => $category->id,
            'monthly_fee' => 40,
            'is_active' => true,
        ]);
    }

    public function test_dry_run_does_not_change_the_database(): void
    {
        // دفعة تجريبية: مبلغ صفر وبلا توزيعات.
        $testPayment = Payment::create([
            'student_id' => $this->student->id,
            'amount' => 0,
            'payment_date' => '2026-08-16',
            'method' => 'cash',
            'created_by' => null,
        ]);

        // أولياء مكررون بعد تطبيع الهاتف.
        $guardian1 = Guardian::create([
            'first_name' => 'خالد',
            'last_name' => 'الربيحي',
            'phone' => '21717944',
            'address' => 'سيدي بوزيد',
        ]);
        $guardian2 = Guardian::create([
            'first_name' => 'Khalid',
            'last_name' => 'Al-Rabhi',
            'phone' => '+21621717944',
            'address' => 'سيدي بوزيد',
        ]);

        // معلوم نادٍ خارج نطاق السنة الدراسية (أوت 2026 قبل سبتمبر 2026).
        $outOfRangeFee = ClubMonthlyFee::create([
            'student_id' => $this->student->id,
            'club_id' => $this->club->id,
            'academic_year_id' => $this->year->id,
            'month' => '2026-08',
            'amount_due' => 40,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        // لقطة قبل التشغيل.
        $guardiansCount = Guardian::count();
        $paymentsCount = Payment::count();
        $clubFeesCount = ClubMonthlyFee::count();

        $this->artisan('cleanup:test-data')
            ->expectsOutputToContain('Dry-run only. No data was changed.')
            ->assertExitCode(0);

        // لم يُمسّ أي جدول.
        $this->assertSame($guardiansCount, Guardian::count());
        $this->assertSame($paymentsCount, Payment::count());
        $this->assertSame($clubFeesCount, ClubMonthlyFee::count());

        // الدفعة التجريبية لم تُلغَ.
        $this->assertNull($testPayment->fresh()->cancelled_at);

        // الولي المكرر لم يُحذف ولم يُعدَّل.
        $this->assertSame('21717944', Guardian::find($guardian1->id)->phone);
        $this->assertSame('+21621717944', Guardian::find($guardian2->id)->phone);

        // معلوم النادي خارج النطاق لم يُلمَس.
        $this->assertNull($outOfRangeFee->fresh()->cancelled_at);
        $this->assertSame('unpaid', $outOfRangeFee->fresh()->status);
    }

    private function makeClubFee(string $month, string $status, float $paid = 0.0): ClubMonthlyFee
    {
        return ClubMonthlyFee::create([
            'student_id' => $this->student->id,
            'club_id' => $this->club->id,
            'academic_year_id' => $this->year->id,
            'month' => $month,
            'amount_due' => 40,
            'amount_paid' => $paid,
            'status' => $status,
        ]);
    }

    public function test_apply_invalid_unpaid_club_fees_without_ids_fails_and_changes_nothing(): void
    {
        $candidate = $this->makeClubFee('2026-08', 'unpaid');

        $this->artisan('cleanup:test-data --apply-invalid-unpaid-club-fees')
            ->expectsOutputToContain('يجب تمرير قائمة IDs صريحة')
            ->assertExitCode(1);

        $this->assertNull($candidate->fresh()->cancelled_at);
    }

    public function test_apply_invalid_unpaid_club_fees_with_one_qualified_id_succeeds(): void
    {
        $candidate = $this->makeClubFee('2026-08', 'unpaid');

        $this->artisan('cleanup:test-data --apply-invalid-unpaid-club-fees='.$candidate->id)
            ->expectsOutputToContain('قائمة المرشحين (1)')
            ->expectsOutputToContain('تمت معالجة 1 سجلاً')
            ->assertExitCode(0);

        $fresh = $candidate->fresh();
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('unpaid', $fresh->status);
        $this->assertNotNull($fresh->cancellation_reason);
    }

    public function test_apply_invalid_unpaid_club_fees_rejects_entire_operation_if_any_id_is_paid(): void
    {
        $unpaid = $this->makeClubFee('2026-08', 'unpaid');
        $paid = $this->makeClubFee('2026-05', 'paid', 20.0);

        $ids = $unpaid->id.','.$paid->id;

        $this->artisan('cleanup:test-data --apply-invalid-unpaid-club-fees='.$ids)
            ->expectsOutputToContain('رُفضت العملية كاملة دون تعديل أي سجل')
            ->assertExitCode(1);

        $this->assertNull($unpaid->fresh()->cancelled_at);
        $this->assertNull($paid->fresh()->cancelled_at);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_apply_invalid_unpaid_club_fees_rejects_entire_operation_if_id_does_not_exist(): void
    {
        $candidate = $this->makeClubFee('2026-08', 'unpaid');
        $nonexistent = $candidate->id + 500;

        $this->artisan('cleanup:test-data --apply-invalid-unpaid-club-fees='.$nonexistent)
            ->expectsOutputToContain('غير موجود')
            ->assertExitCode(1);

        $this->assertNull($candidate->fresh()->cancelled_at);
    }

    public function test_apply_invalid_unpaid_club_fees_does_not_touch_payments_or_treasury(): void
    {
        $candidate = $this->makeClubFee('2026-08', 'unpaid');
        $realPayment = Payment::create([
            'student_id' => $this->student->id,
            'amount' => 50,
            'payment_date' => '2026-08-16',
            'method' => 'cash',
            'created_by' => null,
        ]);

        $cashBefore = CashTransaction::count();

        $this->artisan('cleanup:test-data --apply-invalid-unpaid-club-fees='.$candidate->id)
            ->assertExitCode(0);

        $this->assertNotNull($candidate->fresh()->cancelled_at);
        $this->assertNull($realPayment->fresh()->cancelled_at);
        $this->assertSame($cashBefore, CashTransaction::count());
    }

    public function test_inspect_invalid_unpaid_club_fees_is_read_only(): void
    {
        $candidate = $this->makeClubFee('2026-08', 'unpaid');

        $counts = [
            'guardians' => Guardian::count(),
            'payments' => Payment::count(),
            'fees' => ClubMonthlyFee::count(),
            'cash' => CashTransaction::count(),
        ];

        $this->artisan('cleanup:test-data --inspect-invalid-unpaid-club-fees')
            ->expectsOutputToContain('Read-only inspection. No data was changed.')
            ->expectsOutputToContain('العدد الإجمالي للمرشحين: 1')
            ->assertExitCode(0);

        $this->assertSame($counts['guardians'], Guardian::count());
        $this->assertSame($counts['payments'], Payment::count());
        $this->assertSame($counts['fees'], ClubMonthlyFee::count());
        $this->assertSame($counts['cash'], CashTransaction::count());
        $this->assertNull($candidate->fresh()->cancelled_at);
    }
}