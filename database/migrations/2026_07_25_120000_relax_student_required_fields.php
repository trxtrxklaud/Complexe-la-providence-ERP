<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * يسمح بتسجيل التلميذ بالاسم واللقب فقط، وتُستكمل بقية البيانات لاحقاً.
 * كان حقل gender من نوع enum إجبارياً بلا قيمة افتراضية — فينفجر عند أول حفظ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->change();
        });

        if (! Schema::hasColumn('students', 'mother_name')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('mother_name')->nullable()->after('guardian_last_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'mother_name')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn('mother_name');
            });
        }

        // لا نُعيد gender إلزامياً: قد توجد صفوف فارغة تمنع التراجع.
    }
};
