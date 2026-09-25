import { apiFetch } from './http';
import type { ReceiptData } from '../pages/Payments/ReceiptModal';

export interface PreschoolShortCycleMonthInfo {
  month: string;
  due_date: string;
  is_paid: boolean;
  amount: number;
}

export interface PreschoolShortCyclePreview {
  is_preschool: boolean;
  level_code?: string;
  level_name?: string;
  student_id?: number;
  student_name?: string;
  student_code?: string;
  academic_year_id?: number;
  fee_plan_id?: number;
  full_rate?: number;
  half_rate?: number;
  september?: PreschoolShortCycleMonthInfo;
  june?: PreschoolShortCycleMonthInfo;
  can_collect_full?: boolean;
  can_collect_september?: boolean;
  can_collect_june?: boolean;
  message?: string;
  is_active_year?: boolean;
  fee_plan_missing?: boolean;
}

export interface CollectPreschoolShortCyclePayload {
  enrollment_id: number;
  payment_date: string;
  method: 'cash' | 'bank_transfer' | 'check' | 'card';
  reference?: string;
  notes?: string;
  cycle_mode: 'half_rate' | 'full_rate';
  half_rate_month?: string;
  idempotency_key?: string;
}

export async function fetchPreschoolShortCyclePreview(enrollmentId: number): Promise<PreschoolShortCyclePreview> {
  return apiFetch<PreschoolShortCyclePreview>(`/collections/preschool-short-cycle/preview/${enrollmentId}`, {
    fallbackMessage: 'تعذر جلب معاينة الدورة المبسطة لأقسام ما قبل الابتدائي',
    forceRefresh: true,
  });
}

export async function collectPreschoolShortCycle(payload: CollectPreschoolShortCyclePayload): Promise<ReceiptData> {
  return apiFetch<ReceiptData>('/collections/preschool-short-cycle', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'فشل استخلاص الدورة المبسطة لأقسام ما قبل الابتدائي',
  });
}
