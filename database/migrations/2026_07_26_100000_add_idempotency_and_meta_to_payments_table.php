<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // مفتاح منع تكرار الطلب (idempotency): يمنع تسجيل نفس الدفعة مرتين
            // عند إعادة الإرسال أو النقر المزدوج. القيمة NULL مسموحة ومتعددة.
            $table->string('idempotency_key', 64)->nullable()->after('reference');

            // لقطة إيصال ثابتة تُخزَّن وقت الاستخلاص وتُعاد كما هي عند تكرار الطلب.
            $table->json('meta')->nullable()->after('notes');
        });

        // فهرس فريد منفصل: SQLite لا يقبل إضافة عمود UNIQUE عبر ALTER TABLE،
        // لكنه يقبل إنشاء فهرس فريد بعد وجود العمود.
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'meta']);
        });
    }
};
