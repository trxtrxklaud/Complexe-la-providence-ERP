<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأرصدة الافتتاحية التاريخية للإطارات (ديون قديمة مدخلة يدوياً).
 *
 * قيد إداري تاريخي بحت: لا ينشئ رواتب، ولا سلفاً، ولا قيوداً نقدية،
 * ولا يغيّر دخل التشغيل أو صافي الدخل. الأثر النقدي الوحيد ينشأ حصراً
 * عند تحصيل الدين نقداً عبر CashTransaction داخلة في الخزينة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_opening_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->string('original_year_label', 20);
            $table->string('debt_type', 20);
            $table->string('description', 255);
            $table->decimal('original_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'academic_year_id']);
            $table->index(['academic_year_id', 'status']);
            $table->index(['cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_opening_debts');
    }
};
