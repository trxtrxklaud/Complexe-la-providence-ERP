<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('manual_student_debt_id')
                ->nullable()
                ->after('student_fee_id')
                ->constrained('manual_student_debts')
                ->nullOnDelete();

            $table->foreignId('opening_balance_id')
                ->nullable()
                ->after('manual_student_debt_id')
                ->constrained('opening_balances')
                ->nullOnDelete();

            $table->index('manual_student_debt_id');
            $table->index('opening_balance_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['manual_student_debt_id']);
            $table->dropForeign(['opening_balance_id']);
            $table->dropIndex(['manual_student_debt_id']);
            $table->dropIndex(['opening_balance_id']);
            $table->dropColumn(['manual_student_debt_id', 'opening_balance_id']);
        });
    }
};