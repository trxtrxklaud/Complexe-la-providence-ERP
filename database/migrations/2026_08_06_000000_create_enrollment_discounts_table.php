<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التخفيض السنوي على معاليم التلميذ (تخفيض ثابت لا متخلّد).
 *
 * فرق جوهري عن التنازل (fee_waivers): التنازل يُقفل دَيناً قائماً بعد نشوئه،
 * أمّا التخفيض فيُنقص أصل ما يُطالَب به التلميذ للسنة كلّها — سعر مخفّض لا دَين.
 * يُطبَّق مرّة واحدة (مثلاً 20 د في سبتمبر) ويسري على السنة الدراسية بأكملها،
 * ولا يتغيّر شهراً بشهر، وسقفه 20% من مجموع معاليم السنة.
 *
 * التخفيض ليس متخلّداً وليس مدخولاً: لا يدخل الخزينة ولا يخرج منها.
 * المتخلّد = (المعاليم − التخفيض) − المقبوض. التخفيض يُطرح من المستحقّ لا يُضاف إليه.
 *
 * كبقية وثائق المنصة: يُلغى ولا يُحذف، فيبقى أثره وأثر إلغائه مقروءاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_discounts', function (Blueprint $table) {
            $table->id();

            // مراجع بلا قيود خارجية: SQLite يعيد بناء الجدول عند كل تعديل لاحق.
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('academic_year_id');

            $table->decimal('amount', 10, 2);
            // نسبة مرجعية للعرض فقط؛ المبلغ الثابت هو مصدر الحقيقة في الحساب.
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('reason', 500);
            $table->date('applied_date');

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['enrollment_id', 'academic_year_id'], 'enrollment_discounts_enrollment_year_idx');
            $table->index('cancelled_at', 'enrollment_discounts_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_discounts');
    }
};
