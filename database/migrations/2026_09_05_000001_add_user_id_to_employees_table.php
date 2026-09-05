<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ربط المعلّم بحساب دخول (طبقة تطبيقات الجوال — إضافية بحتة)
|--------------------------------------------------------------------------
|
| عمود nullable فقط: employees.user_id يربط صفّ الموظّف بحساب users
| ليتمكّن المعلّم من الدخول عبر /api/login القائم. لا يمسّ أي منطق مالي
| أو أعمدة قائمة، ولا يغيّر سلوك أي شاشة حالية (كلّها تتجاهل العمود).
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
