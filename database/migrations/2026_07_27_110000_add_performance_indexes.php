<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس الأداء للأعمدة التي تُرشّح عليها كل الاستعلامات المالية وقوائم الأقسام.
 *
 * ملاحظة مهمة: MySQL يُنشئ فهرساً تلقائياً لأعمدة المفاتيح الأجنبية،
 * أما SQLite فلا يفعل. ولأن الجهاز الحالي يعمل على SQLite والهدف محتملاً MySQL،
 * تُطبّق كل فهرسة بشكل دفاعي: لو وجد ما يكافئها مسبقاً تُتجاوز بدل أن تُسقِط الترقية.
 * مهاجرة تفشل في بيئة واحدة وتنجح في أخرى أسوأ من عدم وجودها.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{table: string, columns: array<int, string>, name: string}>
     */
    private array $indexes = [
        // الدفعات: الترتيب والترشيح بالتاريخ في كل الشاشات، والاستثناء بالإلغاء.
        ['table' => 'payments', 'columns' => ['payment_date'], 'name' => 'payments_payment_date_idx'],
        ['table' => 'payments', 'columns' => ['student_id', 'payment_date'], 'name' => 'payments_student_date_idx'],
        ['table' => 'payments', 'columns' => ['enrollment_id'], 'name' => 'payments_enrollment_idx'],
        ['table' => 'payments', 'columns' => ['cancelled_at'], 'name' => 'payments_cancelled_at_idx'],

        // التسجيلات: الربط بالقسم وحده لا يستفيد من الفهرس المركّب الموجود.
        ['table' => 'enrollments', 'columns' => ['section_id'], 'name' => 'enrollments_section_idx'],

        // الدفتر المركزي: كل التقارير تبدأ بـ whereNull('cancelled_at').
        ['table' => 'cash_transactions', 'columns' => ['cancelled_at'], 'name' => 'cash_tx_cancelled_at_idx'],
        ['table' => 'cash_transactions', 'columns' => ['academic_year_id', 'transaction_date'], 'name' => 'cash_tx_year_date_idx'],

        // رسوم التلاميذ: احتساب الرصيد يرشّح بالتسجيل والحالة معاً.
        ['table' => 'student_fees', 'columns' => ['enrollment_id', 'status'], 'name' => 'student_fees_enrollment_status_idx'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $index) {
            if (! Schema::hasTable($index['table'])) {
                continue;
            }

            foreach ($index['columns'] as $column) {
                if (! Schema::hasColumn($index['table'], $column)) {
                    continue 2;
                }
            }

            try {
                Schema::table($index['table'], function (Blueprint $table) use ($index) {
                    $table->index($index['columns'], $index['name']);
                });
            } catch (QueryException $e) {
                // فهرس مكافئ موجود مسبقاً — لا شيء يُفعل.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $index) {
            if (! Schema::hasTable($index['table'])) {
                continue;
            }

            try {
                Schema::table($index['table'], function (Blueprint $table) use ($index) {
                    $table->dropIndex($index['name']);
                });
            } catch (QueryException $e) {
                // الفهرس غير موجود — لا شيء يُفعل.
            }
        }
    }
};
