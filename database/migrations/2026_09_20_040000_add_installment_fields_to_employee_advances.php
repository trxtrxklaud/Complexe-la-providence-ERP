<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_advances', 'due_date')) {
                $table->date('due_date')->nullable();
            }
            if (! Schema::hasColumn('employee_advances', 'installment_count')) {
                $table->unsignedInteger('installment_count')->default(1);
            }
            if (! Schema::hasColumn('employee_advances', 'repayment_method')) {
                $table->string('repayment_method', 50)->default('salary_deduction');
            }
            if (! Schema::hasColumn('employee_advances', 'purpose')) {
                $table->string('purpose', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $cols = [];
            foreach (['due_date', 'installment_count', 'repayment_method', 'purpose'] as $col) {
                if (Schema::hasColumn('employee_advances', $col)) {
                    $cols[] = $col;
                }
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
