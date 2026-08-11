<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite (>= 3.35) يرفض DROP COLUMN لعمود مذكور في تعريف مفتاح أجنبي،
            // لذلك نعيد بناء الجدول. البنية النهائية مطابقة لمسار MySQL حرفياً.
            Schema::create('payments_rebuilt', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->date('payment_date');
                $table->string('method', 50)->default('cash');
                $table->string('reference', 100)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            DB::statement(
                'INSERT INTO payments_rebuilt '
                . '(id, student_id, amount, payment_date, created_by, created_at, updated_at) '
                . 'SELECT id, student_id, amount, date(created_at), created_by, created_at, updated_at '
                . 'FROM payments'
            );

            Schema::drop('payments');
            Schema::rename('payments_rebuilt', 'payments');

            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable()
                  ->constrained()->nullOnDelete()->after('student_id');
            $table->date('payment_date')->after('amount');
            $table->string('method', 50)->default('cash')->after('payment_date');
            $table->string('reference', 100)->nullable()->after('method');
            $table->text('notes')->nullable()->after('reference');

            $table->dropForeign(['academic_year_id']);
            $table->dropColumn(['academic_year_id', 'paid_amount', 'status']);
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::create('payments_reverted', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 10, 2);
                $table->decimal('paid_amount', 10, 2)->default(0);
                $table->string('status', 20)->default('unpaid');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            Schema::drop('payments');
            Schema::rename('payments_reverted', 'payments');

            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
            $table->dropColumn(['enrollment_id', 'payment_date', 'method', 'reference', 'notes']);
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->enum('status', ['paid', 'unpaid', 'partial'])->default('unpaid');
        });
    }
};
