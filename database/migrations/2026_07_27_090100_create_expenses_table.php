<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المصاريف العامة (خارج الأجور والسلف).
 * لا يوجد حذف نهائي: الإلغاء موثّق عبر cancelled_at / cancelled_by / cancellation_reason
 * تماشياً مع سياسة النزاهة المالية المعتمدة في payments و salaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 200);
            $table->decimal('amount', 12, 3);
            $table->date('expense_date');
            $table->string('method', 50)->default('cash');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index('expense_date');
            $table->index(['expense_category_id', 'expense_date']);
            $table->index(['academic_year_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
