import { apiFetch } from './http';

/**
 * طبقة قراءة التقارير المالية.
 *
 * كل الأنواع هنا مطابقة حرفياً لمخرجات FinancialReportController. لا تُحتسب أي
 * أرقام في الواجهة: المجاميع والمقارنات كلها تأتي من الخادم الذي يقرأ من
 * cash_transactions حصراً. أي حساب في المتصفح كان سيفتح باب اختلاف الأرقام
 * بين شاشة وأخرى.
 */

export type Granularity = 'day' | 'month' | 'year';

export type ReportLine = {
  category: string;
  label: string;
  total: number;
};

export type PeriodSummary = {
  date_from: string | null;
  date_to: string | null;
  income: { lines: ReportLine[]; total: number };
  expenses: { lines: ReportLine[]; total: number };
  net_income: number;
  withdrawals: number;
  balance: number;
};

export type LedgerDetailLine = {
  id: number;
  transaction_date: string;
  direction: 'in' | 'out';
  category: string;
  label: string;
  amount: number;
  description: string | null;
};

export type NetIncomeReport = {
  date: string;
  day: PeriodSummary;
  cumulative: PeriodSummary;
  details?: LedgerDetailLine[];
};

export type PeriodRow = {
  period: string;
  by_category: ReportLine[];
  total: number;
};

export type PeriodReportData = {
  granularity: Granularity;
  date_from: string | null;
  date_to: string | null;
  rows: PeriodRow[];
  summary: {
    periods_count: number;
    by_category: ReportLine[];
    total: number;
  };
};

export type StudentRevenueRow = {
  student_id: number;
  student_code: string | null;
  name: string;
  level: string | null;
  section: string | null;
  payments_count: number;
  total: number;
};

export type StudentRevenueReport = {
  rows: StudentRevenueRow[];
  summary: { students_count: number; total: number };
};

export type ClassroomRevenueRow = {
  section_id: number | null;
  section: string | null;
  level_id: number | null;
  level: string | null;
  students_count: number;
  payments_count: number;
  total: number;
};

export type ClassroomRevenueReport = {
  rows: ClassroomRevenueRow[];
  summary: { sections_count: number; total: number };
};

export type YearRevenueRow = {
  academic_year_id: number | null;
  academic_year: string;
  income: number;
  expenses: number;
  net_income: number;
  withdrawals: number;
  balance: number;
};

export type YearRevenueReport = {
  rows: YearRevenueRow[];
  summary: { income: number; expenses: number; net_income: number; balance: number };
};

/** قسم في قائمة الاختيار: تُعاد كل الأقسام دائماً، وعدد التلاميذ قد يكون صفراً. */
export type UnpaidMonthlySectionOption = {
  id: number;
  name: string;
  level: string | null;
  label: string;
  students_count: number;
};

export type UnpaidMonthlyOptions = {
  years: Array<{ id: number; name: string; start_date: string; end_date: string; is_active: boolean }>;
  selected_year_id: number | null;
  months: Array<{ value: string; label: string }>;
  sections: UnpaidMonthlySectionOption[];
};

/**
 * سطر تلميذ غير مسدّد.
 *
 * الأب والأم حقول منفصلة لأن القائمة تُطبع وتُوزّع، فيجب أن يُحجب كل حقل
 * على حدة. حقلا guardian_name و phone محفوظان للتوافق مع النسخة السابقة.
 */
export type UnpaidMonthlyRow = {
  enrollment_id: number;
  student_id: number;
  student_code: string | null;
  student_name: string;
  guardian_name: string;
  phone: string | null;
  father_name: string | null;
  father_phone: string | null;
  mother_name: string | null;
  mother_phone: string | null;
  enrollment_date: string | null;
};

export type UnpaidMonthlyReport = {
  school_name: string;
  title: string;
  academic_year: { id: number; name: string };
  month: { value: string; label: string };
  section: { id: number; name: string; level: string | null; label: string };
  generated_at: string;
  report_date: string;
  report_time: string;
  rows: UnpaidMonthlyRow[];
  summary: { unpaid_students_count: number };
};

export type PeriodParams = {
  granularity?: Granularity;
  date_from?: string;
  date_to?: string;
  academic_year_id?: number | null;
};

export type DimensionParams = {
  date_from?: string;
  date_to?: string;
  academic_year_id?: number | null;
  section_id?: number | null;
  search?: string;
};

export function fetchNetIncome(
  params: { date?: string; details?: boolean; academic_year_id?: number | null },
  signal?: AbortSignal,
): Promise<NetIncomeReport> {
  return apiFetch<NetIncomeReport>('/reports/net-income', {
    params,
    signal,
    fallbackMessage: 'فشل تحميل تقرير الدخل الصافي',
  });
}

export function fetchIncomeByDate(params: PeriodParams, signal?: AbortSignal): Promise<PeriodReportData> {
  return apiFetch<PeriodReportData>('/reports/income-by-date', {
    params,
    signal,
    fallbackMessage: 'فشل تحميل تقرير المداخيل',
  });
}

export function fetchExpenseReport(params: PeriodParams, signal?: AbortSignal): Promise<PeriodReportData> {
  return apiFetch<PeriodReportData>('/reports/expenses', {
    params,
    signal,
    fallbackMessage: 'فشل تحميل تقرير المصاريف',
  });
}

export function fetchStudentRevenue(params: DimensionParams, signal?: AbortSignal): Promise<StudentRevenueReport> {
  return apiFetch<StudentRevenueReport>('/reports/revenue/students', {
    params,
    signal,
    fallbackMessage: 'فشل تحميل مداخيل التلاميذ',
  });
}

export function fetchClassroomRevenue(params: DimensionParams, signal?: AbortSignal): Promise<ClassroomRevenueReport> {
  return apiFetch<ClassroomRevenueReport>('/reports/revenue/classrooms', {
    params,
    signal,
    fallbackMessage: 'فشل تحميل مداخيل الأقسام',
  });
}

export function fetchYearRevenue(signal?: AbortSignal): Promise<YearRevenueReport> {
  return apiFetch<YearRevenueReport>('/reports/revenue/years', {
    signal,
    fallbackMessage: 'فشل تحميل مداخيل السنوات',
  });
}

export function fetchUnpaidMonthlyOptions(academicYearId?: number | null, signal?: AbortSignal): Promise<UnpaidMonthlyOptions> {
  return apiFetch<UnpaidMonthlyOptions>('/reports/unpaid-monthly/options', { params: { academic_year_id: academicYearId }, signal, fallbackMessage: 'فشل تحميل خيارات تقرير المتخلفين الشهري' });
}

export function fetchUnpaidMonthlyReport(params: { academic_year_id: number; month: string; section_id: number }, signal?: AbortSignal): Promise<UnpaidMonthlyReport> {
  return apiFetch<UnpaidMonthlyReport>('/reports/unpaid-monthly', { params, signal, fallbackMessage: 'فشل تحميل تقرير المتخلفين الشهري' });
}
