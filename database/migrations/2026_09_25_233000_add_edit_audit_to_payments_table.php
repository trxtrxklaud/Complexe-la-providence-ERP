<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('old_amount', 12, 3)->nullable()->after('amount');
            $table->decimal('new_amount', 12, 3)->nullable()->after('old_amount');
            $table->foreignId('edited_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['edited_by']);
            $table->dropColumn(['old_amount', 'new_amount', 'edited_by', 'edited_at']);
        });
    }
};
