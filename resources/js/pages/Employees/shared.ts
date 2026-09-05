import { API_BASE, getHeaders, apiFetch } from '../../api/http';
import type { EmployeeAdvance } from '../../api/employees';

/**
 * الأساس المشترك لشاشات الإطارات.
 *
 * كانت هذه القيم محشورة في ملف واحد ضخم؛ جمعها هنا يمنع أن تنحرف
 * نسخة عن أخرى عند أول تعديل.
 */

export const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  bg: '#F4F6F1',
  danger: '#A03434',
  dangerBtn: '#DC2626',
};

export type Tab = 'salaries' | 'advances' | 'staff' | 'schedule';

export interface AcademicYearOption {
  id: number;
  name: string;
  is_active?: boolean;
}

export const TYPE_LABELS: Record<string, string> = {
  advance: 'تسبقة',
  loan: 'سلفة',
};

/** المتبقّي من تسبقة أو سلفة: المبلغ ناقص ما خُلّص منه. */
export function remainingOf(advance: EmployeeAdvance): number {
  return Number(advance.amount ?? 0) - Number(advance.settled_amount ?? 0);
}

export function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function money(value: unknown): string {
  return Number(value ?? 0).toFixed(2);
}

export function shortDate(value: unknown): string {
  return value ? String(value).slice(0, 10) : '—';
}

export function employeeName(
  person?: { first_name: string; last_name: string } | null,
  fallback?: number | string,
): string {
  return person ? `${person.first_name} ${person.last_name}` : String(fallback ?? '—');
}

export async function fetchYears(): Promise<AcademicYearOption[]> {
  const data = await apiFetch<AcademicYearOption[] | { data: AcademicYearOption[] }>('/academic-years', { fallbackMessage: 'فشل جلب السنوات' });
  return Array.isArray(data) ? data : (data && 'data' in data ? (data as { data: AcademicYearOption[] }).data : []);
}

/**
 * ما يُطلب إلغاؤه من شاشة الإطارات.
 *
 * لا شيء يُحذف هنا: كل مستند مالي يُلغى بسبب مكتوب يبقى في السجلّ.
 * وحدة النوع هذه تجعل نافذة واحدة تخدم الثلاثة دون تكرار.
 */
export type CancelTarget =
  | { kind: 'salary'; id: number }
  | { kind: 'advance'; id: number }
  | { kind: 'repayment'; id: number; advanceId: number };

export const CANCEL_TITLES: Record<CancelTarget['kind'], string> = {
  salary: 'إلغاء راتب',
  advance: 'إلغاء تسبقة أو سلفة',
  repayment: 'إلغاء ردّ قسط',
};

export const CANCEL_DESCRIPTIONS: Record<CancelTarget['kind'], string> = {
  salary: 'يُسحَب الراتب من الدفتر النقدي، وتعود التسبقات وأقساط السلف المخصومة به إلى ذمّة الإطار.',
  advance: 'يُسحَب أثرها من الخزينة، وتبقى ظاهرة في السجلّ مع سبب إلغائها.',
  repayment: 'يعود المبلغ دَيناً على الإطار، ويُسحَب من الدفتر إن كان ردّاً نقديّاً.',
};
