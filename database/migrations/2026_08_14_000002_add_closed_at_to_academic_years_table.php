<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاريخ إقفال السنة الدراسية.
 *
 * السطر null يعني أن السنة مفتوحة؛ ملؤه يمنع تكرار إقفال السنة
 * وإعادة ترحيل الأرصدة الافتتاحية لها (درع ضد الازدواج).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
