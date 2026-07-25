<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'months')) {
                $table->json('months')->nullable()->after('enrollment_id');
            }
        });

        Schema::table('student_fees', function (Blueprint $table) {
            if (Schema::hasColumn('student_fees', 'fee_plan_id')) {
                $table->foreignId('fee_plan_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'months')) {
                $table->dropColumn('months');
            }
        });
    }
};
