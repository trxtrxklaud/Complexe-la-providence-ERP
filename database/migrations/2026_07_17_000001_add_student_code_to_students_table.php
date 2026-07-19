<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Phase 1: nullable for backward compatibility with existing data.
            // Future migration should make this non-nullable + add generation logic (e.g. PRV-YYYY-XXXX).
            $table->string('student_code', 50)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['student_code']);
            $table->dropColumn('student_code');
        });
    }
};
