<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط رسم التلميذ بنوع الرسم.
 *
 * كان الاستخلاص يُنشئ student_fees بـ fee_plan_id = null ويكتب اسم النوع في الوصف نصاً،
 * فيستحيل على الدفتر أن يعرف بند المداخيل الصحيح (تسجيل / أشهر / منتجات / أقساط).
 * هذا العمود يُعيد الرابط البنيوي بدل الاعتماد على مطابقة النصوص.
 *
 * ملاحظة: عمود بلا قيد مفتاح أجنبي عن قصد، لأن SQLite (بيئة التشغيل الحالية)
 * لا يدعم إضافة قيد أجنبي على جدول قائم؛ الفهرس يكفي للأداء والسلامة المنطقية محفوظة في الخدمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_type_id')->nullable()->after('fee_plan_id');
            $table->index('fee_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->dropIndex(['fee_type_id']);
            $table->dropColumn('fee_type_id');
        });
    }
};
