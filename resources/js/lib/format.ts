import { ApiError } from '../api/http';

/**
 * أدوات عرض مشتركة لصفحات المالية.
 *
 * قبل هذا الملف كانت كل صفحة تعيد تعريف errorMessage() وتنسيق المبالغ بنفسها.
 * مصدر واحد للحقيقة = سلوك موحّد في كل الموديولات.
 */

/** رسالة خطأ مفهومة من ApiError أو من أي كائن خطأ آخر. */
export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) return err.firstError;

  if (err && typeof err === 'object') {
    const anyErr = err as { firstError?: string; message?: string };
    if (anyErr.firstError) return anyErr.firstError;
    if (anyErr.message) return anyErr.message;
  }

  return 'حدث خطأ غير متوقّع';
}

/** تنسيق مبلغ بمنزلتين عشريتين، مطابقاً لدقّة decimal(12,2) في قاعدة البيانات. */
export function money(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);

  return (Number.isFinite(n) ? n : 0).toFixed(2);
}

/** تاريخ اليوم بصيغة YYYY-MM-DD (توقيت الجهاز). */
export function today(): string {
  const now = new Date();
  const offset = now.getTimezoneOffset() * 60000;

  return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

/** أول يوم من الشهر الحالي بصيغة YYYY-MM-DD. */
export function monthStart(): string {
  return today().slice(0, 8) + '01';
}

/**
 * اسم شخص من علاقة Eloquent.
 *
 * ملاحظة مهمّة: الأعمدة created_by / cancelled_by تتصادم مع العلاقات
 * createdBy / cancelledBy عند التحويل إلى JSON، فقد تصل القيمة رقماً أو كائناً.
 * هذه الدالة تتعامل مع الحالتين.
 */
export function personName(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'number') return '#' + value;
  if (typeof value === 'string') return value;

  if (typeof value === 'object') {
    const person = value as { first_name?: string | null; last_name?: string | null; name?: string | null };
    const full = [person.first_name, person.last_name].filter(Boolean).join(' ').trim();
    if (full) return full;
    if (person.name) return person.name;
  }

  return '—';
}
