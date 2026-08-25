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

// ===== استخلاص الديون القديمة =====

export type OldDebtSummaryItem = {
  id: number;
  type: 'student';
  debt_type: string;
  description: string;
  original_year_label: string;
  created_at: string | null;
  academic_year?: string | null;
  original_amount: number;
  collected_amount: number;
  outstanding_amount: number;
  status: string;
};

export type OldDebtSummary = {
  student_id: number;
  items: OldDebtSummaryItem[];
  totals: { count: number; original_amount: number; collected_amount: number; outstanding_amount: number };
};

export type OldDebtPaymentRow = {
  allocation_id: number;
  payment_id: number | null;
  payment_date: string | null;
  method: string | null;
  amount: number;
  status: 'active' | 'cancelled';
  cancelled_at: string | null;
  cancellation_reason: string | null;
};

export type OldDebtStatement = {
  debt: {
    id: number;
    type: 'student';
    debt_type: string;
    description: string;
    student_name: string;
    student_code: string | null;
    level: string | null;
    section: string | null;
    original_year_label: string;
    created_at: string | null;
    original_amount: number;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
  };
  payments: OldDebtPaymentRow[];
  totals: { paid_active: number; cancelled: number; count: number };
};

export function fetchOldDebtSummary(studentId: number): Promise<OldDebtSummary> {
  return apiFetch<OldDebtSummary>('/students/' + studentId + '/old-debt-summary', {
    fallbackMessage: 'تعذّر تحميل ملخص الدين القديم',
  });
}

export function collectOldDebt(
  debtId: number,
  payload: { amount: number; payment_date?: string; method?: string; notes?: string | null }
): Promise<BulkResult & { receipt: unknown }> {
  return apiFetch('/manual-debts/' + debtId + '/collect', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تحصيل الدين القديم',
  }) as Promise<BulkResult & { receipt: unknown }>;
}

export function fetchOldDebtPayments(debtId: number): Promise<{
  debt_id: number;
  payments: OldDebtPaymentRow[];
  totals: { paid_active: number; cancelled: number; count: number };
}> {
  return apiFetch('/manual-debts/' + debtId + '/payments', {
    fallbackMessage: 'تعذّر تحميل سجل دفعات الدين',
  });
}

export function fetchOldDebtStatement(debtId: number): Promise<OldDebtStatement> {
  return apiFetch<OldDebtStatement>('/manual-debts/' + debtId + '/statement', {
    fallbackMessage: 'تعذّر تحميل كشف استخلاص متخلد قديم',
  });
}

export function cancelOldDebtPayment(paymentId: number, reason: string): Promise<unknown> {
  return apiFetch('/payments/' + paymentId + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء الدفعة',
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

// ===== الإدخال الجماعي =====

export type BulkOptions = {
  active_year: { id: number; name: string; start_date: string } | null;
  levels: Array<{ id: number; name: string }>;
  sections: Array<{ id: number; name: string; level_id: number }>;
  employees: Array<{ id: number; first_name: string; last_name: string; job_title: string | null; staff_type: string | null }>;
  existing_liabilities: Array<{
    id: number;
    employee_id: number;
    liability_type: string;
    original_amount: number | string;
    paid_amount: number;
    outstanding_amount: number;
    notes: string | null;
    status: string;
    original_year_label: string;
    created_at: string | null;
  }>;
};

export type SectionStudentsResponse = {
  academic_year_id: number;
  students: Array<{
    id: number;
    full_name: string;
    student_code: string | null;
    existing: {
      id: number;
      debt_type: string;
      original_amount: number;
      notes: string | null;
      collected_amount: number;
    } | null;
  }>;
};

export type SectionStudentRow = SectionStudentsResponse['students'][number];

export type BulkDebtItem = {
  student_id: number;
  debt_type: string;
  amount: number;
  notes?: string | null;
};

export type BulkLiabilityItem = {
  employee_id: number;
  liability_type: string;
  amount: number;
  notes?: string | null;
};

export type BulkResult = {
  message: string;
  created: number;
  updated: number;
};

export function fetchBulkOptions(): Promise<BulkOptions> {
  return apiFetch<BulkOptions>('/manual-debts/bulk-options', {
    fallbackMessage: 'تعذّر تحميل خيارات الإدخال الجماعي',
  });
}

export function fetchSectionStudents(sectionId: number, academicYearId?: number | null): Promise<SectionStudentsResponse> {
  return apiFetch<SectionStudentsResponse>('/manual-debts/section-students', {
    params: { section_id: sectionId, academic_year_id: academicYearId ?? undefined } as QueryParams,
    fallbackMessage: 'تعذّر تحميل تلاميذ القسم',
  });
}

export function bulkCreateDebts(payload: {
  academic_year_id?: number | null;
  original_year_label: string;
  items: BulkDebtItem[];
}): Promise<BulkResult> {
  return apiFetch<BulkResult>('/manual-debts/bulk', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر حفظ الديون الجماعية',
  });
}

export function bulkCreateLiabilities(payload: {
  academic_year_id?: number | null;
  original_year_label: string;
  items: BulkLiabilityItem[];
}): Promise<BulkResult> {
  return apiFetch<BulkResult>('/employee-liabilities/bulk', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر حفظ التزامات الإطارات الجماعية',
  });
}