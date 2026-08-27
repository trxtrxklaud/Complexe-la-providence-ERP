<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل العمليات (Audit Logs).
 *
 * أثر تدقيق إضافي فقط — لا يمسّ الدفتر النقدي ولا المنطق المالي. يسجّل كل عملية
 * مهمّة (دخول/خروج، دفعات، تلاميذ، مصاريف، سحوبات، مستخدمون) مع لقطة اسم المنفّذ
 * وعنوان IP ووصف عربي مقروء. الكتابة دفاعية في الخدمة فلا يعطّل فشلُها أيّ عملية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // المنفّذ: مرجع اختياري للمستخدم؛ يبقى السجل حتى لو حُذف الحساب.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // لقطة اسم المنفّذ لحظة العملية (قد لا يوجد مستخدم لبعض عمليات النظام).
            $table->string('user_name')->nullable();
            // نوع العملية: login | logout | payment.create | payment.cancel | ...
            $table->string('action');
            // الكائن المرتبط (اختياري): Payment | Student | User | Expense ...
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            // وصف عربي مقروء للعملية.
            $table->string('description');
            // بيانات إضافية حرّة (مبالغ، حقول معدّلة، أسباب ...).
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->index('action');
            $table->index('user_id');
            $table->index('created_at');
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
