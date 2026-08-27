<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تصنيف الإطارات: نوع الإطار (staff_type) ونوع الأجر (salary_type).
 *
 * إضافة غير تدميرية: default_salary يبقى كما هو، وmonthly_salary
 * يُملأ من default_salary للإطارات الموجودة ثم من النموذج للجدد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('staff_type', 30)->default('monthly_teacher')->after('job_title');
            $table->string('salary_type', 10)->default('monthly')->after('staff_type');
            $table->decimal('hourly_rate', 12, 3)->nullable()->after('salary_type');
            $table->decimal('monthly_salary', 12, 3)->nullable()->after('hourly_rate');
        });

        // backfill: monthly_salary يُملأ من default_salary للموجودين.
        DB::table('employees')
            ->whereNotNull('default_salary')
            ->update(['monthly_salary' => DB::raw('default_salary')]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['staff_type', 'salary_type', 'hourly_rate', 'monthly_salary']);
        });
    }
};