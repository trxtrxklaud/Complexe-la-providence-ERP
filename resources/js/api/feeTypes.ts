import { apiFetch, invalidateCache } from './http';

/**
 * بند المدخول في الدفتر النقدي المركزي.
 *
 * القيم والتسميات منقولة حرفياً عن CashTransaction::CATEGORY_LABELS في الـ backend،
 * ومحصورة في بنود المداخيل الستة لأن رسم التلميذ لا ينتج عنه إلا دخل.
 * أي تغيير هنا يجب أن يوازيه تغيير في FeeTypeController::rules().
 */
export const LEDGER_CATEGORY_OPTIONS = [
    { value: 'registration_fee',  label: 'معاليم التسجيل' },
    { value: 'monthly_fee',       label: 'معاليم الأشهر' },
    { value: 'installment',       label: 'خلاص أقساط' },
    { value: 'product_sale',      label: 'بيع المنتجات' },
    { value: 'advance_repayment', label: 'خلاص سلفة' },
    { value: 'other_income',      label: 'مداخيل أخرى' },
] as const;

export type LedgerCategory = typeof LEDGER_CATEGORY_OPTIONS[number]['value'];

export function ledgerCategoryLabel(value?: string | null): string {
    if (!value) return '—';
    return LEDGER_CATEGORY_OPTIONS.find(o => o.value === value)?.label ?? value;
}

export interface FeeType {
    id: number;
    name_ar: string;
    name_fr: string | null;
    price: string;
    /** null يعني: يُستدلّ البند من اسم النوع عبر FeeType::resolveLedgerCategory(). */
    ledger_category: string | null;
    is_active: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface FeeTypePayload {
    name_ar: string;
    name_fr?: string | null;
    price: number;
    ledger_category?: string | null;
    is_active?: boolean;
}

export async function getFeeTypes(): Promise<FeeType[]> {
    return apiFetch<FeeType[]>('/fee-types', { fallbackMessage: 'فشل جلب أنواع المعاليم' });
}

export async function createFeeType(data: FeeTypePayload): Promise<FeeType> {
    const result = await apiFetch<FeeType>('/fee-types', {
        method: 'POST',
        body: data,
        fallbackMessage: 'فشل إنشاء نوع المعلوم',
    });
    invalidateCache('/fee-types');
    return result;
}

export async function updateFeeType(id: number, data: FeeTypePayload): Promise<FeeType> {
    const result = await apiFetch<FeeType>(`/fee-types/${id}`, {
        method: 'PUT',
        body: data,
        fallbackMessage: 'فشل تحديث نوع المعلوم',
    });
    invalidateCache('/fee-types');
    return result;
}

export async function deleteFeeType(id: number): Promise<void> {
    await apiFetch<void>(`/fee-types/${id}`, {
        method: 'DELETE',
        fallbackMessage: 'فشل حذف نوع المعلوم',
    });
    invalidateCache('/fee-types');
}
