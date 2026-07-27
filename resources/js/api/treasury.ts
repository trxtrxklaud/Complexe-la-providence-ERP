import { apiFetch, type QueryParams } from './http';
import type { NameRef, Paginated } from './expenses';

export type { Paginated } from './expenses';

/** بنود الدفتر النقدي المركزي — مطابقة لثوابت CashTransaction في الخادم. */
export const CASH_CATEGORY_LABELS: Record<string, string> = {
  registration_fee: 'معاليم التسجيل',
  monthly_fee: 'معاليم الأشهر',
  installment: 'خلاص أقساط',
  product_sale: 'بيع المنتجات',
  advance_repayment: 'خلاص سلفة',
  other_income: 'مداخيل أخرى',
  salary: 'الأجور',
  employee_advance: 'سلفة',
  expense: 'المصاريف',
  withdrawal: 'سحب من الخزينة',
};

export type CashTransaction = {
  id: number;
  transaction_date: string;
  direction: 'in' | 'out';
  category: string;
  amount: number | string;
  description?: string | null;
  /** تسمية عربية يرفقها الخادم مع كل سطر. */
  label?: string;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  academic_year?: NameRef | null;
  created_by?: unknown;
  cancelled_by?: unknown;
};

export type CategoryBreakdown = {
  category: string;
  label: string;
  direction: 'in' | 'out';
  total: number;
};

export type TreasurySummary = {
  date_from: string | null;
  date_to: string | null;
  income: number;
  expenses: number;
  /** الدخل الصافي قبل خصم السحوبات. */
  net_income: number;
  withdrawals: number;
  /** الرصيد النهائي = الدخل الصافي − السحوبات. */
  balance: number;
  by_category: CategoryBreakdown[];
};

export type TreasuryHistory = {
  transactions: Paginated<CashTransaction>;
  summary: TreasurySummary;
};

export type HistoryFilters = {
  date_from?: string | null;
  date_to?: string | null;
  direction?: 'in' | 'out' | null;
  category?: string | null;
  include_cancelled?: boolean;
  per_page?: number;
  page?: number;
};

export type TreasuryWithdrawal = {
  id: number;
  amount: number | string;
  withdrawn_at: string;
  type?: string | null;
  note?: string | null;
  academic_year_id?: number | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  academic_year?: NameRef | null;
  created_by?: unknown;
  cancelled_by?: unknown;
};

export type WithdrawalInput = {
  amount: number;
  withdrawn_at: string;
  type?: string | null;
  note?: string | null;
  academic_year_id?: number | null;
};

export type WithdrawalFilters = {
  date_from?: string | null;
  date_to?: string | null;
  exclude_cancelled?: boolean;
  per_page?: number;
  page?: number;
};

// ===== سجلّ الخزينة والرصيد =====

export function fetchTreasuryHistory(filters: HistoryFilters = {}): Promise<TreasuryHistory> {
  return apiFetch<TreasuryHistory>('/treasury/history', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل سجلّ الخزينة',
  });
}

export function fetchTreasuryBalance(range: { date_from?: string | null; date_to?: string | null } = {}): Promise<TreasurySummary> {
  return apiFetch<TreasurySummary>('/treasury/balance', {
    params: range as QueryParams,
    fallbackMessage: 'تعذّر تحميل رصيد الخزينة',
  });
}

// ===== السحوبات =====

export function fetchWithdrawals(filters: WithdrawalFilters = {}): Promise<Paginated<TreasuryWithdrawal>> {
  return apiFetch<Paginated<TreasuryWithdrawal>>('/treasury/withdrawals', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل السحوبات',
  });
}

export function createWithdrawal(payload: WithdrawalInput): Promise<TreasuryWithdrawal> {
  return apiFetch<TreasuryWithdrawal>('/treasury/withdrawals', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تسجيل السحب',
  });
}

export function updateWithdrawal(id: number, payload: Partial<WithdrawalInput>): Promise<TreasuryWithdrawal> {
  return apiFetch<TreasuryWithdrawal>('/treasury/withdrawals/' + id, {
    method: 'PUT',
    body: payload,
    fallbackMessage: 'تعذّر تعديل السحب',
  });
}

export function cancelWithdrawal(id: number, reason: string): Promise<TreasuryWithdrawal> {
  return apiFetch<TreasuryWithdrawal>('/treasury/withdrawals/' + id + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء السحب',
  });
}
