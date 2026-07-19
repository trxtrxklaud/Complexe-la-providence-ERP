<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_plan_id')->constrained()->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount_due', 10, 2);
            $table->date('due_date');
            $table->enum('status', ['pending', 'partial', 'paid', 'overdue'])->default('pending');
            $table->timestamps();

            $table->index(['enrollment_id', 'due_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fees');
    }
};
