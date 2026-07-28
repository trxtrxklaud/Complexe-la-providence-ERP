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
