<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDeleteProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_with_payment_history_cannot_be_deleted(): void
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);

        $enrollment = $this->makeEnrollment();
        $student = $enrollment->student;

        Payment::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'academic_year_id' => $enrollment->academic_year_id,
            'amount' => '100.00',
            'payment_date' => '2025-09-10',
            'method' => 'cash',
            'created_by' => $user->id,
        ]);

        $enrollment->update(['status' => 'withdrawn']);

        $response = $this->deleteJson('/api/students/' . $student->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف تلميذ لديه تسجيلات أو سجلات مالية مرتبطة']);

        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertDatabaseHas('payments', ['student_id' => $student->id]);
    }

    public function test_student_with_related_enrollment_records_cannot_be_deleted(): void
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);

        $enrollment = $this->makeEnrollment();
        $student = $enrollment->student;

        $enrollment->update(['status' => 'withdrawn']);

        $response = $this->deleteJson('/api/students/' . $student->id);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف تلميذ لديه تسجيلات أو سجلات مالية مرتبطة']);

        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_student_with_no_related_records_can_be_deleted(): void
    {
        $user = $this->makeUser('admin');
        $user->update(['is_active' => true]);
        Sanctum::actingAs($user);

        $student = Student::create([
            'student_code' => 'STU-TEMP-001',
            'first_name' => 'سامي',
            'last_name' => 'بن عمر',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $response = $this->deleteJson('/api/students/' . $student->id);

        $response->assertNoContent();

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }
}
