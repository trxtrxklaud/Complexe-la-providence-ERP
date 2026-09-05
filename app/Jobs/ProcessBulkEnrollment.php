<?php

namespace App\Jobs;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * معالجة تسجيل التلاميذ دفعياً في الأقسام في الخلفية أو بالتزامن عبر الطوابير.
 */
class ProcessBulkEnrollment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    /**
     * @param array $data بيانات التسجيل الجماعي المعتمدة ['section_id', 'academic_year_id', 'students']
     * @param int|null $userId
     */
    public function __construct(
        public array $data,
        public ?int $userId = null
    ) {}

    public function handle(): array
    {
        $section = Section::with('level')->findOrFail($this->data['section_id']);
        $year = AcademicYear::findOrFail($this->data['academic_year_id']);

        // الأسماء المسجّلة فعلاً في هذه السنة (لأيّ قسم) — لمنع التكرار.
        $existing = Enrollment::query()
            ->where('academic_year_id', $year->id)
            ->with('student')
            ->get()
            ->filter(fn ($enrollment) => $enrollment->student !== null)
            ->map(fn ($enrollment) => self::normalize(
                $enrollment->student->first_name . ' ' . $enrollment->student->last_name
            ))
            ->all();

        $existing = array_flip($existing);

        $capacity = (int) $section->capacity;
        $current = Enrollment::where('academic_year_id', $year->id)
            ->where('section_id', $section->id)
            ->where('status', 'active')
            ->count();

        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($section, $year, &$created, &$skipped, &$existing, $capacity, $current) {
            foreach ($this->data['students'] as $entry) {
                $firstName = trim(preg_replace('/\s+/u', ' ', (string) $entry['first_name']));
                $lastName = trim(preg_replace('/\s+/u', ' ', (string) $entry['last_name']));

                if ($firstName === '' || $lastName === '') {
                    continue;
                }

                $fullName = $firstName . ' ' . $lastName;
                $key = self::normalize($fullName);

                if (isset($existing[$key])) {
                    $skipped[] = $fullName;
                    continue;
                }

                if ($capacity > 0 && ($current + $created) >= $capacity) {
                    $skipped[] = $fullName;
                    continue;
                }

                $student = Student::create([
                    'student_code' => self::nextStudentCode($year->name),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'guardian_first_name' => $entry['father_name'] ?? null,
                    'mother_name' => $entry['mother_name'] ?? null,
                    'guardian_phone' => $entry['father_phone'] ?? null,
                    'mother_phone' => $entry['mother_phone'] ?? null,
                    'status' => 'active',
                ]);

                Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'level_id' => $section->level_id,
                    'section_id' => $section->id,
                    'enrollment_date' => now()->toDateString(),
                    'status' => 'active',
                ]);

                $existing[$key] = true;
                $created++;
            }
        });

        $message = $skipped === []
            ? "تمّ تسجيل {$created} تلميذ."
            : "تمّ تسجيل {$created} تلميذ، وتُجاهل " . count($skipped) . ' اسماً.';

        Log::info("ProcessBulkEnrollment completed for section #{$section->id}: created {$created}, skipped " . count($skipped));

        return [
            'created' => $created,
            'skipped' => $skipped,
            'message' => $message,
        ];
    }

    /**
     * توحيد الاسم للمقارنة: حذف التشكيل وتوحيد الألف والهاء والياء.
     */
    public static function normalize(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value);
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $value);

        return mb_strtolower($value);
    }

    public static function nextStudentCode(string $yearName): string
    {
        preg_match('/(\d{4})/', $yearName, $matches);
        $prefix = 'PRV-' . ($matches[1] ?? date('Y')) . '-';

        $sequence = (int) Student::where('student_code', 'like', $prefix . '%')->count();

        do {
            $sequence++;
            $code = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        } while (Student::where('student_code', $code)->exists());

        return $code;
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessBulkEnrollment failed: " . $exception->getMessage(), [
            'data' => $this->data,
            'user_id' => $this->userId,
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
