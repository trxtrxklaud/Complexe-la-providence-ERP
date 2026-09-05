<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| الأعداد والنتائج (طبقة تطبيقات الجوال — جدول جديد بالكامل)
|--------------------------------------------------------------------------
|
| نتيجة/عدد لكل تلميذ في مادّة ضمن فترة (ثلاثي). مربوط بالتسجيل
| (enrollment). النتيجة لا تظهر للوليّ إلا بعد published_at (النشر).
| لا يمسّ أي منطق مالي أو جدول قائم.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('term', 20)->nullable(); // t1 | t2 | t3
            $table->decimal('score', 6, 2);
            $table->decimal('max_score', 6, 2)->default(20);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'subject', 'term']);
            $table->index(['enrollment_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_results');
    }
};
