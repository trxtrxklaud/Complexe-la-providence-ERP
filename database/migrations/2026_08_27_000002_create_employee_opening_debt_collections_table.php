<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تحصيلات ديون الإطارات القديمة (سجل تحصيل مستقل لكل دفعة).
 *
 * كل دفعة تمثل مستنداً مالياً مستقلاً يسقط أثره النقدي في الخزينة
 * كـ CashTransaction منفصلة مرتبطة بـ OldEmployeeDebtCollection،
 * مما يضمن الدقة المحاسبية وعدم تعديل المبالغ التاريخية السابقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_opening_debt_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_opening_debt_id')
                ->constrained('employee_opening_debts')
                ->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_opening_debt_id');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_opening_debt_collections');
    }
};