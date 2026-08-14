<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأرصدة الافتتاحية (Opening Balances).
 *
 * عند إقفال سنة دراسية تُنقل المتخلّدات غير المدفوعة إلى السنة الجديدة كأرصدة
 * افتتاحية، مع الحفاظ على مصدر الدَّين (الرسم الأصلي) وسنته الدراسية.
 *
 * القواعد:
 * - لا يُحذف الدَّين القديم أبداً، ولا يُحوَّل إلى مدخول للسنة الجديدة.
 * - الربط بالرسم الأصلي (source_student_fee_id) يحفظ مصدر الدَّين،
 *   وبالسنة الدراسية (academic_year_id) يحفظ السنة التي نُقل إليها الرصيد.
 * - قيد الفرادة (source_student_fee_id, academic_year_id) يمنع ازدواج
 *   الترحيل لنفس الدَّين إلى نفس السنة (لا ترحيل مزدوج).
 * - الإلغاء موثّق ولا حذف نهائي، حفاظاً على مسار التدقيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('source_enrollment_id')->constrained('enrollments');
            $table->foreignId('source_student_fee_id')->constrained('student_fees');
            // السنة الدراسية التي نُقل إليها الرصيد (السنة الجديدة).
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->decimal('amount', 12, 3);
            $table->string('status', 20)->default('pending'); // pending | partial | paid
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'academic_year_id'], 'opening_balance_student_year_idx');
            $table->unique(['source_student_fee_id', 'academic_year_id'], 'opening_balance_source_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
