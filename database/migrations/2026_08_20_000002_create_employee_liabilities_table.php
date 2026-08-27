<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مستحقات الإطارات القديمة (Employee Liabilities).
 *
 * مداخيل/التزامات مالية تجاه الإطارات من سنوات سابقة (أجور غير مدفوعة، سلف،
 * منح، أو غيرها) تُدخل يدوياً من بيانات خارجية. خلاصها يمرّ بالدفتر النقدي
 * كبند مستقل (old_liability_payment) لا كصرف للسنة الحالية.
 *
 * الروابط المضافة:
 * - salaries.employee_liability_id و employee_advances.employee_liability_id:
 *   ربط اختياري يحدّد أي راتب/سلفة خلّص أي استحقاق، فيُشتقّ المتبقّي من
 *   المدفوعات الفعلية المرتبطة دون تخزين رصيد مخبّأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_liabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // السنة الدراسية التي نُقل إليها الاستحقاق (السنة الجديدة).
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            // تسمية السنة الأصلية للاستحقاق (مثال: 2025/2026).
            $table->string('original_year_label', 20);
            $table->string('liability_type', 20)->default('salary'); // salary | advance | bonus | other
            $table->string('description', 255);
            $table->decimal('original_amount', 12, 3);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending'); // pending | partial | paid
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'academic_year_id'], 'employee_liability_emp_year_idx');
        });

        // ربط اختياري: أي راتب خلّص أي استحقاق سابق.
        Schema::table('salaries', function (Blueprint $table) {
            $table->foreignId('employee_liability_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('employee_liabilities')
                ->nullOnDelete();
        });

        // ربط اختياري: أي سلفة خلّصت أي استحقاق سابق من نوع سلفة.
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->foreignId('employee_liability_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('employee_liabilities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_liability_id');
        });

        Schema::table('salaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_liability_id');
        });

        Schema::dropIfExists('employee_liabilities');
    }
};
