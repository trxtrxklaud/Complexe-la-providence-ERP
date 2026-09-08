<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سِجلّ تنبيهات الدفع المرسَلة لأولياء الأمور عبر SMS.
 * جدول سِجِلّي (audit) — لا يُحذف منه شيء؛ كل إرسال يُسجَّل
 * سوياً مع نتيجته لمعرفة ما وصل فعلاً وما فشل، ومنع الإزعاج
 * بإعادة إرسال نفس النوع لنفس التلميذ في نفس الشهر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained();
            // شهر القسط المستحق — YYYY-MM.
            $table->string('fee_month', 7);
            // first | mid | final
            $table->string('type', 10);
            $table->string('phone', 20);
            $table->boolean('sent')->default(false);
            // فشل الإرسال: سبب مختصر للمشرف (لا يُرسل للوليّ).
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // منع إرسال نفس النوع لنفس التلميذ في نفس الشهر مرتين.
            $table->unique(['student_id', 'fee_month', 'type'], 'payment_reminders_unique_send_idx');
            $table->index(['fee_month', 'type', 'sent'], 'payment_reminders_month_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
