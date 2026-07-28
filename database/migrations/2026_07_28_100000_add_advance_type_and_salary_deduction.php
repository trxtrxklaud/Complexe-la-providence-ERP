<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسبقة والسلفة ليستا شيئاً واحداً:
 *   التسبقة (advance) تُخصم كاملة من راتب الشهر نفسه.
 *   السلفة (loan) تُردّ على مهل دفعات.
 *
 * وما دام الخصم يجري عند خلاص الراتب، يجب أن تحفظ السلفة أيّ راتب خلّصها
 * حتّى يمكن عكس العملية بدقّة إن أُلغي ذلك الراتب.
 *
 * SQLite: أعمدة بسيطة بلا مفاتيح أجنبية وبلا after().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            // advance = تسبقة، loan = سلفة
            $table->string('type', 20)->default('advance');
            $table->unsignedBigInteger('settled_by_salary_id')->nullable();
            // رصيد افتتاحي منقول من سنة سابقة: دَين قائم لا يمسّ خزينة هذه السنة.
            $table->boolean('is_opening')->default(false);
        });

        Schema::table('salaries', function (Blueprint $table) {
            // amount يبقى الصافي المدفوع فعلاً — وهو وحده ما يقرأه الدفتر النقدي.
            $table->decimal('gross_amount', 10, 2)->nullable();
            $table->decimal('advance_deduction', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('employee_advances', function (Blueprint $table) {
            $table->dropColumn(['type', 'settled_by_salary_id', 'is_opening']);
        });

        Schema::table('salaries', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'advance_deduction']);
        });
    }
};
