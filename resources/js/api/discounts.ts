/**
 * التخفيض السنوي على معاليم التسجيل — سعر مخفّض لا دَين ولا متخلّد.
 *
 * الأنواع هنا مطابقة حرفياً لما يُرجِعه DiscountController (present و
 * presentEnrollment)، فلا تخمين لأسماء الحقول.
 *
 * كل هذه المسارات محمية بصلاحية waive_fees وحدها كالتنازل: من لا يملكها
 * يستقبل 403، ولذلك تُخفى الواجهة أصلاً عند غياب الصلاحية.
 */

import { apiFetch } from './http';

export { ApiError } from './http';

/**
 * ملخّص تخفيض التسجيل: المعاليم السنوية من المخطّط، سقف 20%، التخفيض السارِي،
 * والصافي بعده. تقرؤه الواجهة لتعرض «المعاليم قبل/بعد التخفيض» دون حساب محلّي.
 */
export type DiscountEnrollmentSummary = {
  id: number;
  academic_year_id: number;
  annual_fees: number;
  discount_cap: number;
  active_discount: number;
  net_fees: number;
};

export type EnrollmentDiscount = {
  id: number;
  enrollment_id: number;
  academic_year_id: number;
  amount: number;
  percentage: number | null;
  reason: string;
  applied_date: string | null;
  created_at: string | null;
  created_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancellation_reason: string | null;
  is_cancelled: boolean;
};

export type DiscountShow = {
  enrollment: DiscountEnrollmentSummary;
  discounts: EnrollmentDiscount[];
};

export type DiscountMutation = {
  discount: EnrollmentDiscount;
  enrollment: DiscountEnrollmentSummary | null;
};

/** تخفيض سارٍ: الملغى لا يُنقص من المستحقّ ولا يُحتسب. */
export function isActiveDiscount(discount: EnrollmentDiscount): boolean {
  return discount.cancelled_at === null;
}

export function fetchEnrollmentDiscount(enrollmentId: number): Promise<DiscountShow> {
  return apiFetch<DiscountShow>('/enrollments/' + enrollmentId + '/discount', {
    fallbackMessage: 'تعذّر تحميل التخفيض',
  });
}

export function createDiscount(
  enrollmentId: number,
  amount: number,
  reason: string,
  appliedDate: string,
): Promise<DiscountMutation> {
  return apiFetch<DiscountMutation>('/enrollments/' + enrollmentId + '/discount', {
    method: 'POST',
    body: { amount, reason, applied_date: appliedDate },
    fallbackMessage: 'تعذّر تسجيل التخفيض',
  });
}

export function cancelDiscount(discountId: number, reason: string): Promise<DiscountMutation> {
  return apiFetch<DiscountMutation>('/discounts/' + discountId + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء التخفيض',
  });
}
