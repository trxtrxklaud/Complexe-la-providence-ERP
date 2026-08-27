<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الديون القديمة المدخلة يدوياً (Manual Student Debts).
 *
 * تُدخَل عندما لا يوجد رسم سابق في النظام أصلاً (بيانات خارجية)، فلا يمكن
 * ترحيلها عبر إقفال السنة — المالية تُدخلها يدوياً وتحصيلها يمرّ بنفس مسار
 * متخلّدات السنوات السابقة (prior_year_debt).
 *
 * القواعد:
 * - كل دَين يُنشئ رسماً جسراً (source_student_fee_id) تحت تسجيل التلميذ في
 *   سنة سابقة، لأن توزيعات الدفع لا يمكن أن تشير إلا إلى student_fee، ولأن
 *   الدفتر يصنّف القبض كدَين سنة سابقة من اختلاف سنة التسجيل عن سنة الدفعة.
 * - المتبقّي يُشتقّ دائماً من التوزيعات الفعلية (لا يُخزَّن رصيد مخبّأ).
 * - لا حذف نهائي: الإلغاء موثّق بسبب ومانح، ويُمنع بعد تحصيل أي جزء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_student_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            // السنة الدراسية التي نُقل إليها الدَّين (السنة الجديدة).
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            // الرسم الجسر تحت تسجيل سنة سابقة — إليه تُشير توزيعات الدفع.
            $table->foreignId('source_student_fee_id')->nullable()->constrained('student_fees')->nullOnDelete();
            // تسمية السنة الأصلية للدَّين (مثال: 2025/2026) — بيانات خارجية قد لا
            // تطابق سنة دراسية مسجّلة في النظام.
            $table->string('original_year_label', 20);
            $table->string('debt_type', 20)->default('tuition'); // tuition | registration | club | other
            $table->string('description', 255);
            $table->decimal('original_amount', 12, 3);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending'); // pending | partial | paid
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'academic_year_id'], 'manual_debt_student_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_student_debts');
    }
};
