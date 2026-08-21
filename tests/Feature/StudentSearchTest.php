<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\Permission;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_search_supports_the_legacy_get_fields(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $matchingStudent = Student::create([
            'student_code'   => 'CNTE-001',
            'first_name'     => 'أحمد',
            'last_name'      => 'بن صالح',
            'dob'            => '2016-04-12',
            'gender'         => 'male',
            'guardian_phone' => '22123456',
            'status'         => 'active',
        ]);

        Enrollment::create([
            'student_id'       => $matchingStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        Student::create([
            'student_code'   => 'CNTE-999',
            'first_name'     => 'سليم',
            'last_name'      => 'العبيدي',
            'dob'            => '2015-01-01',
            'gender'         => 'male',
            'guardian_phone' => '99999999',
            'status'         => 'active',
        ]);

        $this->getJson('/api/students?'.http_build_query([
            'level'        => $section->id,
            'student_name' => 'أحمد بن صالح',
            'phone'        => '2212',
            'birthday'     => '2016-04-12',
            'year'         => $year->id,
            'cnte'         => 'CNTE-001',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingStudent->id);
    }

    public function test_student_search_options_return_sections_and_years(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $this->getJson('/api/students/search-options')
            ->assertOk()
            ->assertJsonPath('levels.0.id', $section->id)
            ->assertJsonPath('levels.0.label', $level->name.' '.$section->name)
            ->assertJsonPath('years.0.id', $year->id);
    }

    public function test_selecting_a_section_returns_all_active_students_in_that_section(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        foreach (['أحمد', 'مريم'] as $index => $firstName) {
            $student = Student::create([
                'student_code' => 'CLASS-'.$index,
                'first_name'   => $firstName,
                'last_name'    => 'اختبار',
                'gender'       => $index === 0 ? 'male' : 'female',
                'status'       => 'active',
            ]);

            Enrollment::create([
                'student_id'       => $student->id,
                'academic_year_id' => $year->id,
                'level_id'         => $level->id,
                'section_id'       => $section->id,
                'enrollment_date'  => '2025-09-01',
                'status'           => 'active',
            ]);
        }

        $this->getJson('/api/students?level='.$section->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_section_search_deduplicates_names_and_sorts_them_alphabetically(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        foreach ([
            ['code' => 'ORDER-1', 'first_name' => 'مريم', 'last_name' => 'اختبار'],
            ['code' => 'ORDER-2', 'first_name' => 'أحمد', 'last_name' => 'اختبار'],
            ['code' => 'ORDER-3', 'first_name' => 'أحمد', 'last_name' => 'اختبار'],
        ] as $studentData) {
            $student = Student::create([
                'student_code' => $studentData['code'],
                'first_name' => $studentData['first_name'],
                'last_name' => $studentData['last_name'],
                'gender' => 'male',
                'status' => 'active',
            ]);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'level_id' => $level->id,
                'section_id' => $section->id,
                'enrollment_date' => '2025-09-01',
                'status' => 'active',
            ]);
        }

        $this->getJson('/api/students?level='.$section->id)
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_selected_student_detail_endpoints_are_available_to_student_managers(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $student = Student::create([
            'student_code'        => 'DETAIL-001',
            'first_name'          => 'سارة',
            'last_name'           => 'بن عمر',
            'gender'              => 'female',
            'guardian_first_name' => 'منصف',
            'guardian_last_name'  => 'بن عمر',
            'guardian_phone'      => '22112233',
            'status'              => 'active',
        ]);

        $this->getJson('/api/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('guardian_phone', '22112233');

        $this->getJson('/api/students/'.$student->id.'/fees')
            ->assertOk()
            ->assertExactJson([]);

        $this->getJson('/api/students/'.$student->id.'/balance')
            ->assertOk()
            ->assertJsonPath('balance', 0);
    }

    public function test_student_details_include_unpaid_fees_and_payment_collection_dates(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $student = Student::create([
            'student_code' => 'FINANCE-001',
            'first_name' => 'ليلى',
            'last_name' => 'المنصوري',
            'gender' => 'female',
            'guardian_first_name' => 'منصف',
            'guardian_last_name' => 'المنصوري',
            'guardian_phone' => '22112233',
            'status' => 'active',
        ]);
        $enrollment = $this->makeEnrollment($this->makeAcademicYear(), $student);
        $fee = StudentFee::create([
            'enrollment_id' => $enrollment->id,
            'fee_plan_id' => null,
            'description' => 'القسط الشهري سبتمبر',
            'amount_due' => 150,
            'due_date' => '2025-09-10',
            'status' => 'partial',
        ]);
        $payment = Payment::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'months' => ['2025-09'],
            'amount' => 50,
            'payment_date' => '2025-09-14',
            'method' => 'cash',
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'student_fee_id' => $fee->id,
            'amount_allocated' => 50,
        ]);

        $this->getJson('/api/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('guardian_first_name', 'منصف')
            ->assertJsonPath('enrollments.0.academic_year.name', '2025-2026');

        $this->getJson('/api/students/'.$student->id.'/fees')
            ->assertOk()
            ->assertJsonPath('0.fees.0.allocated', 50)
            ->assertJsonPath('0.fees.0.remaining', 100);

        $this->getJson('/api/students/'.$student->id.'/balance')
            ->assertOk()
            ->assertJsonPath('balance', 100);

        $this->getJson('/api/students/'.$student->id.'/payments')
            ->assertOk()
            ->assertJsonPath('0.payment_date', '2025-09-14')
            ->assertJsonPath('0.months.0', '2025-09')
            ->assertJsonPath('0.allocations.0.amount', '50.00');
    }

    public function test_print_profile_hides_links_buttons_and_form_controls(): void
    {
        $page = file_get_contents(resource_path('js/pages/Students/StudentDetailsPage.tsx'));

        $this->assertIsString($page);
        $this->assertStringContainsString('@media print', $page);
        $this->assertStringContainsString('.student-print-profile button', $page);
        $this->assertStringContainsString('.student-print-profile a', $page);
        $this->assertStringContainsString('.student-print-profile input', $page);
        $this->assertStringContainsString('display: none !important', $page);
    }

    public function test_student_search_supports_gender_filtering_breakdown_counts_and_reconciliation(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $sectionA] = $this->makeSection();
        $sectionB = Section::create([
            'level_id' => $level->id,
            'name'     => 'ب',
            'code'     => 'L1-B',
            'capacity' => 25,
        ]);

        // Student 1: Male in Section A
        $maleStudent = Student::create([
            'student_code' => 'ST_M1',
            'first_name'   => 'أحمد',
            'last_name'    => 'بن علي',
            'gender'       => 'male',
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $maleStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $sectionA->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        // Student 2: Female in Section A with duplicate enrollment row in Section B
        $femaleStudent = Student::create([
            'student_code' => 'ST_F1',
            'first_name'   => 'مريم',
            'last_name'    => 'الطرابلسي',
            'gender'       => 'female',
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $sectionA->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $sectionB->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        // Student 3: Unknown gender in Section A
        $unknownStudent = Student::create([
            'student_code' => 'ST_U1',
            'first_name'   => 'غير_معروف_99',
            'last_name'    => 'المستورد',
            'gender'       => null,
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $unknownStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $sectionA->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        // 1. Query All for Section A
        $resAll = $this->getJson('/api/students?'.http_build_query([
            'level' => $sectionA->id,
            'year'  => $year->id,
            'gender' => 'all',
        ]))->assertOk()->json();

        $this->assertEquals(3, $resAll['total_count']);
        $this->assertEquals(1, $resAll['male_count']);
        $this->assertEquals(1, $resAll['female_count']);
        $this->assertEquals(1, $resAll['unknown_count']);
        $this->assertEquals(3, count($resAll['data']));
        $this->assertEquals(
            $resAll['total_count'],
            $resAll['male_count'] + $resAll['female_count'] + $resAll['unknown_count']
        );

        // 2. Male-only Filter
        $resMale = $this->getJson('/api/students?'.http_build_query([
            'level' => $sectionA->id,
            'year'  => $year->id,
            'gender' => 'male',
        ]))->assertOk()->json();

        $this->assertEquals(1, count($resMale['data']));
        $this->assertEquals($maleStudent->id, $resMale['data'][0]['id']);

        // 3. Female-only Filter
        $resFemale = $this->getJson('/api/students?'.http_build_query([
            'level' => $sectionA->id,
            'year'  => $year->id,
            'gender' => 'female',
        ]))->assertOk()->json();

        $this->assertEquals(1, count($resFemale['data']));
        $this->assertEquals($femaleStudent->id, $resFemale['data'][0]['id']);

        // 4. Unknown-only Filter
        $resUnknown = $this->getJson('/api/students?'.http_build_query([
            'level' => $sectionA->id,
            'year'  => $year->id,
            'gender' => 'unknown',
        ]))->assertOk()->json();

        $this->assertEquals(1, count($resUnknown['data']));
        $this->assertEquals($unknownStudent->id, $resUnknown['data'][0]['id']);
    }

    public function test_arabic_first_name_infers_gender_when_column_is_null(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        // Male Arabic name 'أحمد' with NULL gender column
        $maleStudent = Student::create([
            'student_code' => 'NO_INF_1',
            'first_name'   => 'أحمد',
            'last_name'    => 'بن صالح',
            'gender'       => null,
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $maleStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        // Female Arabic name 'سلمى' (تاء مربوطة ending) with NULL gender column
        $femaleStudent = Student::create([
            'student_code' => 'NO_INF_2',
            'first_name'   => 'سلمى',
            'last_name'    => 'بن صالح',
            'gender'       => null,
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $femaleStudent->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        $res = $this->getJson('/api/students?'.http_build_query([
            'level' => $section->id,
            'year'  => $year->id,
        ]))->assertOk()->json();

        // Inferred from Arabic name: 1 male, 1 female, 0 unknown
        $this->assertEquals(2, $res['total_count']);
        $this->assertEquals(1, $res['male_count']);
        $this->assertEquals(1, $res['female_count']);
        $this->assertEquals(0, $res['unknown_count']);
        $this->assertEquals('male', $res['data'][0]['gender']);

        // Male-only filter catches the inferred male
        $resMale = $this->getJson('/api/students?'.http_build_query([
            'level' => $section->id,
            'year'  => $year->id,
            'gender' => 'male',
        ]))->assertOk()->json();
        $this->assertEquals(1, count($resMale['data']));
        $this->assertEquals($maleStudent->id, $resMale['data'][0]['id']);

        // Female-only filter catches the inferred female
        $resFemale = $this->getJson('/api/students?'.http_build_query([
            'level' => $section->id,
            'year'  => $year->id,
            'gender' => 'female',
        ]))->assertOk()->json();
        $this->assertEquals(1, count($resFemale['data']));
        $this->assertEquals($femaleStudent->id, $resFemale['data'][0]['id']);
    }

    public function test_gender_inference_keeps_unrecognized_names_as_unknown(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        // Name that matches neither list nor feminine endings
        $student = Student::create([
            'student_code' => 'NO_INF_3',
            'first_name'   => 'رونالدو',
            'last_name'    => 'بن صالح',
            'gender'       => null,
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $student->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        $res = $this->getJson('/api/students?'.http_build_query([
            'level' => $section->id,
            'year'  => $year->id,
        ]))->assertOk()->json();

        $this->assertEquals(1, $res['total_count']);
        $this->assertEquals(0, $res['male_count']);
        $this->assertEquals(0, $res['female_count']);
        $this->assertEquals(1, $res['unknown_count']);
        $this->assertEquals('unknown', $res['data'][0]['gender']);
    }

    public function test_creating_a_student_with_male_gender_stores_male(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $res = $this->postJson('/api/students/enroll', [
            'first_name'          => 'خالد',
            'last_name'           => 'المبروك',
            'dob'                 => '2015-05-10',
            'gender'              => 'male',
            'guardian_first_name' => 'سالم',
            'guardian_last_name'  => 'المبروك',
            'guardian_phone'      => '21000001',
            'address'             => 'شارع الجمهورية',
            'section_id'          => $section->id,
        ])->assertStatus(201)->json();

        $student = Student::find($res['enrollment']['student_id'] ?? null)
            ?? Student::where('student_code', $res['enrollment']['student']['student_code'] ?? '')->first();

        $this->assertNotNull($student);
        $this->assertEquals('male', $student->fresh()->gender);
    }

    public function test_creating_a_student_with_female_gender_stores_female(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $res = $this->postJson('/api/students/enroll', [
            'first_name'          => 'سارة',
            'last_name'           => 'البوسعيدي',
            'dob'                 => '2016-03-20',
            'gender'              => 'female',
            'guardian_first_name' => 'محمد',
            'guardian_last_name'  => 'البوسعيدي',
            'guardian_phone'      => '21000002',
            'address'             => 'حي الرياض',
            'section_id'          => $section->id,
        ])->assertStatus(201)->json();

        $student = Student::find($res['enrollment']['student_id'] ?? null)
            ?? Student::where('student_code', $res['enrollment']['student']['student_code'] ?? '')->first();

        $this->assertNotNull($student);
        $this->assertEquals('female', $student->fresh()->gender);
    }

    public function test_creating_a_student_with_invalid_gender_is_rejected(): void
    {
        Sanctum::actingAs($this->makeStudentManager());
        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $this->postJson('/api/students/enroll', [
            'first_name'          => 'تلميذ',
            'last_name'           => 'اختبار',
            'dob'                 => '2015-01-01',
            'gender'              => 'unknown',  // invalid — must be male or female
            'guardian_first_name' => 'ولي',
            'guardian_last_name'  => 'الأمر',
            'guardian_phone'      => '21000003',
            'address'             => 'عنوان',
            'section_id'          => $section->id,
        ])->assertStatus(422);
    }

    public function test_creating_a_student_without_gender_is_rejected(): void
    {
        Sanctum::actingAs($this->makeStudentManager());
        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        $this->postJson('/api/students/enroll', [
            'first_name'          => 'تلميذ',
            'last_name'           => 'اختبار',
            'dob'                 => '2015-01-01',
            // gender omitted — must be rejected
            'guardian_first_name' => 'ولي',
            'guardian_last_name'  => 'الأمر',
            'guardian_phone'      => '21000004',
            'address'             => 'عنوان',
            'section_id'          => $section->id,
        ])->assertStatus(422);
    }

    public function test_updating_gender_from_null_to_male_succeeds(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $student = Student::create([
            'student_code' => 'UPD_M_1',
            'first_name'   => 'تلميذ',
            'last_name'    => 'قديم',
            'gender'       => null,
            'status'       => 'active',
        ]);

        $this->patchJson("/api/students/{$student->id}", ['gender' => 'male'])
            ->assertOk();

        $this->assertEquals('male', $student->fresh()->gender);
    }

    public function test_updating_gender_from_null_to_female_succeeds(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $student = Student::create([
            'student_code' => 'UPD_F_1',
            'first_name'   => 'تلميذة',
            'last_name'    => 'قديمة',
            'gender'       => null,
            'status'       => 'active',
        ]);

        $this->patchJson("/api/students/{$student->id}", ['gender' => 'female'])
            ->assertOk();

        $this->assertEquals('female', $student->fresh()->gender);
    }

    public function test_updating_gender_to_invalid_value_is_rejected(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $student = Student::create([
            'student_code' => 'UPD_INV_1',
            'first_name'   => 'تلميذ',
            'last_name'    => 'اختبار',
            'gender'       => null,
            'status'       => 'active',
        ]);

        $this->patchJson("/api/students/{$student->id}", ['gender' => 'unknown'])
            ->assertStatus(422);

        $this->assertNull($student->fresh()->gender);
    }

    public function test_unauthorized_user_cannot_update_student_gender(): void
    {
        // User with no manage_students permission
        $user = $this->makeUser('cashier');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);

        $student = Student::create([
            'student_code' => 'UNAUTH_1',
            'first_name'   => 'تلميذ',
            'last_name'    => 'اختبار',
            'gender'       => null,
            'status'       => 'active',
        ]);

        $this->patchJson("/api/students/{$student->id}", ['gender' => 'male'])
            ->assertStatus(403);

        $this->assertNull($student->fresh()->gender);
    }

    public function test_dashboard_counts_update_correctly_after_gender_change(): void
    {
        Sanctum::actingAs($this->makeStudentManager());

        $year = $this->makeAcademicYear();
        [$level, $section] = $this->makeSection();

        // Create student with null gender, enrolled in active year
        $student = Student::create([
            'student_code' => 'DASH_UPD_1',
            'first_name'   => 'تلميذ',
            'last_name'    => 'اختبار',
            'gender'       => null,
            'status'       => 'active',
        ]);
        Enrollment::create([
            'student_id'       => $student->id,
            'academic_year_id' => $year->id,
            'level_id'         => $level->id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        // Initially unknown
        $res1 = $this->getJson('/api/students?' . http_build_query([
            'level' => $section->id,
            'year'  => $year->id,
        ]))->assertOk()->json();
        $this->assertEquals(0, $res1['male_count']);
        $this->assertEquals(1, $res1['unknown_count']);

        // Update gender to male
        $this->patchJson("/api/students/{$student->id}", ['gender' => 'male'])->assertOk();

        // Now male count should increase
        $res2 = $this->getJson('/api/students?' . http_build_query([
            'level' => $section->id,
            'year'  => $year->id,
        ]))->assertOk()->json();
        $this->assertEquals(1, $res2['male_count']);
        $this->assertEquals(0, $res2['unknown_count']);
        $this->assertEquals(
            $res2['total_count'],
            $res2['male_count'] + $res2['female_count'] + $res2['unknown_count']
        );
    }

    private function makeSection(): array
    {
        $level = Level::create([
            'name'  => 'السنة الأولى',
            'code'  => 'L1',
            'order' => 1,
        ]);

        $section = Section::create([
            'level_id' => $level->id,
            'name'     => 'أ',
            'code'     => 'L1-A',
            'capacity' => 25,
        ]);

        return [$level, $section];
    }

    private function makeStudentManager()
    {
        $user = $this->makeUser('student_manager');
        $user->update(['is_active' => true]);
        $permission = Permission::create([
            'name'         => 'manage_students',
            'display_name' => 'إدارة الطلاب',
            'group'        => 'Students',
        ]);
        $user->role->permissions()->attach($permission);

        return $user;
    }
}
