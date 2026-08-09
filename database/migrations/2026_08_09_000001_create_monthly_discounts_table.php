<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_discounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->string('discount_type', 30); // normal_monthly | full_waiver | humanitarian_fixed

            $table->decimal('monthly_amount', 10, 2)->nullable(); // NULL for full_waiver
            $table->string('fee_category', 30)->default('tuition');

            $table->char('start_month', 7); // YYYY-MM
            $table->char('end_month', 7);   // YYYY-MM

            $table->string('reason', 500);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['enrollment_id', 'academic_year_id'], 'monthly_disc_enroll_year_idx');
            $table->index('cancelled_at', 'monthly_disc_cancelled_idx');
            $table->index('discount_type', 'monthly_disc_type_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_discounts');
    }
};
