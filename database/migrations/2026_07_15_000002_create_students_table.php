<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob')->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();
            
            // Guardian Data
            $table->string('guardian_first_name')->nullable();
            $table->string('guardian_last_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->text('address')->nullable();
            $table->string('mother_phone')->nullable();
            $table->string('mother_email')->nullable();

            // Payment Data
            $table->string('payment_method')->nullable();
            $table->decimal('registration_amount', 10, 2)->nullable();
            $table->date('payment_date')->nullable();
            $table->text('payment_notes')->nullable();
            
            $table->date('enrollment_date')->nullable();
            $table->string('status')->default('active');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
