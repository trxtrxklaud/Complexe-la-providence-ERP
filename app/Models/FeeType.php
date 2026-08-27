<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeType extends Model
{
    protected $fillable = [
        'name_ar',
        'name_fr',
        'price',
        'ledger_category',
        'is_active',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * يحدّد بند المداخيل في الدفتر المركزي وفق أولوية واضحة:
     *   1) العمود المُصرَّح ledger_category (بيانات يضبطها المسؤول)
     *   2) استدلال من الاسم العربي للأنواع التي أُنشئت قبل هذا العمود
     *   3) «مداخيل أخرى» — تصنيف ظاهر للعِيان بدل إخفاء المبلغ في بند خاطئ
     */
    public function resolveLedgerCategory(): string
    {
        $valid = [
            CashTransaction::CATEGORY_REGISTRATION_FEE,
            CashTransaction::CATEGORY_MONTHLY_FEE,
            CashTransaction::CATEGORY_INSTALLMENT,
            CashTransaction::CATEGORY_PRODUCT_SALE,
            CashTransaction::CATEGORY_ADVANCE_REPAYMENT,
            CashTransaction::CATEGORY_OTHER_INCOME,
        ];

        if ($this->ledger_category && in_array($this->ledger_category, $valid, true)) {
            return $this->ledger_category;
        }

        return $this->guessCategoryFromName();
    }

    private function guessCategoryFromName(): string
    {
        $name = self::normalize((string) $this->name_ar);

        if (
            str_contains($name, 'شهر') ||
            str_contains($name, 'تمدرس') ||
            str_contains($name, 'تعليم') ||
            str_contains($name, 'دراسي')
        ) {
            return CashTransaction::CATEGORY_MONTHLY_FEE;
        }

        if (str_contains($name, 'تسجيل') || str_contains($name, 'ترسيم')) {
            return CashTransaction::CATEGORY_REGISTRATION_FEE;
        }

        if (str_contains($name, 'اقساط')) {
            return CashTransaction::CATEGORY_INSTALLMENT;
        }

        if (str_contains($name, 'سلفه')) {
            return CashTransaction::CATEGORY_ADVANCE_REPAYMENT;
        }

        return CashTransaction::CATEGORY_OTHER_INCOME;
    }

    /**
     * تطبيع عربي مطابق لما تعتمده قوائم الأقسام: حذف التشكيل وتوحيد الألف والياء والتاء المربوطة.
     */
    public static function normalize(string $value): string
    {
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value) ?? $value;
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $value);

        return trim($value);
    }
}
