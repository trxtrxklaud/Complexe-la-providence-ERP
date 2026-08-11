<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التنازل عن متبقّي رسم (معلوم الترسيم خاصة).
 *
 * لا نخفّض amount_due ولا نحذف الرسم: المدرسة طالبت بـ 70 وقبضت 20
 * وتنازلت عن 50، والثلاثة وقائع يجب أن تبقى مقروءة بعد سنة.
 * شطب المبلغ بصمت يجعل دفتر المدرسة يكذب بلا أثر.
 *
 * المتخلّد بعد هذا الجدول = amount_due - المخصّص - المتنازل عنه.
 * والتنازل نفسه يُلغى ولا يُحذف، كبقية وثائق المنصة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_waivers', function (Blueprint $table) {
            $table->id();

            // مراجع بلا قيود خارجية: SQLite يعيد بناء الجدول عند كل تعديل لاحق.
            $table->unsignedBigInteger('student_fee_id');
            $table->decimal('amount', 10, 2);
            $table->string('reason', 500);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();

            $table->index('student_fee_id', 'fee_waivers_student_fee_idx');
            $table->index('cancelled_at', 'fee_waivers_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_waivers');
    }
};
