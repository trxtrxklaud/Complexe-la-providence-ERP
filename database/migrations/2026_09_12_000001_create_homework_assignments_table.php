<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['section_id', 'due_date']);
            $table->index(['published_at', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_assignments');
    }
};
