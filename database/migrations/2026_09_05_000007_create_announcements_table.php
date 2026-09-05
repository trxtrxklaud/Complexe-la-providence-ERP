<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| الإعلانات (طبقة تطبيقات الجوال — جدول جديد بالكامل)
|--------------------------------------------------------------------------
|
| إعلان تنشره الإدارة (scope=school لكل الأولياء) أو المعلّم
| (scope=section لقسم محدّد). الوليّ يرى إعلانات المدرسة + أقسام أبنائه.
| لا يمسّ أي منطق مالي أو جدول قائم.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('scope', ['school', 'section'])->default('school');
            $table->foreignId('section_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'published_at']);
            $table->index(['section_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
