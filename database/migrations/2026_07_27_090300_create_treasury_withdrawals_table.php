<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سحوبات الخزينة.
 * حركة مستقلة تماماً عن المصاريف: لا تُحتسب ضمن "مجموع المصاريف" ولا تؤثر على الدخل الصافي،
 * بل تُخصم من الرصيد بعد احتساب الدخل الصافي — السحب نقل أموال لا استهلاك، فلا يُنقِص الدخل الصافي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->date('withdrawn_at');
            $table->string('type', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index('withdrawn_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_withdrawals');
    }
};
