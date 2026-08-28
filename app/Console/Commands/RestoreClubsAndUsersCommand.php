<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\ClubMonthlyFee;
use App\Models\ClubSubscription;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\Level;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreClubsAndUsersCommand extends Command
{
    protected $signature = 'legacy:restore-clubs-users
                            {--sqlite-path= : Path to legacy sqlite database file}
                            {--dry-run : Test import without committing changes}';

    protected $description = 'Restore clubs, levels, sections, subscriptions, fees, and users from legacy SQLite to MySQL';

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
            // ----------------------------------------------------
            // 1. بناء قواميس المطابقة (Lookup Dictionaries)
            // ----------------------------------------------------
            $this->info("1/5 تجهيز قواميس المطابقة للسنوات والأقسام والمستويات...");

            $targetYearsByName = AcademicYear::all()->keyBy('name');
            $legacyYears = $legacyDb->table('academic_years')->get()->keyBy('id');
            $yearIdMap = [];
            foreach ($legacyYears as $oldId => $oldYear) {
                $targetYear = $targetYearsByName->get($oldYear->name);
                if ($targetYear) {
                    $yearIdMap[$oldId] = $targetYear->id;
                }
            }

            $targetLevelsByCode = Level::all()->keyBy('code');
            $legacyLevels = $legacyDb->table('levels')->get()->keyBy('id');
            $levelIdMap = [];
            foreach ($legacyLevels as $oldId => $oldLevel) {
                $targetLevel = $targetLevelsByCode->get($oldLevel->code)
                    ?? Level::where('name', $oldLevel->name)->first();
                if ($targetLevel) {
                    $levelIdMap[$oldId] = $targetLevel;
                }
            }

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

            $legacySections = $legacyDb->table('sections')->get()->keyBy('id');
            $sectionIdMap = [];
            foreach ($legacySections as $oldId => $oldSec) {
                $oldLevel = $legacyLevels->get($oldSec->level_id);
                $newLevel = $oldLevel ? ($levelIdMap[$oldLevel->id] ?? null) : null;
                $levelCode = $newLevel ? $newLevel->code : ($oldLevel->code ?? '');
                $lookupKey = "{$levelCode}|{$oldSec->name}";
                $sectionIdMap[$oldId] = $sectionLookup[$lookupKey] ?? null;
            }

            // مطابقة الطلاب
            $legacyStudents = $legacyDb->table('students')->get()->keyBy('id');
            $targetStudentsByCode = Student::all()->keyBy('student_code');
            $studentIdMap = [];
            foreach ($legacyStudents as $oldId => $oldStudent) {
                $targetStudent = null;
                if (! empty($oldStudent->student_code)) {
                    $targetStudent = $targetStudentsByCode->get($oldStudent->student_code);
                }
                if (! $targetStudent) {
                    $targetStudent = Student::where('first_name', trim((string) $oldStudent->first_name))
                        ->where('last_name', trim((string) $oldStudent->last_name))
                        ->first();
                }
                if ($targetStudent) {
                    $studentIdMap[$oldId] = $targetStudent->id;
                }
            }

            // مطابقة التسجيلات (Enrollments)
            $legacyEnrollments = $legacyDb->table('enrollments')->get()->keyBy('id');
            $enrollmentIdMap = [];
            foreach ($legacyEnrollments as $oldId => $oldEnr) {
                $newStudentId = $studentIdMap[$oldEnr->student_id] ?? null;
                $newYearId = $yearIdMap[$oldEnr->academic_year_id] ?? null;
                if ($newStudentId && $newYearId) {
                    $targetEnr = Enrollment::where('student_id', $newStudentId)
                        ->where('academic_year_id', $newYearId)
                        ->first();
                    if ($targetEnr) {
                        $enrollmentIdMap[$oldId] = $targetEnr->id;
                    }
                }
            }

            // ----------------------------------------------------
            // 2. استيراد المستخدمين والصلاحيات
            // ----------------------------------------------------
            $this->info("2/5 استيراد المستخدمين واستثناءات الصلاحيات...");
            $legacyRoles = $legacyDb->table('roles')->get()->keyBy('id');
            $targetRolesByName = Role::all()->keyBy('name');
            $legacyPermissions = $legacyDb->table('permissions')->get()->keyBy('id');
            $targetPermissionsByName = Permission::all()->keyBy('name');

            $legacyUsers = $legacyDb->table('users')->get();
            $userIdMap = [];
            $usersCreated = 0;
            $usersSkipped = 0;

            foreach ($legacyUsers as $oldUser) {
                $existingUser = User::where('username', $oldUser->username)
                    ->orWhere('email', $oldUser->email)
                    ->first();

                if ($existingUser) {
                    $userIdMap[$oldUser->id] = $existingUser->id;
                    $usersSkipped++;
                    $this->line("  ℹ️ مستخدم موجود مسبقاً: {$existingUser->username} ({$existingUser->first_name} {$existingUser->last_name})");
                } else {
                    $oldRole = $legacyRoles->get($oldUser->role_id);
                    $targetRole = $oldRole ? $targetRolesByName->get($oldRole->name) : null;
                    if (! $targetRole) {
                        $targetRole = Role::where('name', 'cashier')->first() ?? Role::first();
                    }

                    $userData = [
                        'first_name' => $oldUser->first_name,
                        'last_name' => $oldUser->last_name,
                        'username' => $oldUser->username,
                        'email' => $oldUser->email,
                        'phone' => $oldUser->phone ?? null,
                        'role_id' => $targetRole->id,
                        'is_active' => (bool) ($oldUser->is_active ?? 1),
                    ];

                    if (! $isDryRun) {
                        $newUser = new User($userData);
                        $newUser->setRawAttributes(array_merge($userData, [
                            'password' => $oldUser->password, // الحفاظ على هاش كلمة المرور الأصلي
                            'created_at' => $oldUser->created_at ?: now(),
                            'updated_at' => $oldUser->updated_at ?: now(),
                        ]));
                        $newUser->save();
                        $userIdMap[$oldUser->id] = $newUser->id;
                    } else {
                        $userIdMap[$oldUser->id] = 999990 + $oldUser->id;
                    }
                    $usersCreated++;
                    $this->line("  ➕ إضافة مستخدم جديد: {$oldUser->username} ({$targetRole->display_name})");
                }
            }

            // استثناءات الصلاحيات (user_permission_overrides)
            $legacyOverrides = $legacyDb->table('user_permission_overrides')->get();
            $overridesCreated = 0;
            $overridesSkipped = 0;

            foreach ($legacyOverrides as $oldOv) {
                $newUserId = $userIdMap[$oldOv->user_id] ?? null;
                $oldPerm = $legacyPermissions->get($oldOv->permission_id);
                $targetPerm = $oldPerm ? $targetPermissionsByName->get($oldPerm->name) : null;

                if (! $newUserId || ! $targetPerm) {
                    $overridesSkipped++;
                    continue;
                }

                $exists = UserPermissionOverride::where('user_id', $newUserId)
                    ->where('permission_id', $targetPerm->id)
                    ->exists();

                if ($exists) {
                    $overridesSkipped++;
                } else {
                    if (! $isDryRun) {
                        UserPermissionOverride::create([
                            'user_id' => $newUserId,
                            'permission_id' => $targetPerm->id,
                            'effect' => $oldOv->effect,
                            'created_by' => $userIdMap[$oldOv->created_by] ?? null,
                        ]);
                    }
                    $overridesCreated++;
                }
            }

            // ----------------------------------------------------
            // 3. استيراد النوادي والمستويات والأقسام المرتبطة
            // ----------------------------------------------------
            $this->info("3/5 استيراد النوادي والمستويات والأقسام المسموحة...");

            // التأكد من وجود صنف رسوم النوادي في fee_categories
            $clubCategory = FeeCategory::where('code', 'CLUB')
                ->orWhere('name', 'معاليم النوادي')
                ->first();

            if (! $clubCategory) {
                if (! $isDryRun) {
                    $clubCategory = FeeCategory::create([
                        'name' => 'معاليم النوادي',
                        'code' => 'CLUB',
                        'is_recurring' => true,
                    ]);
                } else {
                    $clubCategory = (object) ['id' => 2, 'name' => 'معاليم النوادي'];
                }
                $this->line("  ➕ إنشاء صنف رسوم النوادي (FeeCategory: CLUB)");
            }

            $legacyClubs = $legacyDb->table('clubs')->get();
            $clubIdMap = [];
            $clubsCreated = 0;
            $clubsUpdated = 0;

            foreach ($legacyClubs as $oldClub) {
                $targetClub = Club::where('name', $oldClub->name)->first();
                $clubData = [
                    'name' => $oldClub->name,
                    'description' => $oldClub->description,
                    'fee_category_id' => $clubCategory->id,
                    'monthly_fee' => (float) ($oldClub->monthly_fee ?? 20.0),
                    'is_active' => (bool) ($oldClub->is_active ?? 1),
                ];

                if ($targetClub) {
                    if (! $isDryRun) {
                        $targetClub->update($clubData);
                    }
                    $clubIdMap[$oldClub->id] = $targetClub->id;
                    $clubsUpdated++;
                } else {
                    if (! $isDryRun) {
                        $newClub = Club::create($clubData);
                        $clubIdMap[$oldClub->id] = $newClub->id;
                    } else {
                        $clubIdMap[$oldClub->id] = 100 + $oldClub->id;
                    }
                    $clubsCreated++;
                    $this->line("  ➕ إضافة نادي جديد: {$oldClub->name} ({$clubData['monthly_fee']} د.ت)");
                }
            }

            // ربط النوادي بالمستويات (club_levels)
            $legacyClubLevels = $legacyDb->table('club_levels')->get();
            $clubLevelsCount = 0;
            foreach ($legacyClubLevels as $cl) {
                $newClubId = $clubIdMap[$cl->club_id] ?? null;
                $newLevel = $levelIdMap[$cl->level_id] ?? null;
                if ($newClubId && $newLevel) {
                    if (! $isDryRun) {
                        DB::table('club_levels')->updateOrInsert(
                            ['club_id' => $newClubId, 'level_id' => $newLevel->id],
                            []
                        );
                    }
                    $clubLevelsCount++;
                }
            }

            // ربط النوادي بالأقسام (club_sections)
            $legacyClubSections = $legacyDb->table('club_sections')->get();
            $clubSectionsCount = 0;
            foreach ($legacyClubSections as $cs) {
                $newClubId = $clubIdMap[$cs->club_id] ?? null;
                $newSectionId = $sectionIdMap[$cs->section_id] ?? null;
                if ($newClubId && $newSectionId) {
                    if (! $isDryRun) {
                        DB::table('club_sections')->updateOrInsert(
                            ['club_id' => $newClubId, 'section_id' => $newSectionId],
                            ['created_at' => now(), 'updated_at' => now()]
                        );
                    }
                    $clubSectionsCount++;
                }
            }

            // ----------------------------------------------------
            // 4. استيراد اشتراكات النوادي (club_subscriptions)
            // ----------------------------------------------------
            $this->info("4/5 استيراد اشتراكات التلاميذ في النوادي...");
            $legacySubscriptions = $legacyDb->table('club_subscriptions')->get();
            $subscriptionIdMap = [];
            $subsCreated = 0;
            $subsSkipped = 0;

            foreach ($legacySubscriptions as $oldSub) {
                $newStudentId = $studentIdMap[$oldSub->student_id] ?? null;
                $newClubId = $clubIdMap[$oldSub->club_id] ?? null;
                $newYearId = $yearIdMap[$oldSub->academic_year_id] ?? null;
                $newEnrollmentId = $enrollmentIdMap[$oldSub->enrollment_id] ?? null;

                if (! $newStudentId || ! $newClubId || ! $newYearId) {
                    $subsSkipped++;
                    continue;
                }

                $existingSub = ClubSubscription::where('student_id', $newStudentId)
                    ->where('club_id', $newClubId)
                    ->where('academic_year_id', $newYearId)
                    ->first();

                $subData = [
                    'student_id' => $newStudentId,
                    'club_id' => $newClubId,
                    'academic_year_id' => $newYearId,
                    'enrollment_id' => $newEnrollmentId,
                    'start_date' => $oldSub->start_date ?: '2026-09-01',
                    'end_date' => $oldSub->end_date ?: null,
                    'status' => $oldSub->status ?: 'active',
                    'monthly_fee_override' => $oldSub->monthly_fee_override,
                    'excluded_at' => $oldSub->excluded_at,
                    'excluded_by' => $userIdMap[$oldSub->excluded_by] ?? null,
                    'exclusion_reason' => $oldSub->exclusion_reason,
                ];

                if ($existingSub) {
                    if (! $isDryRun) {
                        $existingSub->update($subData);
                    }
                    $subscriptionIdMap[$oldSub->id] = $existingSub->id;
                    $subsSkipped++;
                } else {
                    if (! $isDryRun) {
                        $newSub = ClubSubscription::create($subData);
                        $subscriptionIdMap[$oldSub->id] = $newSub->id;
                    } else {
                        $subscriptionIdMap[$oldSub->id] = 50000 + $oldSub->id;
                    }
                    $subsCreated++;
                }
            }

            // ----------------------------------------------------
            // 5. استيراد معاليم النوادي الشهرية (club_monthly_fees)
            // ----------------------------------------------------
            $this->info("5/5 استيراد معاليم النوادي الشهرية...");
            $legacyFees = $legacyDb->table('club_monthly_fees')->get();
            $feesCreated = 0;
            $feesSkipped = 0;

            foreach ($legacyFees as $oldFee) {
                $newStudentId = $studentIdMap[$oldFee->student_id] ?? null;
                $newClubId = $clubIdMap[$oldFee->club_id] ?? null;
                $newYearId = $yearIdMap[$oldFee->academic_year_id] ?? null;
                $newEnrollmentId = $enrollmentIdMap[$oldFee->enrollment_id] ?? null;
                $newSubId = $subscriptionIdMap[$oldFee->club_subscription_id] ?? null;

                if (! $newStudentId || ! $newClubId || ! $newYearId || empty($oldFee->month)) {
                    $feesSkipped++;
                    continue;
                }

                $existingFee = ClubMonthlyFee::where('student_id', $newStudentId)
                    ->where('club_id', $newClubId)
                    ->where('month', $oldFee->month)
                    ->where('academic_year_id', $newYearId)
                    ->first();

                $feeData = [
                    'student_id' => $newStudentId,
                    'club_id' => $newClubId,
                    'academic_year_id' => $newYearId,
                    'enrollment_id' => $newEnrollmentId,
                    'club_subscription_id' => $newSubId,
                    'month' => $oldFee->month,
                    'amount_due' => (float) $oldFee->amount_due,
                    'amount_paid' => (float) ($oldFee->amount_paid ?? 0),
                    'status' => $oldFee->status ?: 'unpaid',
                    'paid_at' => $oldFee->paid_at,
                    'method' => $oldFee->method,
                    'reference' => $oldFee->reference,
                    'notes' => $oldFee->notes,
                    'created_by' => $userIdMap[$oldFee->created_by] ?? null,
                    'cancelled_at' => $oldFee->cancelled_at,
                    'cancelled_by' => $userIdMap[$oldFee->cancelled_by] ?? null,
                    'cancellation_reason' => $oldFee->cancellation_reason,
                ];

                if ($existingFee) {
                    if (! $isDryRun) {
                        $existingFee->update($feeData);
                    }
                    $feesSkipped++;
                } else {
                    if (! $isDryRun) {
                        ClubMonthlyFee::create($feeData);
                    }
                    $feesCreated++;
                }
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->warn("\n🏁 اكتمال فحص التجربة (DRY-RUN): تم التراجع عن كافة التغييرات بأمان.");
            } else {
                DB::commit();
                $this->info("\n✅ نجاح: تم استيراد كافة بيانات النوادي والمستخدمين بنجاح!");
            }

            $this->table(
                ['الكيان (Entity)', 'سجلات جديدة (Created)', 'محدثة / موجودة (Updated/Skipped)'],
                [
                    ['المستخدمون (Users)', $usersCreated, $usersSkipped],
                    ['استثناءات الصلاحيات (Permission Overrides)', $overridesCreated, $overridesSkipped],
                    ['النوادي (Clubs)', $clubsCreated, $clubsUpdated],
                    ['ربط النوادي بالمستويات (Club Levels)', $clubLevelsCount, 0],
                    ['ربط النوادي بالأقسام (Club Sections)', $clubSectionsCount, 0],
                    ['اشتراكات النوادي (Club Subscriptions)', $subsCreated, $subsSkipped],
                    ['معاليم النوادي الشهرية (Club Monthly Fees)', $feesCreated, $feesSkipped],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("❌ فشلت العملية وتم التراجع عن كافة التغييرات: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }
    }
}
