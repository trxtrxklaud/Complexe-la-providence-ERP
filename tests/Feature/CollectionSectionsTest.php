<?php

namespace Tests\Feature;

use App\Http\Controllers\CollectionController;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * حارس شاشة الفوترة والاستخلاص.
 *
 * العطب الذي سقط فيه النظام: قائمة الأقسام كانت مرشَّحة بالتسجيلات، فيختفي
 * كل قسم لم يُرسَّم فيه أحد بعد — أي أغلب الأقسام في بداية السنة الدراسية.
 * قائمة اختيار لا يجوز أن تُرشَّح ببيانات المعاملات، وهذه الاختبارات تمنع
 * رجوع هذا السلوك.
 */
class CollectionSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function controller(): CollectionController
    {
        return app(CollectionController::class);
    }

    private function makeSection(string $levelCode, string $name, int $order = 1): Section
    {
        $suffix = uniqid();

        $level = Level::create([
            'name'  => 'مستوى ' . $levelCode,
            'code'  => $levelCode . substr($suffix, -4),
            'order' => $order,
        ]);

        return Section::create([
            'level_id' => $level->id,
            'name'     => $name,
            'code'     => 'S' . substr($suffix, -6),
            'capacity' => 25,
        ]);
    }

    public function test_a_section_without_enrollments_still_appears_in_the_collection_screen(): void
    {
        $year = $this->makeAcademicYear();
        $enrollment = $this->makeEnrollment($year);

        $empty = $this->makeSection('L6', 'هـ', 6);

        $rows = collect($this->controller()->sectionsByYear($year)->getData(true));
        $ids = $rows->pluck('id');

        // القسم الفارغ حاضر، والقسب الممتلئ حاضر أيضاً.
        $this->assertTrue($ids->contains($empty->id), 'قسم بلا تسجيلات اختفى من شاشة الاستخلاص');
        $this->assertTrue($ids->contains($enrollment->section_id));
        $this->assertCount(2, $rows);

        $emptyRow = $rows->firstWhere('id', $empty->id);
        $filledRow = $rows->firstWhere('id', $enrollment->section_id);

        $this->assertSame(0, $emptyRow['students_count']);
        $this->assertSame(1, $filledRow['students_count']);
    }

    public function test_a_section_full_of_another_year_pupils_is_counted_as_empty_but_still_listed(): void
    {
        $current = $this->makeAcademicYear('2026-2027');
        $past = $this->makeAcademicYear('2025-2026');
        $enrollment = $this->makeEnrollment($past);

        $rows = collect($this->controller()->sectionsByYear($current)->getData(true));
        $row = $rows->firstWhere('id', $enrollment->section_id);

        $this->assertNotNull($row, 'القسم اختفى لأن تسجيلاته من سنة أخرى');
        $this->assertSame(0, $row['students_count']);
    }

    public function test_preschool_sections_are_listed_before_primary_ones(): void
    {
        $year = $this->makeAcademicYear();

        $primary = $this->makeSection('L1', 'أ', 1);
        $preschool = $this->makeSection('PRE1', 'أميمة', 1);

        $ids = collect($this->controller()->sectionsByYear($year)->getData(true))->pluck('id');

        $this->assertSame(
            [$preschool->id, $primary->id],
            $ids->all(),
            'ترتيب الأقسام يجب أن يبدأ بالروضة والتمهيدي والتحضيري'
        );
    }

    public function test_a_withdrawn_pupil_is_not_collectable_in_the_section(): void
    {
        $year = $this->makeAcademicYear();
        $leaver = $this->makeEnrollment($year);
        $section = Section::findOrFail($leaver->section_id);

        $student = Student::create([
            'student_code' => 'STU-' . substr(uniqid(), -6),
            'first_name'   => 'سارة',
            'last_name'    => 'بن عمر',
            'gender'       => 'female',
            'status'       => 'active',
        ]);

        $stayer = Enrollment::create([
            'student_id'       => $student->id,
            'academic_year_id' => $year->id,
            'level_id'         => $leaver->level_id,
            'section_id'       => $section->id,
            'enrollment_date'  => '2025-09-01',
            'status'           => 'active',
        ]);

        $leaver->update(['status' => 'withdrawn']);

        $request = Request::create('/api/collection/sections/' . $section->id . '/students', 'GET', [
            'year_id' => $year->id,
        ]);

        $rows = collect($this->controller()->studentsBySection($section, $request)->getData(true));

        $this->assertCount(1, $rows, 'تلميذ مغادر ما زال قابلاً للاستخلاص منه');
        $this->assertSame($stayer->id, $rows->first()['enrollment_id']);
    }
}
