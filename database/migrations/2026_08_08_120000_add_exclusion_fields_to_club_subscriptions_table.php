<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_subscriptions', function (Blueprint $table) {
            $table->timestamp('excluded_at')->nullable()->after('monthly_fee_override');
            $table->foreignId('excluded_by')->nullable()->after('excluded_at')->constrained('users')->nullOnDelete();
            $table->text('exclusion_reason')->nullable()->after('excluded_by');
        });
    }

    public function down(): void
    {
        Schema::table('club_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['excluded_by']);
            $table->dropColumn(['excluded_at', 'excluded_by', 'exclusion_reason']);
        });
    }
};
