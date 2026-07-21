<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // أضف الأعمدة الجديدة
            $table->foreignId('enrollment_id')->nullable()
                  ->constrained()->nullOnDelete()->after('student_id');
            $table->date('payment_date')->after('amount');
            $table->string('method', 50)->default('cash')->after('payment_date');
            $table->string('reference', 100)->nullable()->after('method');
            $table->text('notes')->nullable()->after('reference');

            // احذف الأعمدة القديمة غير المستخدمة
            $table->dropForeign(['academic_year_id']);
            $table->dropColumn(['academic_year_id', 'paid_amount', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
            $table->dropColumn(['enrollment_id','payment_date','method','reference','notes']);
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->enum('status', ['paid','unpaid','partial'])->default('unpaid');
        });
    }
};
