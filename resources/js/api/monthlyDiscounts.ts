/**
 * التخفيضات الشهرية المتكررة — التخفيض الكلي والتخفيض الإنساني (> 20 د).
 * تسري من سبتمبر إلى نهاية السنة الدراسية.
 */

import { apiFetch } from './http';

export type MonthlyDiscountItem = {
  id: number;
  enrollment_id?: number;
  club_subscription_id?: number;
  academic_year_id: number;
  discount_type: 'full_waiver' | 'humanitarian_fixed' | 'normal_monthly';
  monthly_amount: number | null;
  fee_category?: string;
  start_month: string;
  end_month: string;
  reason: string;
  notes: string | null;
  created_at: string | null;
  created_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancellation_reason: string | null;
  is_cancelled: boolean;
};

export type FetchTuitionDiscountsResponse = {
  enrollment_id: number;
  discounts: MonthlyDiscountItem[];
};

export type FetchClubDiscountsResponse = {
  club_subscription_id: number;
  discounts: MonthlyDiscountItem[];
};

export function fetchTuitionMonthlyDiscounts(enrollmentId: number, signal?: AbortSignal): Promise<FetchTuitionDiscountsResponse> {
  return apiFetch<FetchTuitionDiscountsResponse>(`/enrollments/${enrollmentId}/monthly-discounts`, {
    signal,
    fallbackMessage: 'تعذّر تحميل التخفيضات الشهرية',
  });
}

export function createTuitionMonthlyDiscount(
  enrollmentId: number,
  data: {
    discount_type: 'full_waiver' | 'humanitarian_fixed' | 'normal_monthly';
    monthly_amount?: number | null;
    reason: string;
    notes?: string;
  }
): Promise<{ message: string; discount: MonthlyDiscountItem }> {

  return apiFetch<{ message: string; discount: MonthlyDiscountItem }>(`/enrollments/${enrollmentId}/monthly-discounts`, {
    method: 'POST',
    body: data,
    fallbackMessage: 'تعذّر تسجيل التخفيض الشهري',
  });
}

export function cancelTuitionMonthlyDiscount(
  discountId: number,
  reason: string
): Promise<{ message: string; discount: MonthlyDiscountItem }> {
  return apiFetch<{ message: string; discount: MonthlyDiscountItem }>(`/monthly-discounts/${discountId}/cancel`, {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء التخفيض',
  });
}

export function fetchClubMonthlyDiscounts(subscriptionId: number, signal?: AbortSignal): Promise<FetchClubDiscountsResponse> {
  return apiFetch<FetchClubDiscountsResponse>(`/club-subscriptions/${subscriptionId}/monthly-discounts`, {
    signal,
    fallbackMessage: 'تعذّر تحميل تخفيضات النادي',
  });
}

export function createClubMonthlyDiscount(
  subscriptionId: number,
  data: {
    discount_type: 'full_waiver' | 'humanitarian_fixed';
    monthly_amount?: number | null;
    reason: string;
    notes?: string;
  }
): Promise<{ message: string; discount: MonthlyDiscountItem }> {
  return apiFetch<{ message: string; discount: MonthlyDiscountItem }>(`/club-subscriptions/${subscriptionId}/monthly-discounts`, {
    method: 'POST',
    body: data,
    fallbackMessage: 'تعذّر تسجيل تخفيض النادي',
  });
}

export function cancelClubMonthlyDiscount(
  discountId: number,
  reason: string
): Promise<{ message: string; discount: MonthlyDiscountItem }> {
  return apiFetch<{ message: string; discount: MonthlyDiscountItem }>(`/club-monthly-discounts/${discountId}/cancel`, {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء تخفيض النادي',
  });
}
