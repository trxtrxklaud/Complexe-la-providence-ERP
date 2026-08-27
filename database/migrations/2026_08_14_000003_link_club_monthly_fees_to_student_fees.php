<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->unsignedBigInteger('club_monthly_fee_id')->nullable()->after('fee_type_id');
            $table->decimal('direct_paid_amount', 12, 3)->default(0)->after('amount_due');
            $table->unique('club_monthly_fee_id', 'student_fees_club_monthly_fee_unique');
            $table->index('club_monthly_fee_id');
        });

        $clubFees = DB::table('club_monthly_fees')->get();

        foreach ($clubFees as $clubFee) {
            $existing = DB::table('student_fees')
                ->where('club_monthly_fee_id', $clubFee->id)
                ->first();

            if ($existing) {
                continue;
            }

            $clubName = DB::table('clubs')->where('id', $clubFee->club_id)->value('name') ?? 'النادي';
            $paid = (float) $clubFee->amount_paid;
            $due = (float) $clubFee->amount_due;

            DB::table('student_fees')->insert([
                'enrollment_id' => $clubFee->enrollment_id,
                'fee_plan_id' => null,
                'fee_type_id' => null,
                'club_monthly_fee_id' => $clubFee->id,
                'description' => 'معلوم نادي '.$clubName.' — '.$clubFee->month,
                'amount_due' => $due,
                'direct_paid_amount' => $paid,
                'due_date' => $clubFee->month.'-01',
                'status' => $paid >= $due ? 'paid' : ($paid > 0 ? 'partial' : 'pending'),
                'created_at' => $clubFee->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('student_fees', function (Blueprint $table) {
            $table->dropUnique('student_fees_club_monthly_fee_unique');
            $table->dropIndex('student_fees_club_monthly_fee_id_index');
            $table->dropColumn(['club_monthly_fee_id', 'direct_paid_amount']);
        });
    }
};
