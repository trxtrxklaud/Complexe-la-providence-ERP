import { API_BASE, getHeaders } from '../../api/http';
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

export type Tab = 'salaries' | 'advances' | 'staff';

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
  const res = await fetch(`${API_BASE}/academic-years`, { headers: getHeaders() });

  if (!res.ok) throw new Error('فشل جلب السنوات');

  return res.json();
}

/**
 * طلب سبب إلغاء موثّق.
 *
 * كان هذا التحقّق مكرّراً في أربعة مواضع، واختلاف أحدها عن بقيّتها
 * يعني ثغرة في مسار التدقيق.
 *
 * @returns السبب بعد التشذيب، أو null إن تراجع المستخدم.
 * @throws إن كان السبب أقصر من ثلاثة أحرف.
 */
export function promptCancelReason(label: string): string | null {
  const reason = window.prompt(label);

  if (reason === null) return null;

  const trimmed = reason.trim();

  if (trimmed.length < 3) {
    throw new Error('سبب الإلغاء مطلوب (ثلاثة أحرف على الأقلّ)');
  }

  return trimmed;
}
