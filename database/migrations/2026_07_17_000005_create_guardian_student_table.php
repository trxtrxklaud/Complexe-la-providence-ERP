<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_student', function (Blueprint $table) {
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 50)->default('primary'); // primary, secondary, other
            $table->boolean('is_primary_contact')->default(false);
            $table->timestamps();

            $table->primary(['guardian_id', 'student_id']);
            $table->index(['student_id', 'is_primary_contact']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_student');
    }
};
