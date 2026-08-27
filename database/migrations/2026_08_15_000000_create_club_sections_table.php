<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('club_sections')) {
            Schema::create('club_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('club_id')->constrained()->cascadeOnDelete();
                $table->foreignId('section_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                
                $table->unique(['club_id', 'section_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('club_sections');
    }
};