<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use Illuminate\Database\Seeder;

/**
 * السنة الدراسية القادمة — وعاء التسجيل الجديد.
 * لا تُفعّل تلقائياً حتّى لا يتأثّر الاستخلاص الجاري.
 */
class AcademicYear2027Seeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2026-2027'],
            [
                'start_date' => '2026-09-15',
                'end_date' => '2027-06-30',
                'is_active' => false,
            ]
        );

        $this->command?->info(
            $year->wasRecentlyCreated
                ? '✅ أُضيفت السنة الدراسية 2026-2027.'
                : 'ℹ️ السنة 2026-2027 موجودة من قبل.'
        );
    }
}
