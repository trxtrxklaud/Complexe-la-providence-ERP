<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ربط المعلّم بالأقسام (pivot مرن — طبقة تطبيقات الجوال)
|--------------------------------------------------------------------------
|
| جدول جديد بالكامل: معلّم واحد يمكن أن يُدرّس عدّة أقسام، والقسم يمكن أن
| يكون له عدّة معلّمين، مع مادّة اختيارية (subject). لا يمسّ جدول sections
| ولا employees. مصدر الحقيقة لـ«أقسامي» في تطبيق المعلّم.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'employee_id', 'subject']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_teacher');
    }
};
