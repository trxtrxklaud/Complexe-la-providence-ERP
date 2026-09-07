<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس هواتف أولياء الأمور على students — لمطابقة نطاق الجوال (أبنائي).
 *
 * تُرشَّح كل استعلامات دخول الوليّ ونطاقه بهذين العمودين
 * (login-by-phone / request-otp / MobileScopeService)، وبلا فهارس
 * تُفحص كل صفوف التلاميذ في PHP — لا يصمد أمام 1000+ مستخدم.
 *
 * ملاحظة: الهواتف مخزّنة بصيغ مختلفة (+216 / مسافات / 0)، فالفهرس
 * يسرّع الترشيح الأولي لكن التطابق النهائي يبقى عبر normalizePhone في PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->index('guardian_phone', 'students_guardian_phone_idx');
            $table->index('mother_phone', 'students_mother_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_guardian_phone_idx');
            $table->dropIndex('students_mother_phone_idx');
        });
    }
};
