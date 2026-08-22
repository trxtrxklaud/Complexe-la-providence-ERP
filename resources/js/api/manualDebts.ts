import { apiFetch, type QueryParams } from './http';
import type { Paginated } from './expenses';

export type { Paginated } from './expenses';

// ===== ديون التلاميذ القديمة (مدخلة يدوياً) =====

export const DEBT_TYPE_LABELS: Record<string, string> = {
  tuition: 'أقساط شهرية',
  registration: 'تسجيل سنوي',
  club: 'نوادي',
  other: 'أخرى',
};

export const LIABILITY_TYPE_LABELS: Record<string, string> = {
  debt: 'دين',
  advance: 'سلفة غير مسددة',
  // قيم قديمة تظهر على سجلات سابقة فقط — لا تُعرض كخيارات في نموذج الإدخال.
  salary: 'أجور',
  bonus: 'منح',
  other: 'أخرى',
};

/**
 * أنواع الاستحقاقات المسموحة حسب تصنيف الإطار — مطابقة لثوابت
 * EmployeeLiabilityController على الخادم:
 * عاملة (worker): دين فقط؛ معلم (بساعة/بالشهر) ومنشط نوادي: دين + سلفة غير مسددة؛
 * وأي تصنيف آخر يقع على الدَّين العام.
 */
export const LIABILITY_TYPES_BY_STAFF_TYPE: Record<string, string[]> = {
  worker: ['debt'],
  hourly_teacher: ['debt', 'advance'],
  monthly_teacher: ['debt', 'advance'],
  club_animator: ['debt', 'advance'],
};

export function liabilityTypesForStaff(staffType?: string | null): string[] {
  return LIABILITY_TYPES_BY_STAFF_TYPE[staffType ?? ''] ?? ['debt'];
}

export const DEBT_STATUS_LABELS: Record<string, string> = {
  pending: 'قائم',
  partial: 'مُحصّل جزئياً',
  paid: 'مُحصّل بالكامل',
  cancelled: 'ملغى',
};

export type ManualDebt = {
  id: number;
  student_id: number;
  academic_year_id: number;
  source_student_fee_id?: number | null;
  original_year_label: string;
  debt_type: string;
  description: string;
  original_amount: number | string;
  notes?: string | null;
  status: string;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  created_at?: string;
  student?: { id: number; first_name: string; last_name: string; student_code?: string } | null;
  academic_year?: { id: number; name: string } | null;
  collected_amount?: number;
  outstanding_amount?: number;
  created_by?: unknown;
  cancelled_by?: unknown;
};

export type ManualDebtInput = {
  student_id: number;
  academic_year_id?: number | null;
  original_year_label: string;
  debt_type: string;
  description: string;
  original_amount: number;
  notes?: string | null;
};

export type ManualDebtFilters = {
  student_id?: number | null;
  academic_year_id?: number | null;
  debt_type?: string | null;
  status?: string | null;
  exclude_cancelled?: boolean;
  per_page?: number;
  page?: number;
};

export function fetchManualDebts(filters: ManualDebtFilters = {}): Promise<Paginated<ManualDebt>> {
  return apiFetch<Paginated<ManualDebt>>('/manual-debts', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل الديون القديمة',
  });
}

export function fetchManualDebt(id: number): Promise<ManualDebt> {
  return apiFetch<ManualDebt>('/manual-debts/' + id, {
    fallbackMessage: 'تعذّر تحميل الدَّين',
  });
}

export function createManualDebt(payload: ManualDebtInput): Promise<ManualDebt> {
  return apiFetch<ManualDebt>('/manual-debts', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر إدخال الدَّين',
  });
}

export function cancelManualDebt(id: number, reason: string): Promise<ManualDebt> {
  return apiFetch<ManualDebt>('/manual-debts/' + id + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء الدَّين',
  });
}

// ===== مستحقات الإطارات القديمة =====

export type EmployeeLiability = {
  id: number;
  employee_id: number;
  academic_year_id: number;
  original_year_label: string;
  liability_type: string;
  description: string;
  original_amount: number | string;
  notes?: string | null;
  status: string;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  created_at?: string;
  employee?: { id: number; first_name: string; last_name: string; job_title?: string | null } | null;
  academic_year?: { id: number; name: string } | null;
  paid_amount?: number;
  outstanding_amount?: number;
  created_by?: unknown;
  cancelled_by?: unknown;
};

