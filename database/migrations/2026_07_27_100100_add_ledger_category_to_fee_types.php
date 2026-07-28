<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند المداخيل الخاص بكل نوع رسم.
 *
 * يُخزَّن كبيانات لا كشيفرة، حتى يستطيع المسؤول تصنيف أي نوع رسم جديد
 * دون تعديل الكود، وتظل التقارير مطابقة للتقرير المحاسبي القديم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_types', function (Blueprint $table) {
            $table->string('ledger_category', 40)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('fee_types', function (Blueprint $table) {
            $table->dropColumn('ledger_category');
        });
    }
};
