/**
 * طبقة الوصول إلى محرّك الدخل الصافي.
 *
 * كل دوال هذا الملف تقرأ من نفس الدفتر النقدي المركزي (cash_transactions)
 * الذي تكتب فيه المداخيل والمصاريف والأجور والسلف وسحوبات الخزينة.
 * لا يُجمع ولا يُطرح أي مبلغ في المتصفّح.
 */
import { apiFetch } from './http';
import type { ReportLine } from './reports';

export type NetGranularity = 'month' | 'year';

export type NetPeriodRow = {
  period: string;
  income: { lines: ReportLine[]; total: number };
  expenses: { lines: ReportLine[]; total: number };
  net_income: number;
  withdrawals: number;
  balance: number;
};

export type NetPeriodReport = {
  granularity: NetGranularity;
  date_from: string | null;
  date_to: string | null;
  rows: NetPeriodRow[];
  summary: NetPeriodRow;
};

export type NetPeriodParams = {
  granularity: NetGranularity;
  date_from?: string;
  date_to?: string;
  academic_year_id?: number;
};

export function fetchNetIncomePeriods(params: NetPeriodParams, signal?: AbortSignal) {
  return apiFetch<NetPeriodReport>('/reports/net-income/periods', {
    params,
    signal,
    fallbackMessage: 'فشل تحميل كشف الدخل الصافي',
  });
}

export type AcademicYearOption = { id: number; name: string };

/**
 * قائمة السنوات الدراسية.
 *
 * القراءة متسامحة مع شكل الردّ (مصفوفة مباشرة أو مغلّفة في data) لأن هذه النقطة
 * مشتركة مع شاشات أخرى، وتخمين شكل واحد يكسر الصفحة بالكامل عند أول اختلاف.
 */
export async function fetchAcademicYears(signal?: AbortSignal): Promise<AcademicYearOption[]> {
  const raw = await apiFetch<unknown>('/academic-years', {
    signal,
    fallbackMessage: 'فشل تحميل السنوات الدراسية',
  });

  const list: any[] = Array.isArray(raw) ? raw : ((raw as any)?.data ?? []);

  return list
    .filter((item) => item && item.id !== undefined)
    .map((item) => ({
      id: Number(item.id),
      name: String(item.name ?? item.label ?? item.title ?? item.id),
    }));
}
