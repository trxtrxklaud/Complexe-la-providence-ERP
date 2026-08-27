import { apiFetch, type QueryParams } from './http';
import type { Paginated } from './expenses';

export type { Paginated } from './expenses';

// ===== ديون الإطارات القديمة (أرصدة افتتاحية تاريخية) =====

export const EMPLOYEE_DEBT_TYPE_LABELS: Record<string, string> = {
  debt: 'دَين قديم',
  other: 'أخرى',
};

export const EMPLOYEE_DEBT_STATUS_LABELS: Record<string, string> = {
  pending: 'قائم',
  partial: 'مُحصّل جزئياً',
  paid: 'مسدّد بالكامل',
  cancelled: 'ملغى',
};

export interface OldEmployeeDebt {
  id: number;
  employee_id: number;
  academic_year_id: number;
  original_year_label: string;
  debt_type: 'debt' | 'other' | string;
  description: string;
  original_amount: number | string;
  notes?: string | null;
  status: 'pending' | 'partial' | 'paid' | 'cancelled' | string;
  cancelled_at?: string | null;
  cancelled_by?: unknown;
  cancellation_reason?: string | null;
  created_at?: string;
  created_by?: unknown;
  employee?: {
    id: number;
    first_name: string;
    last_name: string;
    job_title?: string | null;
  } | null;
  academic_year?: {
    id: number;
    name: string;
  } | null;
  collected_amount?: number;
  outstanding_amount?: number;
}

export interface OldEmployeeDebtCollection {
  id: number;
  employee_opening_debt_id: number;
  amount: number | string;
  payment_date: string;
  method?: string | null;
  notes?: string | null;
  collected_by?: number | null;
  created_at?: string;
}

export interface OldEmployeeDebtInput {
  employee_id: number;
  academic_year_id?: number | null;
  original_year_label: string;
  debt_type: string;
  description: string;
  original_amount: number;
  notes?: string | null;
}

export interface OldEmployeeDebtUpdateInput {
  original_year_label?: string;
  debt_type?: string;
  description?: string;
  original_amount?: number;
  notes?: string | null;
}

export interface OldEmployeeDebtFilters {
  employee_id?: number | null;
  academic_year_id?: number | null;
  debt_type?: string | null;
  status?: string | null;
  exclude_cancelled?: boolean;
  per_page?: number;
  page?: number;
}

export interface CollectOldEmployeeDebtInput {
  amount: number;
  payment_date?: string;
  method?: 'cash' | 'bank_transfer' | 'check' | 'card' | string;
  notes?: string | null;
}

export interface CollectOldEmployeeDebtResponse {
  message: string;
  collection: OldEmployeeDebtCollection;
  transaction: unknown;
  debt: OldEmployeeDebt;
}

export function fetchOldEmployeeDebts(filters: OldEmployeeDebtFilters = {}): Promise<Paginated<OldEmployeeDebt>> {
  return apiFetch<Paginated<OldEmployeeDebt>>('/employee-opening-debts', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل ديون الإطارات القديمة',
  });
}

export function fetchOldEmployeeDebt(id: number): Promise<OldEmployeeDebt> {
  return apiFetch<OldEmployeeDebt>(`/employee-opening-debts/${id}`, {
    fallbackMessage: 'تعذّر تحميل دَين الإطار',
  });
}

export function createOldEmployeeDebt(payload: OldEmployeeDebtInput): Promise<OldEmployeeDebt> {
  return apiFetch<OldEmployeeDebt>('/employee-opening-debts', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر إدخال دَين الإطار',
  });
}

export function updateOldEmployeeDebt(id: number, payload: OldEmployeeDebtUpdateInput): Promise<OldEmployeeDebt> {
  return apiFetch<OldEmployeeDebt>(`/employee-opening-debts/${id}`, {
    method: 'PUT',
    body: payload,
    fallbackMessage: 'تعذّر تعديل دَين الإطار',
  });
}

export function cancelOldEmployeeDebt(id: number, reason: string): Promise<OldEmployeeDebt> {
  return apiFetch<OldEmployeeDebt>(`/employee-opening-debts/${id}/cancel`, {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء دَين الإطار',
  });
}

export function collectOldEmployeeDebt(
  id: number,
  payload: CollectOldEmployeeDebtInput
): Promise<CollectOldEmployeeDebtResponse> {
  return apiFetch<CollectOldEmployeeDebtResponse>(`/employee-opening-debts/${id}/collect`, {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تحصيل دَين الإطار',
  });
}