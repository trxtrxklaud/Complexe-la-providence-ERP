<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفتر النقدي المركزي (Centralized Ledger).
 *
 * كل حركة مال في المنصة — مداخيل التلاميذ، بيع المنتجات، الأجور، السلف،
 * المصاريف، سحوبات الخزينة — تُسجَّل هنا كسطر واحد موحَّد.
 * هذا الجدول هو المصدر الوحيد للحقيقة في كل التقارير المالية،
 * أما الجداول المصدرية (payments, salaries, expenses...) فتبقى تفاصيل تشغيلية.
 *
 * القواعد:
 * - amount موجب دائماً، والاتجاه يُحدَّد بـ direction (in | out).
 * - المصدر مرتبط بشكل polymorphic عبر source_type + source_id.
 * - قيد الفرادة يمنع ازدواج التسجيل لنفس المستند ونفس البند (idempotency).
 * - الإلغاء موثّق ولا يُحذف السطر أبداً، حفاظاً على مسار التدقيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->string('direction', 3);
            $table->string('category', 40);
            $table->decimal('amount', 12, 3);
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['transaction_date', 'direction'], 'cash_tx_date_direction_idx');
            $table->index(['category', 'transaction_date'], 'cash_tx_category_date_idx');
            $table->index(['source_type', 'source_id'], 'cash_tx_source_idx');
            $table->unique(['source_type', 'source_id', 'category'], 'cash_tx_source_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
