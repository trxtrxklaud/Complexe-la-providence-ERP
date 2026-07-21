<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // MySQL لا يدعم partial indexes — الـ uniqueness تُدار في StudentService
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'academic_year_id']);
        });
        Schema::table('student_fees', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
            // restrictOnDelete: يمنع حذف enrollment له fees — الأسلم مالياً
            $table->foreign('enrollment_id')
                  ->references('id')->on('enrollments')
                  ->restrictOnDelete();
            $table->unique(
                ['enrollment_id', 'fee_plan_id', 'due_date'],
                'unique_student_fee_instance'
            );
        });
    }
    public function down(): void {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->dropUnique('unique_student_fee_instance');
            $table->dropForeign(['enrollment_id']);
            $table->foreign('enrollment_id')
                  ->references('id')->on('enrollments')->cascadeOnDelete();
        });
        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_id', 'academic_year_id']);
        });
    }
};
