import { apiFetch } from './http';

export type ExemptionItem = {
  id: number;
  type: 'tuition' | 'club';
  type_label: string;
  enrollment_id: number;
  club_subscription_id?: number | null;
  club_name?: string | null;
  student?: {
    id: number;
    first_name: string;
    last_name: string;
    student_code?: string;
    full_name: string;
  } | null;
  classroom?: {
    section_id: number;
    section_name: string;
    level_name?: string | null;
    full_name: string;
  } | null;
  academic_year_id: number;
  academic_year_name?: string | null;
  discount_type: 'full_waiver' | 'humanitarian_fixed' | 'normal_monthly';
  discount_type_label: string;
  monthly_amount: number | null;
  fee_category: string;
  start_month: string;
  end_month: string;
  reason: string;
  notes: string | null;
  created_at: string | null;
  created_by: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  cancellation_reason: string | null;
  is_active: boolean;
  status_color: 'emerald' | 'amber' | 'slate';
};

export type ExemptionStats = {
  total_exemptions: number;
  tuition_full_waivers: number;
  club_full_waivers: number;
  humanitarian_discounts: number;
};

export type AllExemptionsResponse = {
  stats: ExemptionStats;
  data: ExemptionItem[];
};

export type GetExemptionsResponse = {
  enrollment_id: number;
  student_id: number;
  data: ExemptionItem[];
  monthly_exemptions: ExemptionItem[];
  club_exemptions: ExemptionItem[];
};

export type CreateMonthlyExemptionPayload = {
  discount_type: 'full_waiver' | 'humanitarian_fixed' | 'normal_monthly';
  monthly_amount?: number | null;
  start_month: string;
  end_month: string;
  reason: string;
  notes?: string | null;
};

export type CreateClubExemptionPayload = {
  discount_type: 'full_waiver' | 'humanitarian_fixed';
  monthly_amount?: number | null;
  start_month: string;
  end_month: string;
  reason: string;
  notes?: string | null;
};

export function fetchAllExemptions(
  params?: {
    academic_year_id?: number | null;
    section_id?: number | null;
    discount_type?: string | null;
    status?: 'active' | 'cancelled' | 'all';
    search?: string | null;
  },
  signal?: AbortSignal
): Promise<AllExemptionsResponse> {
  const q = new URLSearchParams();
  if (params?.academic_year_id) q.set('academic_year_id', String(params.academic_year_id));
  if (params?.section_id) q.set('section_id', String(params.section_id));
  if (params?.discount_type && params.discount_type !== 'all') q.set('discount_type', params.discount_type);
  if (params?.status && params.status !== 'all') q.set('status', params.status);
  if (params?.search && params.search.trim()) q.set('search', params.search.trim());

  const qs = q.toString();
  return apiFetch<AllExemptionsResponse>(`/exemptions${qs ? `?${qs}` : ''}`, {
    signal,
    fallbackMessage: 'تعذّر تحميل قائمة الإعفاءات العامة',
  });
}

export function fetchStudentExemptions(
  enrollmentId: number,
  signal?: AbortSignal
): Promise<GetExemptionsResponse> {
  return apiFetch<GetExemptionsResponse>(`/enrollments/${enrollmentId}/exemptions`, {
    signal,
    fallbackMessage: 'تعذّر تحميل قائمة الإعفاءات والتخفيضات',
  });
}

export function createMonthlyExemption(
  enrollmentId: number,
  payload: CreateMonthlyExemptionPayload
): Promise<{ message: string; data: ExemptionItem }> {
  return apiFetch<{ message: string; data: ExemptionItem }>(
    `/enrollments/${enrollmentId}/exemptions/monthly`,
    {
      method: 'POST',
      body: payload,
      fallbackMessage: 'تعذّر تسجيل الإعفاء الشهري',
    }
  );
}

export function createClubExemption(
  enrollmentId: number,
  subscriptionId: number,
  payload: CreateClubExemptionPayload
): Promise<{ message: string; data: ExemptionItem }> {
  return apiFetch<{ message: string; data: ExemptionItem }>(
    `/enrollments/${enrollmentId}/exemptions/club/${subscriptionId}`,
    {
      method: 'POST',
      body: payload,
      fallbackMessage: 'تعذّر تسجيل إعفاء النادي',
    }
  );
}

export function cancelMonthlyExemption(
  id: number,
  reason: string
): Promise<{ message: string; data: ExemptionItem }> {
  return apiFetch<{ message: string; data: ExemptionItem }>(`/exemptions/monthly/${id}`, {
    method: 'DELETE',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء الإعفاء الشهري',
  });
}

export function cancelClubExemption(
  id: number,
  reason: string
): Promise<{ message: string; data: ExemptionItem }> {
  return apiFetch<{ message: string; data: ExemptionItem }>(`/exemptions/club/${id}`, {
    method: 'DELETE',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء إعفاء النادي',
  });
}
