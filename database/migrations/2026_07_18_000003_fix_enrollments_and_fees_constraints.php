<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL does not support partial indexes - uniqueness is enforced in StudentService
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
            $table->dropUnique(['student_id', 'academic_year_id']);
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });

        // SQLite cannot ALTER foreign keys. We skip only the constraint tightening
        // under sqlite (test environment). MySQL/PostgreSQL behaviour is unchanged.
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('student_fees', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['enrollment_id']);
                // restrictOnDelete: prevents deleting an enrollment that has fees
                $table->foreign('enrollment_id')
                      ->references('id')->on('enrollments')
                      ->restrictOnDelete();
            }

            $table->unique(
                ['enrollment_id', 'fee_plan_id', 'due_date'],
                'unique_student_fee_instance'
            );
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('student_fees', function (Blueprint $table) use ($driver) {
            $table->dropUnique('unique_student_fee_instance');

            if ($driver !== 'sqlite') {
                $table->dropForeign(['enrollment_id']);
                $table->foreign('enrollment_id')
                      ->references('id')->on('enrollments')->cascadeOnDelete();
            }
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_id', 'academic_year_id']);
        });
    }
};
