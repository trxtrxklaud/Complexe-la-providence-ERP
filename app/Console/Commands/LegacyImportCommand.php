<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Level;
use App\Models\ManualStudentDebt;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LegacyImportCommand extends Command
{
    protected $signature = 'legacy:import 
                            {--sqlite-path= : Path to legacy sqlite database file}
                            {--dry-run : Test import without committing changes}';

    protected $description = 'Import students, enrollments, and debts from legacy SQLite database to current MySQL database';

    public function handle(): int
    {
        $sqlitePath = $this->option('sqlite-path') ?: env('DB_SQLITE_LEGACY_PATH', database_path('legacy.sqlite'));

        if (! file_exists($sqlitePath)) {
            $sqlitePath = database_path('database.sqlite');
        }

        if (! file_exists($sqlitePath)) {
            $this->error("❌ ملف قاعدة بيانات SQLite القديمة غير موجود في المسار: {$sqlitePath}");
            return self::FAILURE;
        }

        $this->info("🔌 الاتصال بقاعدة SQLite القديمة: {$sqlitePath}");

        config(['database.connections.sqlite_legacy' => [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $legacyDb = DB::connection('sqlite_legacy');

        try {
            $legacyDb->getPdo();
        } catch (\Throwable $e) {
            $this->error("فشل الاتصال بقاعدة بيانات SQLite: " . $e->getMessage());
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn("⚠️  وضع التجربة (DRY-RUN) مفعّل: لن يتم حفظ أي تعديلات في قاعدة البيانات.");
        }

        DB::beginTransaction();

        try {
            $this->info("1/4 مطابقة السنوات الدراسية والمستويات والأقسام...");

            $targetYears = AcademicYear::all()->keyBy('name');
            $targetLevels = Level::all()->keyBy('code');
            $targetSections = Section::with('level')->get();

            $sectionLookup = [];
            foreach ($targetSections as $s) {
                $levelCode = $s->level?->code ?? '';
                $sectionLookup["{$levelCode}|{$s->name}"] = $s->id;
                if ($s->name === 'ه') {
                    $sectionLookup["{$levelCode}|هـ"] = $s->id;
                } elseif ($s->name === 'هـ') {
                    $sectionLookup["{$levelCode}|ه"] = $s->id;
                }
            }

            $legacyYears = $legacyDb->table('academic_years')->get()->keyBy('id');
            $yearIdMap = [];
            foreach ($legacyYears as $oldId => $oldYear) {
                $targetYear = $targetYears->get($oldYear->name);
                if (! $targetYear) {
                    $targetYear = AcademicYear::create([
                        'name' => $oldYear->name,
                        'start_date' => $oldYear->start_date ?? '2025-09-01',
                        'end_date' => $oldYear->end_date ?? '2026-06-30',
                        'is_active' => (bool) $oldYear->is_active,
                    ]);
                    $targetYears->put($targetYear->name, $targetYear);
                    $this->line("  ➕ إنشاء سنة دراسية مفقودة: {$targetYear->name}");
                }
                $yearIdMap[$oldId] = $targetYear->id;
            }

            $legacyLevels = $legacyDb->table('levels')->get()->keyBy('id');
            $levelIdMap = [];
            foreach ($legacyLevels as $oldId => $oldLevel) {
                $targetLevel = $targetLevels->get($oldLevel->code)
                    ?? Level::where('name', $oldLevel->name)->first();

                if (! $targetLevel) {
                    $targetLevel = Level::create([
                        'name' => $oldLevel->name,
                        'code' => $oldLevel->code,
                        'order' => $oldLevel->order ?? 1,
                    ]);
                    $targetLevels->put($targetLevel->code, $targetLevel);
                    $this->line("  ➕ إنشاء مستوى مفقود: {$targetLevel->name} ({$targetLevel->code})");
                }
                $levelIdMap[$oldId] = $targetLevel;
            }

            $legacySections = $legacyDb->table('sections')->get()->keyBy('id');
            $sectionIdMap = [];
            foreach ($legacySections as $oldId => $oldSec) {
                $oldLevel = $legacyLevels->get($oldSec->level_id);
                $newLevel = $oldLevel ? ($levelIdMap[$oldLevel->id] ?? null) : null;
                $levelCode = $newLevel ? $newLevel->code : ($oldLevel->code ?? '');

                $lookupKey = "{$levelCode}|{$oldSec->name}";
                $targetSectionId = $sectionLookup[$lookupKey] ?? null;

                if (! $targetSectionId && $newLevel) {
                    $sec = Section::where('level_id', $newLevel->id)->where('name', $oldSec->name)->first();
                    if (! $sec && in_array($oldSec->name, ['ه', 'هـ'])) {
                        $alt = $oldSec->name === 'ه' ? 'هـ' : 'ه';
                        $sec = Section::where('level_id', $newLevel->id)->where('name', $alt)->first();
                    }
                    if (! $sec) {
                        $sec = Section::create([
                            'level_id' => $newLevel->id,
                            'name' => $oldSec->name,
                            'code' => $newLevel->code . '-' . $oldSec->name,
                            'capacity' => $oldSec->capacity ?? 30,
                        ]);
                        $sectionLookup[$lookupKey] = $sec->id;
                        $this->line("  ➕ إنشاء قسم مفقود: {$sec->code}");
                    }
                    $targetSectionId = $sec->id;
                }

                $sectionIdMap[$oldId] = $targetSectionId;
            }

            $this->info("2/4 نقل بيانات التلاميذ وتطهير النصوص...");
            $legacyStudents = $legacyDb->table('students')->get();
            $studentIdMap = [];
            $studentsCreated = 0;
            $studentsUpdated = 0;

            foreach ($legacyStudents as $s) {
                $phone = $this->cleanString($s->guardian_phone);
                $motherPhone = $this->cleanString($s->mother_phone);
                $firstName = $this->cleanString($s->first_name);
                $lastName = $this->cleanString($s->last_name);
                $studentCode = $this->cleanString($s->student_code);

                $targetStudent = null;
                if ($studentCode) {
                    $targetStudent = Student::where('student_code', $studentCode)->first();
                }
                if (! $targetStudent && $firstName && $lastName) {
                    $query = Student::where('first_name', $firstName)->where('last_name', $lastName);
                    if ($phone) {
                        $query->where('guardian_phone', $phone);
                    }
                    $targetStudent = $query->first();
                }

                $studentData = [
                    'student_code' => $studentCode ?: ('PRV-' . date('Y') . '-' . str_pad((string) $s->id, 4, '0', STR_PAD_LEFT)),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'dob' => $s->dob ?: null,
                    'gender' => in_array($s->gender, ['male', 'female'], true) ? $s->gender : 'male',
                    'photo' => $s->photo ?: null,
                    'notes' => $this->cleanString($s->notes),
                    'status' => $s->status ?: 'active',
                    'guardian_first_name' => $this->cleanString($s->guardian_first_name),
                    'guardian_last_name' => $this->cleanString($s->guardian_last_name),
                    'mother_name' => $this->cleanString($s->mother_name),
                    'guardian_phone' => $phone,
                    'mother_phone' => $motherPhone,
                ];

                if ($targetStudent) {
                    $targetStudent->update($studentData);
                    $studentIdMap[$s->id] = $targetStudent->id;
                    $studentsUpdated++;
                } else {
                    $newStudent = Student::create($studentData);
                    $studentIdMap[$s->id] = $newStudent->id;
                    $studentsCreated++;
                }
            }

            $this->info("3/4 ربط التلاميذ بالأقسام والمستويات...");
            $legacyEnrollments = $legacyDb->table('enrollments')->get();
            $enrollmentsCreated = 0;
            $enrollmentsSkipped = 0;

            foreach ($legacyEnrollments as $e) {
                $newStudentId = $studentIdMap[$e->student_id] ?? null;
                $newYearId = $yearIdMap[$e->academic_year_id] ?? null;
                $newLevel = $levelIdMap[$e->level_id] ?? null;
                $newSectionId = $sectionIdMap[$e->section_id] ?? null;

                if (! $newStudentId || ! $newYearId || ! $newLevel || ! $newSectionId) {
                    $enrollmentsSkipped++;
                    continue;
                }

                $existingEnrollment = Enrollment::where('student_id', $newStudentId)
                    ->where('academic_year_id', $newYearId)
                    ->first();

                if ($existingEnrollment) {
                    $existingEnrollment->update([
                        'level_id' => $newLevel->id,
                        'section_id' => $newSectionId,
                        'status' => $e->status ?: 'active',
                        'enrollment_date' => $e->enrollment_date ?: now(),
                    ]);
                } else {
                    Enrollment::create([
                        'student_id' => $newStudentId,
                        'academic_year_id' => $newYearId,
                        'level_id' => $newLevel->id,
                        'section_id' => $newSectionId,
                        'status' => $e->status ?: 'active',
                        'enrollment_date' => $e->enrollment_date ?: now(),
                    ]);
                    $enrollmentsCreated++;
                }
            }

            $this->info("4/4 نقل الأرصدة الافتتاحية والديون السابقة...");
            $legacyDebts = $legacyDb->table('manual_student_debts')->get();
            $debtsCreated = 0;
            $debtsSkipped = 0;

            foreach ($legacyDebts as $d) {
                $newStudentId = $studentIdMap[$d->student_id] ?? null;
                $newYearId = $yearIdMap[$d->academic_year_id] ?? null;

                if (! $newStudentId || ! $newYearId) {
                    $debtsSkipped++;
                    continue;
                }

                $exists = ManualStudentDebt::where('student_id', $newStudentId)
                    ->where('academic_year_id', $newYearId)
                    ->where('debt_type', $d->debt_type ?: 'tuition')
                    ->where('original_amount', $d->original_amount)
                    ->exists();

                if ($exists) {
                    $debtsSkipped++;
                    continue;
                }

                ManualStudentDebt::create([
                    'student_id' => $newStudentId,
                    'academic_year_id' => $newYearId,
                    'debt_type' => $d->debt_type ?: 'tuition',
                    'original_amount' => $d->original_amount,
                    'original_year_label' => $d->original_year_label ?: '2025-2026',
                    'description' => $d->description ?: 'رصيد افتتاحي مرحل من النظام القديم',
                    'notes' => $this->cleanString($d->notes),
                    'status' => $d->status ?: 'pending',
                    'created_by' => 1,
                ]);
                $debtsCreated++;
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->warn("\n🏁 اكتمال التجربة (DRY-RUN): تم التراجع عن كافة التغييرات.");
            } else {
                DB::commit();
                $this->info("\n✅ نجاح: تم استيراد كافة البيانات بنجاح!");
            }

            $this->table(
                ['الكيان (Entity)', 'سجلات جديدة (Created)', 'محدثة / متجاوزة (Updated/Skipped)'],
                [
                    ['التلاميذ (Students)', $studentsCreated, $studentsUpdated],
                    ['التسجيلات في الأقسام (Enrollments)', $enrollmentsCreated, $enrollmentsSkipped],
                    ['الديون القديمة (Manual Debts)', $debtsCreated, $debtsSkipped],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("❌ فشلت عملية النقل: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function cleanString(?string $str): ?string
    {
        if ($str === null) {
            return null;
        }

        $cleaned = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "NOTION_TWS["], '', $str);
        $cleaned = trim($cleaned);

        return $cleaned !== '' ? $cleaned : null;
    }
}
