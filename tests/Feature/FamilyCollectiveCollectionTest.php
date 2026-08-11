<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\Section;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\PaymentAllocation;
use App\Models\FeeCategory;
use App\Models\Level;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyCollectiveCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Guardian $guardian;
    protected Student $student1;
    protected Student $student2;
    protected Enrollment $enrollment1;
    protected Enrollment $enrollment2;
    protected StudentFee $fee1;
    protected StudentFee $fee2;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = \App\Models\Permission::create([
            'name' => 'manage_payments',
            'display_name' => 'إدارة التحصيل والدفعات',
            'module' => 'finance',
        ]);

        $role = \App\Models\Role::create([
            'name' => 'admin',
            'display_name' => 'مدير النظام',
        ]);
        $role->permissions()->attach($permission->id);

        $this->user = User::create([
            'role_id' => $role->id,
            'username' => 'admin_test',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->actingAs($this->user);

        $year = AcademicYear::create([
            'name' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
            'is_current' => true,
        ]);

        $level = Level::create(['name' => 'المستوى الأول', 'code' => 'L1']);
        $section = Section::create(['level_id' => $level->id, 'name' => 'أ', 'code' => 'L1-A', 'capacity' => 30]);

        $this->guardian = Guardian::create([
            'first_name' => 'علي',
            'last_name' => 'بن صالح',
            'phone' => '99887766',
            'address' => 'قفصة',
        ]);

        $this->student1 = Student::create([
            'student_code' => 'PRV-TEST-001',
            'first_name' => 'ياسين',
            'last_name' => 'بن صالح',
            'gender' => 'boy',
            'birth_date' => '2018-01-01',
            'guardian_phone' => '99887766',
        ]);

        $this->student2 = Student::create([
            'student_code' => 'PRV-TEST-002',
            'first_name' => 'مريم',
            'last_name' => 'بن صالح',
            'gender' => 'girl',
            'birth_date' => '2019-05-05',
            'guardian_phone' => '99887766',
        ]);

        $this->guardian->students()->attach($this->student1->id);
        $this->guardian->students()->attach($this->student2->id);

        $this->enrollment1 = Enrollment::create([
            'student_id' => $this->student1->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->enrollment2 = Enrollment::create([
            'student_id' => $this->student2->id,
            'academic_year_id' => $year->id,
            'level_id' => $level->id,
            'section_id' => $section->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $feeType = FeeType::create([
            'name_ar' => 'معلوم شهر أكتوبر',
            'name_fr' => 'Frais Octobre',
            'code' => 'monthly_october',
            'is_recurring' => true,
        ]);

        $this->fee1 = StudentFee::create([
            'student_id' => $this->student1->id,
            'enrollment_id' => $this->enrollment1->id,
            'fee_type_id' => $feeType->id,
            'amount_due' => 190.00,
            'due_date' => '2026-10-01',
            'status' => 'pending',
            'description' => 'معلوم شهر أكتوبر - ياسين',
        ]);

        $this->fee2 = StudentFee::create([
            'student_id' => $this->student2->id,
            'enrollment_id' => $this->enrollment2->id,
            'fee_type_id' => $feeType->id,
            'amount_due' => 190.00,
            'due_date' => '2026-10-01',
            'status' => 'pending',
            'description' => 'معلوم شهر أكتوبر - مريم',
        ]);
    }

    public function test_family_collective_collection_creates_single_payment_and_correct_allocations(): void
    {
        $response = $this->postJson("/api/families/{$this->guardian->id}/collect", [
            'allocations' => [
                ['student_id' => $this->student1->id, 'student_fee_id' => $this->fee1->id, 'amount' => 190.00],
                ['student_id' => $this->student2->id, 'student_fee_id' => $this->fee2->id, 'amount' => 100.00],
            ],
            'payment_date' => '2026-10-05',
            'method' => 'cash',
            'notes' => 'اختبار التحصيل الجماعي',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertDatabaseCount('payments', 1);
        $payment = Payment::first();
        $this->assertEquals($data['receipt']['payment_id'], $payment->id);
        $this->assertEquals(290.00, $payment->amount);

        $this->assertDatabaseCount('payment_allocations', 2);

        $this->fee1->refresh();
        $this->assertEquals(190.00, $this->fee1->allocatedAmount());
        $this->assertEquals(0.00, $this->fee1->outstanding());
        $this->assertEquals('paid', $this->fee1->status);

        $this->fee2->refresh();
        $this->assertEquals(100.00, $this->fee2->allocatedAmount());
        $this->assertEquals(90.00, $this->fee2->outstanding());
        $this->assertEquals('partial', $this->fee2->status);
    }

    public function test_collective_collection_supports_adding_registration_and_club_fees(): void
    {
        $cat = FeeCategory::create(['code' => 'CLUB', 'name' => 'معاليم النوادي', 'is_recurring' => true]);
        $club = Club::create(['name' => 'نادي الروبوت', 'fee_category_id' => $cat->id, 'monthly_fee' => 40.00, 'is_active' => true]);

        $response = $this->postJson("/api/families/{$this->guardian->id}/collect", [
            'allocations' => [
                ['student_id' => $this->student1->id, 'student_fee_id' => $this->fee1->id, 'amount' => 190.00],
                [
                    'student_id' => $this->student2->id,
                    'student_fee_id' => 0,
                    'amount' => 50.00,
                    'new_item' => [
                        'student_id' => $this->student2->id,
                        'enrollment_id' => $this->enrollment2->id,
                        'type' => 'registration',
                        'description' => 'معلوم ترسيم - مريم',
                        'amount_due' => 50.00,
                    ]
                ],
                [
                    'student_id' => $this->student1->id,
                    'student_fee_id' => 0,
                    'amount' => 40.00,
                    'new_item' => [
                        'student_id' => $this->student1->id,
                        'enrollment_id' => $this->enrollment1->id,
                        'club_id' => $club->id,
                        'type' => 'club',
                        'description' => 'معلوم نادي الروبوت (أكتوبر)',
                        'month_name' => 'أكتوبر',
                        'amount_due' => 40.00,
                    ]
                ],
            ],
            'payment_date' => '2026-10-05',
            'method' => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('payments', 1);
        $payment = Payment::first();
        $this->assertEquals(280.00, $payment->amount);
    }

    public function test_cannot_recollect_fully_paid_fee_item(): void
    {
        PaymentAllocation::create([
            'payment_id' => Payment::create([
                'student_id' => $this->student1->id,
                'amount' => 190.00,
                'payment_date' => '2026-10-01',
                'method' => 'cash',
                'created_by' => $this->user->id
            ])->id,
            'student_fee_id' => $this->fee1->id,
            'amount_allocated' => 190.00
        ]);
        $this->fee1->update(['status' => 'paid']);

        $response = $this->postJson("/api/families/{$this->guardian->id}/collect", [
            'allocations' => [
                ['student_id' => $this->student1->id, 'student_fee_id' => $this->fee1->id, 'amount' => 190.00],
            ],
            'payment_date' => '2026-10-05',
            'method' => 'cash',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_family_by_numeric_guardian_id(): void
    {
        $response = $this->getJson("/api/families/{$this->guardian->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $this->guardian->id,
            'guardian_name' => 'علي بن صالح',
            'phone' => '99887766',
            'students_count' => 2,
        ]);
    }

    public function test_show_family_by_phone_identifier(): void
    {
        $response = $this->getJson("/api/families/phone_99887766");

        $response->assertStatus(200);
        $response->assertJson([
            'phone' => '99887766',
            'students_count' => 2,
        ]);
    }

    public function test_show_family_with_invalid_identifier_returns_404(): void
    {
        $response = $this->getJson("/api/families/phone_0000000000");

        $response->assertStatus(404);
        $response->assertJson(['message' => 'العائلة غير موجودة']);
    }
}
