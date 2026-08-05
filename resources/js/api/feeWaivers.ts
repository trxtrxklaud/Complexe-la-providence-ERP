/**
 * التنازل عن متبقّي رسم التلميذ.
 *
 * الأنواع هنا مطابقة حرفياً لما يُرجِعه FeeWaiverController
 * (present و presentFee)، فلا تخمين لأسماء الحقول.
 *
 * كل هذه المسارات محمية بصلاحية waive_fees وحدها: من لا يملكها
 * يستقبل 403، ولذلك تُخفى الواجهة أصلاً عند غياب الصلاحية.
 */

import { apiFetch } from './http';

export { ApiError } from './http';

/** حالة الرسم بعد احتساب المقبوض والمتنازَل عنه. */
export type WaivedFee = {
  id: number;
  description: string | null;
  amount_due: number;
  allocated: number;
  waived: number;
  outstanding: number;
  status: string;
};

export type FeeWaiver = {
  id: number;
  student_fee_id: number;
  amount: number;
  reason: string;
  created_at: string | null;
  created_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancellation_reason: string | null;
};

export type FeeWaiverList = {
  fee: WaivedFee;
  waivers: FeeWaiver[];
};

export type FeeWaiverMutation = {
  waiver: FeeWaiver;
  fee: WaivedFee | null;
};

/** تنازل سارٍ: الملغى يعود دَيناً ولا يُحتسب. */
export function isActiveWaiver(waiver: FeeWaiver): boolean {
  return waiver.cancelled_at === null;
}

export function fetchFeeWaivers(studentFeeId: number): Promise<FeeWaiverList> {
  return apiFetch<FeeWaiverList>('/student-fees/' + studentFeeId + '/waivers', {
    fallbackMessage: 'تعذّر تحميل التنازلات',
  });
}

export function waiveFee(
  studentFeeId: number,
  amount: number,
  reason: string,
): Promise<FeeWaiverMutation> {
  return apiFetch<FeeWaiverMutation>('/student-fees/' + studentFeeId + '/waive', {
    method: 'POST',
    body: { amount, reason },
    fallbackMessage: 'تعذّر تسجيل التنازل',
  });
}

export function cancelFeeWaiver(waiverId: number, reason: string): Promise<FeeWaiverMutation> {
  return apiFetch<FeeWaiverMutation>('/fee-waivers/' + waiverId + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء التنازل',
  });
}
