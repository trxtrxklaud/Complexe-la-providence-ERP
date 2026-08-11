<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سلف الإطارات (تسبقة الرواتب).
 * السلفة خروج نقدي فوري، ثم تُخصم لاحقاً من الراتب أو تُسدَّد نقداً (خلاص سلفة).
 * settled_amount يتتبّع ما تمّ استرجاعه، و status يُشتق منه في طبقة الخدمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('settled_amount', 12, 2)->default(0);
            $table->date('advance_date');
            $table->string('method', 50)->default('cash');
            $table->string('reason', 200)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index('advance_date');
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advances');
    }
};
