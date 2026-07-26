<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // إلغاء موثّق بدل الحذف النهائي: نحتفظ بالسجل مع سبب الإلغاء
        // والمستخدِم المنفّذ وتاريخ العملية.
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('meta');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
            $table->index('cancelled_at');
        });

        Schema::table('salaries', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('notes');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });

        Schema::table('salaries', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
