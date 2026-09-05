<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| الحضور والغياب (طبقة تطبيقات الجوال — جدول جديد بالكامل)
|--------------------------------------------------------------------------
|
| سجلّ حضور يومي لكل تلميذ في قسم، يسجّله المعلّم. مربوط بالتسجيل
| (enrollment) لضمان النطاق الأكاديمي الصحيح للسنة، وبالقسم للاستعلام
| السريع «حضور قسمي اليوم». لا يمسّ أي منطق مالي أو جدول قائم.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'late', 'excused'])->default('present');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'date']);
            $table->index(['section_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
