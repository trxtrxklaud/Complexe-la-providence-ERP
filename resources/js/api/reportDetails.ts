/**
 * نقطات تفصيل المداخيل: قسم واحد أو تلميذ واحد.
 *
 * نفس قاعدة reports.ts: لا تُحتسب أي أرقام في الواجهة. كل مبلغ معروض
 * هنا جاء محسوباً من الخادم انطلاقاً من cash_transactions، لأن جمعاً يجري في
 * المتصفّح لا يمكن تدقيقه لاحقاً ولا مطابقته مع الخزينة.
 */
import { apiFetch } from './http';
import type { ReportLine } from './reports';

export type DetailParams = {
  date_from?: string;
  date_to?: string;
  academic_year_id?: number;
};

export type ClassroomStudentRow = {
  student_id: number;
  student_code: string | null;
  name: string;
  payments_count: number;
  total: number;
};

export type ClassroomDetail = {
  filters: DetailParams;
  section: {
    id: number;
    name: string | null;
    level: string | null;
  };
  by_category: ReportLine[];
  students: ClassroomStudentRow[];
  summary: {
    enrolled_count: number;
    payers_count: number;
    unpaid_count: number;
    payments_count: number;
    total: number;
  };
};

export type StudentPaymentLine = {
  category: string;
  label: string;
  amount: number;
};

export type StudentPaymentRow = {
  payment_id: number;
  transaction_date: string;
  method: string | null;
  reference: string | null;
  cancelled: boolean;
  cancellation_reason: string | null;
  lines: StudentPaymentLine[];
  total: number;
};

export type StudentDetail = {
  filters: DetailParams;
  student: {
    id: number;
    student_code: string | null;
    name: string;
    level: string | null;
    section: string | null;
    academic_year: string | null;
    guardian_phone: string | null;
  };
  by_category: ReportLine[];
  payments: StudentPaymentRow[];
  summary: {
    payments_count: number;
    cancelled_count: number;
    total: number;
  };
};

export function fetchClassroomDetail(
  sectionId: number | string,
  params: DetailParams = {},
  signal?: AbortSignal,
) {
  return apiFetch<ClassroomDetail>(`/reports/revenue/classrooms/${sectionId}`, {
    params,
    signal,
    fallbackMessage: 'فشل تحميل تفصيل مداخيل القسم',
  });
}

export function fetchStudentDetail(
  studentId: number | string,
  params: DetailParams = {},
  signal?: AbortSignal,
) {
  return apiFetch<StudentDetail>(`/reports/revenue/students/${studentId}`, {
    params,
    signal,
    fallbackMessage: 'فشل تحميل تفصيل مداخيل التلميذ',
  });
}

/* ==================== كشف مداخيل القسم ==================== */

export type RosterSectionOption = {
  id: number;
  name: string;
  level: string | null;
  level_id: number | null;
  label: string;
};

export type RosterYearOption = {
  id: number;
  name: string;
  start_date: string | null;
  end_date: string | null;
  is_active: boolean | number;
};

export type RosterMonth = {
  key: string;
  label: string;
  year: number;
  elapsed?: boolean;
  current?: boolean;
};

export type ClassroomRosterOptions = {
  sections: RosterSectionOption[];
  years: RosterYearOption[];
  active_year_id: number | null;
  months: RosterMonth[];
};

export type RosterCategory = {
  category: string;
  label: string;
};

/**
 * حالة الشهر لتلميذ واحد:
 *  paid     خالص (أخضر)
 *  late     فات الشهر ولم يدفع (أحمر)
 *  due      الشهر الجاري وما زالت أيامه (أصفر)
 *  upcoming لم يأتِ دوره بعد (رمادي)
 */
export type RosterMonthStatus = 'paid' | 'late' | 'due' | 'upcoming';

export type RosterMonthCell = {
  key: string;
  label: string;
  status: RosterMonthStatus;
  amount: number;
  payment_date: string | null;
};

export type ClassroomRosterRow = {
  student_id: number;
  student_code: string | null;
  name: string;
  phone: string | null;
  enrolled: boolean;
  payments_count: number;
  by_category: Record<string, number>;
  months: RosterMonthCell[];
  paid_months: number;
  late_count: number;
  unpaid_months: string[];
  months_arrears: number;
  total: number;
  outstanding: number;
};

export type RosterMonthTotal = {
  key: string;
  label: string;
  paid_count: number;
  late_count: number;
  due_count: number;
  total: number;
};

export type RosterParams = DetailParams & {
  month_from?: string;
  month_to?: string;
};

export type ClassroomRosterReport = {
  filters: RosterParams;
  section: {
    id: number;
    name: string | null;
    level: string | null;
    label: string;
  };
  academic_year: { id: number; name: string } | null;
  categories: RosterCategory[];
  months: RosterMonth[];
  reference_monthly_fee: number;
  by_category: Array<RosterCategory & { total: number }>;
  by_month: RosterMonthTotal[];
  rows: ClassroomRosterRow[];
  summary: {
    students_count: number;
    enrolled_count: number;
    payers_count: number;
    debtors_count: number;
    total: number;
    months_arrears: number;
    outstanding_total: number;
  };
  report_date: string;
  report_time: string;
};

export function fetchClassroomRosterOptions(signal?: AbortSignal) {
  return apiFetch<ClassroomRosterOptions>('/reports/classroom-roster/options', {
    signal,
    fallbackMessage: 'تعذّر تحميل قائمة الأقسام',
  });
}

export function fetchClassroomRoster(
  sectionId: number | string,
  params: RosterParams = {},
  signal?: AbortSignal,
) {
  return apiFetch<ClassroomRosterReport>(`/reports/classroom-roster/${sectionId}`, {
    params,
    signal,
    fallbackMessage: 'فشل تحميل كشف مداخيل القسم',
  });
}
