<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'code')) {
                $table->string('code', 10)->nullable()->unique()->after('username');
            }
        });

        // توليد كود للمستخدمين الحاليين
        $users = DB::table('users')->orderBy('id')->get();
        foreach ($users as $u) {
            if (!empty($u->code)) continue;
            $a = mb_substr(trim((string)($u->first_name ?? '')), 0, 1);
            $b = mb_substr(trim((string)($u->last_name ?? '')), 0, 1);
            $base = ($a ?: 'U') . ($b ?: 'X');
            $code = mb_strtoupper($base);
            $n = 1;
            while (DB::table('users')->where('code', $code)->where('id', '!=', $u->id)->exists()) {
                $code = mb_strtoupper($base) . $n;
                $n++;
            }
            DB::table('users')->where('id', $u->id)->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'code')) {
                $table->dropColumn('code');
            }
        });
    }
};