export type EmployeeLiabilityInput = {
  employee_id: number;
  academic_year_id?: number | null;
  original_year_label: string;
  liability_type: string;
  description: string;
  original_amount: number;
  notes?: string | null;
};

export type EmployeeLiabilityFilters = {
  employee_id?: number | null;
  academic_year_id?: number | null;
  liability_type?: string | null;
  status?: string | null;
  exclude_cancelled?: boolean;
  per_page?: number;
  page?: number;
};

export function fetchEmployeeLiabilities(filters: EmployeeLiabilityFilters = {}): Promise<Paginated<EmployeeLiability>> {
  return apiFetch<Paginated<EmployeeLiability>>('/employee-liabilities', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل مستحقات الإطارات',
  });
}

export function createEmployeeLiability(payload: EmployeeLiabilityInput): Promise<EmployeeLiability> {
  return apiFetch<EmployeeLiability>('/employee-liabilities', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر إدخال المستحقّ',
  });
}

export function payEmployeeLiability(
  id: number,
  payload: { amount: number; paid_at?: string; method?: string | null; reference?: string | null; notes?: string | null }
): Promise<{ salary: unknown; liability: EmployeeLiability }> {
  return apiFetch<{ salary: unknown; liability: EmployeeLiability }>('/employee-liabilities/' + id + '/pay', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر خلاص المستحقّ',
  });
}

export function cancelEmployeeLiability(id: number, reason: string): Promise<EmployeeLiability> {
  return apiFetch<EmployeeLiability>('/employee-liabilities/' + id + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء المستحقّ',
  });
}

// ===== تقارير الأرصدة الافتتاحية =====

export type ManualDebtsReport = {
  filters: Record<string, unknown>;
  items: Array<{
    id: number;
    student: ManualDebt['student'];
    academic_year: ManualDebt['academic_year'];
    original_year_label: string;
    debt_type: string;
    description: string;
    original_amount: number;
    collected_amount: number;
    outstanding_amount: number;
    status: string;
    cancelled_at: string | null;
    created_at: string | null;
  }>;
  totals: {
    count: number;
    original_amount: number;
    collected_amount: number;
    outstanding_amount: number;
  };
};

export function fetchManualDebtsReport(filters: { academic_year_id?: number | null; student_id?: number | null; debt_type?: string | null; status?: string | null; exclude_cancelled?: boolean } = {}) {
  return apiFetch<ManualDebtsReport>('/reports/manual-debts', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل كشف الديون القديمة',
  });
}

export type OpeningBalancesSummary = {
  filters: Record<string, unknown>;
  summary: {
    auto: {
      count: number;
      original_amount: number;
      outstanding_amount: number;
      by_type: Record<string, { count: number; original_amount: number; outstanding_amount: number }>;
    };
    manual: {
      count: number;
      original_amount: number;
      outstanding_amount: number;
      by_type: Record<string, { count: number; original_amount: number; outstanding_amount: number }>;
    };
    grand_total: {
      count: number;
      original_amount: number;
      outstanding_amount: number;
    };
  };
};

export function fetchOpeningBalancesSummary(academic_year_id?: number | null): Promise<OpeningBalancesSummary> {
  return apiFetch<OpeningBalancesSummary>('/reports/opening-balances-summary', {
    params: academic_year_id ? { academic_year_id } : {},
    fallbackMessage: 'تعذّر تحميل ملخّص الأرصدة الافتتاحية',
  });
}

export type EmployeeLiabilitiesReport = {
  filters: Record<string, unknown>;
  items: Array<{
    id: number;
    employee: EmployeeLiability['employee'];
    academic_year: EmployeeLiability['academic_year'];
    original_year_label: string;
    liability_type: string;
    description: string;
    original_amount: number;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
    cancelled_at: string | null;
    created_at: string | null;
  }>;
  totals: {
    count: number;
    original_amount: number;
    paid_amount: number;
    outstanding_amount: number;
  };
};

export function fetchEmployeeLiabilitiesReport(filters: { academic_year_id?: number | null; employee_id?: number | null; liability_type?: string | null; status?: string | null; exclude_cancelled?: boolean } = {}) {
  return apiFetch<EmployeeLiabilitiesReport>('/reports/employee-liabilities', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل كشف مستحقات الإطارات',
  });
}