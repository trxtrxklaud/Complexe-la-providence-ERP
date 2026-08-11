<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ردّيات السلف.
 *
 * settled_amount في employee_advances رقم تراكمي بلا تاريخ، والدفتر النقدي
 * يحتاج أن يعرف متى دخل كل مليم لا كم صار المجموع. وزيادةً على ذلك،
 * LedgerService::post() مفتاحه (source_type, source_id, category)، فلو أُسقطت الردّيات
 * على السلفة نفسها لاندمجت كلّها في سطر واحد يحمل تاريخ آخر دفعة.
 * لذلك صار كل ردّ مستنداً قائماً بذاته له مفتاحه في الدفتر.
 *
 * method يحسم الأثر النقدي:
 *   cash             → مال دخل الصندوق فعلاً → سطر دخل في بند advance_repayment
 *   salary_deduction → لم يدخل مال، بل نقَص الخارج → لا سطر له إطلاقاً،
 *                       لأن راتب الشهر أُسقِط صافياً والخصم محتسَب فيه أصلاً.
 *   إسقاط سطر دخل للخصم من الراتب ينفخ المداخيل والمصاريف معاً بمبلغ وهمي.
 *
 * بلا مفاتيح أجنبية: SQLite في التشغيل الحالي، والفهارس تكفي للأداء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advance_repayments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('employee_advance_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('academic_year_id')->nullable()->index();

            $table->decimal('amount', 12, 3);
            $table->date('repaid_at')->index();

            // cash | salary_deduction
            $table->string('method', 30)->default('cash')->index();

            // يُملأ حصراً حين method = salary_deduction
            $table->unsignedBigInteger('salary_id')->nullable()->index();

            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            // لا حذف نهائياً — الإلغاء موثّق مثل بقية المستندات المالية.
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advance_repayments');
    }
};
