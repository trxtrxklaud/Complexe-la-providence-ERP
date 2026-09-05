<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| رموز التحقّق لدخول الوليّ (OTP — طبقة تطبيقات الجوال)
|--------------------------------------------------------------------------
|
| جدول جديد بالكامل. يخزّن الرمز مُجزّأً (hash) لا نصّاً صريحاً، مع الهاتف
| المطبَّع (آخر 8 أرقام كما في FamilyService::normalizePhone)، ومهلة صلاحية
| وعدّاد محاولات لمنع التخمين. لا يمسّ أي جدول قائم.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
