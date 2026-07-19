<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AcademicYear;
use App\Models\Level;
use App\Models\Section;
use App\Models\FeeCategory;
use App\Models\FeePlan;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use App\Models\Student;
use App\Models\Guardian;
use App\Models\Enrollment;
use Illuminate\Support\Facades\Hash;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        // Academic Years
        $currentYear = AcademicYear::firstOrCreate(
            ['name' => '2025-2026'],
            ['start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]
        );

        AcademicYear::firstOrCreate(
            ['name' => '2024-2025'],
            ['start_date' => '2024-09-01', 'end_date' => '2025-06-30', 'is_active' => false]
        );

        // Levels
        $levelsData = [
            ['name' => 'السنة الأولى', 'code' => 'L1', 'order' => 1],
            ['name' => 'السنة الثانية', 'code' => 'L2', 'order' => 2],
            ['name' => 'السنة الثالثة', 'code' => 'L3', 'order' => 3],
            ['name' => 'السنة الرابعة', 'code' => 'L4', 'order' => 4],
            ['name' => 'السنة الخامسة', 'code' => 'L5', 'order' => 5],
            ['name' => 'السنة السادسة', 'code' => 'L6', 'order' => 6],
        ];

        $levels = [];
        foreach ($levelsData as $data) {
            $levels[] = Level::firstOrCreate(['code' => $data['code']], $data);
        }

        // Sections
        $sectionNames = ['أ', 'ب', 'ج', 'د', 'هـ'];
        foreach ($levels as $level) {
            foreach ($sectionNames as $name) {
                Section::firstOrCreate(
                    ['level_id' => $level->id, 'name' => $name],
                    ['code' => $level->code . '-' . $name, 'capacity' => 28]
                );
            }
        }

        // Fee Categories & Plans
        $scolarite = FeeCategory::firstOrCreate(['code' => 'scolarite'], [
            'name' => 'الرسوم الدراسية', 'is_recurring' => true
        ]);

        foreach ($levels as $level) {
            FeePlan::firstOrCreate([
                'academic_year_id' => $currentYear->id,
                'level_id' => $level->id,
                'fee_category_id' => $scolarite->id,
            ], [
                'name' => 'رسوم شهرية - ' . $level->name,
                'amount' => 450 + ($level->order * 25),
                'frequency' => 'monthly',
                'due_day' => 5,
            ]);
        }

        // Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'مدير النظام']);

        // Admin User
        User::firstOrCreate(
            ['email' => 'admin@laprovidence.ma'],
            [
                'first_name' => 'مدير', 'last_name' => 'النظام', 'username' => 'admin',
                'password' => Hash::make(env('ADMIN_DEFAULT_PASSWORD', 'ChangeMe123!')),
                'role_id' => $adminRole->id,
            ]
        );

        // Sample realistic students
        $sampleData = [
            ['Youssef', 'El Amine', 'male', '2015-03-12'],
            ['Fatima', 'Zahra', 'female', '2014-07-22'],
            ['Omar', 'Khatab', 'male', '2013-11-05'],
            ['Maryam', 'Alaoui', 'female', '2015-09-18'],
        ];

        $firstLevel = $levels[0];
        $firstSection = Section::where('level_id', $firstLevel->id)->first();

        foreach ($sampleData as $i => $data) {
            $student = Student::firstOrCreate(
                ['first_name' => $data[0], 'last_name' => $data[1]],
                [
                    'student_code' => 'PRV-2026-' . str_pad($i + 100, 4, '0', STR_PAD_LEFT),
                    'dob' => $data[3],
                    'gender' => $data[2],
                    'status' => 'active',
                ]
            );

            $guardian = Guardian::firstOrCreate(
                ['phone' => '0612345' . str_pad($i, 3, '0', STR_PAD_LEFT)],
                [
                    'first_name' => $i % 2 === 0 ? 'محمد' : 'خديجة',
                    'last_name' => $data[1],
                    'address' => 'حي السلام، الرباط',
                ]
            );

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => ['relationship' => 'primary', 'is_primary_contact' => true]
            ]);

            Enrollment::firstOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => $currentYear->id],
                [
                    'level_id' => $firstLevel->id,
                    'section_id' => $firstSection->id,
                    'enrollment_date' => now()->subDays(rand(15, 80)),
                    'status' => 'active',
                ]
            );
        }

        $this->command->info('✅ Realistic School La Providence seed data loaded.');
    }
}
