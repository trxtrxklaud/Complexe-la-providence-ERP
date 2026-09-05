<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| رموز أجهزة الإشعارات (Expo Push — طبقة تطبيقات الجوال)
|--------------------------------------------------------------------------
|
| جدول جديد بالكامل. يربط كل رمز جهاز (Expo push token) بمستخدم ومنصّة،
| لإرسال إشعارات الدفع لاحقاً (إعلان/غياب/نتيجة/دفعة). لا يمسّ أي جدول قائم.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 20)->nullable(); // android | ios
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
