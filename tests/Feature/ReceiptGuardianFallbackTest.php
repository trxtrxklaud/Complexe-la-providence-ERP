<?php

namespace Tests\Feature;

use App\Http\Controllers\CollectionController;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Services\CollectionService;
use App\Services\FamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * حارس بيانات وليّ الأمر في الوصل.
 *
 * العطب: الوصل كان يقرأ الوليّ من جدول الربط guardian_student فقط. تلاميذ
 * الاستيراد ليست لهم صفوف في ذلك الجدول، وبيانات أوليائهم محفوظة في أعمدة
 * students المسطَّحة — فكان الوصل يُطبع بلا اسم الوليّ ولا هاتفه رغم توفّر
 * البيانات. هذه الاختبارات تثبّت التراجع، وتثبّت أن الربط يبقى مُقدَّماً عليه.
 *
 * الإصلاح طبقة عرض بحتة: لا يكتب شيئاً، ولا يمسّ مبلغاً ولا قيداً نقدياً،
 * ولا يغيّر تجميع العائلات.
 */
class ReceiptGuardianFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function collect(int $studentId, int $enrollmentId, int $feeTypeId, int $userId): array
    {
        return app(CollectionService::class)->collect([
            'student_id' => $studentId,
            'enrollment_id' => $enrollmentId,
            'months' => ['2025-09'],
            'payment_date' => '2025-09-05',
            'method' => 'cash',
            'items' => [['fee_type_id' => $feeTypeId, 'amount' => 240]],
        ], $userId);
    }

    public function test_receipt_uses_pivot_guardian_when_the_link_row_exists(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $guardian = Guardian::create([
            'first_name' => 'كمال',
            'last_name' => 'الترهوني',
            'phone' => '20111222',
            'email' => 'kamel@test.local',
            'address' => 'سيدي بوزيد',
        ]);

        // الأعمدة المسطَّحة تحمل قيماً مختلفة: يجب ألا تُستعمل حين يوجد الربط.
        $enrollment->student->update([
            'guardian_first_name' => 'مسطَّح',
            'guardian_last_name' => 'لا يُستعمل',
            'guardian_phone' => '99999999',
        ]);

        $enrollment->student->guardians()->attach($guardian->id, [
            'relationship' => 'primary',
            'is_primary_contact' => true,
        ]);

        $receipt = $this->collect($enrollment->student_id, $enrollment->id, $feeType->id, $user->id);

        $this->assertSame('كمال', $receipt['guardian']['first_name']);
        $this->assertSame('الترهوني', $receipt['guardian']['last_name']);
        $this->assertSame('20111222', $receipt['guardian']['phone']);
        $this->assertSame('kamel@test.local', $receipt['guardian']['email']);
    }

    public function test_receipt_falls_back_to_the_flat_student_columns_without_a_pivot_row(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $student = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'ريم',
            'last_name' => 'بن عيسى',
            'gender' => 'female',
            'status' => 'active',
            'guardian_first_name' => 'محمد',
            'guardian_last_name' => 'بن عيسى',
            'guardian_phone' => '25334455',
            'guardian_email' => 'mohamed@test.local',
        ]);

        $enrollment = $this->makeEnrollment(null, $student);
        $feeType = $this->makeFeeType();

        $this->assertDatabaseCount('guardian_student', 0);
        // إسناد جَماعي لا forceFill: يحرس أيضاً بقاء guardian_email في $fillable.
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'guardian_email' => 'mohamed@test.local',
        ]);

        $receipt = $this->collect($student->id, $enrollment->id, $feeType->id, $user->id);

        $this->assertNotNull($receipt['guardian'], 'الوصل طُبع بلا وليّ رغم توفّر البيانات المسطَّحة');
        $this->assertSame('محمد', $receipt['guardian']['first_name']);
        $this->assertSame('بن عيسى', $receipt['guardian']['last_name']);
        $this->assertSame('25334455', $receipt['guardian']['phone']);
        $this->assertSame('mohamed@test.local', $receipt['guardian']['email']);
    }

    /**
     * صفوف الاستيراد القديمة كُتبت في القاعدة مباشرة، لا عبر الإسناد الجَماعي.
     * هذا هو الموضع الوحيد الذي يستعمل forceFill: محاكاة ذلك المسار حرفياً.
     */
    public function test_receipt_falls_back_for_legacy_import_rows_written_straight_to_the_database(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $student = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'هالة',
            'last_name' => 'العلوي',
            'gender' => 'female',
            'status' => 'active',
        ]);
        $student->forceFill([
            'guardian_first_name' => 'فتحي',
            'guardian_last_name' => 'العلوي',
            'guardian_phone' => '22110099',
            'guardian_email' => 'fethi@test.local',
        ])->save();

        $enrollment = $this->makeEnrollment(null, $student);
        $feeType = $this->makeFeeType();

        $receipt = $this->collect($student->id, $enrollment->id, $feeType->id, $user->id);

        $this->assertSame('فتحي', $receipt['guardian']['first_name']);
        $this->assertSame('22110099', $receipt['guardian']['phone']);
        $this->assertSame('fethi@test.local', $receipt['guardian']['email']);
    }

    public function test_receipt_guardian_stays_null_when_no_source_holds_any_data(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $receipt = $this->collect($enrollment->student_id, $enrollment->id, $feeType->id, $user->id);

        $this->assertNull($receipt['guardian'], 'بلا أيّ بيانات وليّ يجب أن يبقى الحقل null كما كان');
    }

    public function test_the_stored_receipt_snapshot_carries_the_fallback_guardian(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $student = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'أيوب',
            'last_name' => 'الساحلي',
            'gender' => 'male',
            'status' => 'active',
            'guardian_first_name' => 'صالح',
            'guardian_last_name' => 'الساحلي',
            'guardian_phone' => '27889900',
        ]);

        $enrollment = $this->makeEnrollment(null, $student);
        $feeType = $this->makeFeeType();

        $receipt = $this->collect($student->id, $enrollment->id, $feeType->id, $user->id);

        // meta هي اللقطة الثابتة التي تُعاد عند إعادة إرسال نفس الطلب.
        $meta = Payment::findOrFail($receipt['payment_id'])->meta;

        $this->assertSame('صالح', $meta['guardian']['first_name']);
        $this->assertSame('27889900', $meta['guardian']['phone']);
    }

    public function test_section_students_list_shows_the_fallback_guardian(): void
    {
        $year = $this->makeAcademicYear();

        $withPivot = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'سلمى',
            'last_name' => 'الغربي',
            'gender' => 'female',
            'status' => 'active',
        ]);
        $enrollment = $this->makeEnrollment($year, $withPivot);
        $section = Section::findOrFail($enrollment->section_id);

        $guardian = Guardian::create([
            'first_name' => 'حاتم',
            'last_name' => 'الغربي',
            'phone' => '21445566',
            'address' => 'سيدي بوزيد',
        ]);
        $withPivot->guardians()->attach($guardian->id, [
            'relationship' => 'primary',
            'is_primary_contact' => true,
        ]);

        $flatOnly = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'زياد',
            'last_name' => 'المرزوقي',
            'gender' => 'male',
            'status' => 'active',
            'guardian_first_name' => 'نبيل',
            'guardian_last_name' => 'المرزوقي',
            'guardian_phone' => '26778899',
        ]);
        Enrollment::create([
            'student_id' => $flatOnly->id,
            'academic_year_id' => $year->id,
            'level_id' => $enrollment->level_id,
            'section_id' => $section->id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $request = Request::create('/api/collection/sections/'.$section->id.'/students', 'GET', [
            'year_id' => $year->id,
        ]);

        $rows = collect(app(CollectionController::class)->studentsBySection($section, $request)->getData(true));

        $pivotRow = $rows->firstWhere('student.id', $withPivot->id);
        $flatRow = $rows->firstWhere('student.id', $flatOnly->id);

        $this->assertSame('حاتم', $pivotRow['guardian']['first_name']);
        $this->assertSame('21445566', $pivotRow['guardian']['phone']);

        $this->assertNotNull($flatRow['guardian'], 'قائمة القسم أظهرت التلميذ بلا وليّ رغم توفّر البيانات المسطَّحة');
        $this->assertSame('نبيل', $flatRow['guardian']['first_name']);
        $this->assertSame('26778899', $flatRow['guardian']['phone']);
    }

    public function test_the_primary_contact_is_preferred_among_several_pivot_guardians(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $enrollment = $this->makeEnrollment();
        $feeType = $this->makeFeeType();

        $secondary = Guardian::create([
            'first_name' => 'ثانوي',
            'last_name' => 'الاتصال',
            'phone' => '20000001',
            'address' => 'سيدي بوزيد',
        ]);
        $primary = Guardian::create([
            'first_name' => 'أساسي',
            'last_name' => 'الاتصال',
            'phone' => '20000002',
            'address' => 'سيدي بوزيد',
        ]);

        $enrollment->student->guardians()->attach($secondary->id, [
            'relationship' => 'secondary',
            'is_primary_contact' => false,
        ]);
        $enrollment->student->guardians()->attach($primary->id, [
            'relationship' => 'primary',
            'is_primary_contact' => true,
        ]);

        $receipt = $this->collect($enrollment->student_id, $enrollment->id, $feeType->id, $user->id);

        $this->assertSame('أساسي', $receipt['guardian']['first_name']);
        $this->assertSame('20000002', $receipt['guardian']['phone']);
    }

    public function test_family_grouping_by_phone_is_untouched_by_the_receipt_fallback(): void
    {
        $year = $this->makeAcademicYear();

        // شقيقان بلا صفوف ربط، يجمعهما هاتف الوليّ نفسه بصيغتين مختلفتين.
        $first = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'أمين',
            'last_name' => 'الحمروني',
            'gender' => 'male',
            'status' => 'active',
            'guardian_first_name' => 'رضا',
            'guardian_last_name' => 'الحمروني',
            'guardian_phone' => '24556677',
        ]);
        $second = Student::create([
            'student_code' => 'STU-'.substr(uniqid(), -6),
            'first_name' => 'إيناس',
            'last_name' => 'الحمروني',
            'gender' => 'female',
            'status' => 'active',
            'guardian_first_name' => 'رضا',
            'guardian_last_name' => 'الحمروني',
            'guardian_phone' => '+216 24 556 677',
        ]);

        $enrollment = $this->makeEnrollment($year, $first);
        Enrollment::create([
            'student_id' => $second->id,
            'academic_year_id' => $year->id,
            'level_id' => $enrollment->level_id,
            'section_id' => $enrollment->section_id,
            'enrollment_date' => '2025-09-01',
            'status' => 'active',
        ]);

        $families = collect(app(FamilyService::class)->listFamilies()['data'] ?? []);
        $family = $families->firstWhere('guardian_name', 'رضا الحمروني');

        $this->assertNotNull($family, 'تجميع العائلات بالهاتف تغيّر بعد إصلاح الوصل');
        $this->assertSame(2, (int) $family['students_count']);
    }
}
