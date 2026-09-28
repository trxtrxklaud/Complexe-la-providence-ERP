import { apiFetch } from './http';

/** قسم في قائمة الاختيار: تُعاد كل الأقسام دائماً، وعدد التلاميذ قد يكون صفراً. */
export type PaymentStatusSectionOption = {
  id: number;
  name: string;
  level: string | null;
  label: string;
  students_count: number;
};

export type PaymentStatusOptions = {
  years: Array<{ id: number; name: string; start_date: string; end_date: string; is_active: boolean }>;
  selected_year_id: number | null;
  months: Array<{ value: string; label: string }>;
  sections: PaymentStatusSectionOption[];
};

export type PaymentStatusValue = 'all' | 'paid' | 'unpaid' | 'waived';

/**
 * سطر تلميذ في قائمة حالة السداد.
 *
 * الأب والأم حقول منفصلة لأن القائمة تُطبع وتُوزّع، فيجب أن يُحجب كل حقل
 * على حدة. حقلا guardian_name و phone محفوظان للتوافق.
 */
export type PaymentStatusRow = {
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
  gross_amount?: number;
  status: 'paid' | 'unpaid' | 'waived';
  status_label: string;
  month: string;
};

export type PaymentStatusReport = {
  school_name: string;
  title: string;
  academic_year: { id: number; name: string };
  month: { value: string; label: string };
  section: { id: number; name: string; level: string | null; label: string };
  status: PaymentStatusValue;
  generated_at: string;
  report_date: string;
  report_time: string;
  rows: PaymentStatusRow[];
  summary: {
    paid_students_count: number;
    unpaid_students_count: number;
    waived_students_count: number;
    total_students_count: number;
  };
};

export function fetchPaymentStatusOptions(academicYearId?: number | null, signal?: AbortSignal): Promise<PaymentStatusOptions> {
  return apiFetch<PaymentStatusOptions>('/reports/payment-status/options', { params: { academic_year_id: academicYearId }, signal, fallbackMessage: 'فشل تحميل خيارات قائمة حالة السداد' });
}

export function fetchPaymentStatusReport(
  params: { academic_year_id: number; month: string; section_id: number; status?: PaymentStatusValue },
  signal?: AbortSignal,
): Promise<PaymentStatusReport> {
  return apiFetch<PaymentStatusReport>('/reports/payment-status', { params, signal, fallbackMessage: 'فشل تحميل قائمة حالة السداد' });
}
