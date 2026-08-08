<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_levels', function (Blueprint $table) {
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->primary(['club_id', 'level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_levels');
    }
};
