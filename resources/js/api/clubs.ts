import { apiFetch } from './http';

export interface LevelInfo {
  id: number;
  name: string;
  code: string;
}

export interface ClubItem {
  id: number;
  name: string;
  description: string | null;
  fee_category_id: number;
  monthly_fee: number | string;
  is_active: boolean;
  levels?: LevelInfo[];
}

export interface ClubSubscriptionItem {
  id: number;
  student_id: number;
  club_id: number;
  academic_year_id: number;
  enrollment_id: number | null;
  start_date: string;
  end_date: string | null;
  status: 'active' | 'paused' | 'cancelled';
  monthly_fee_override: number | string | null;
  student?: {
    id: number;
    first_name: string;
    last_name: string;
    student_code: string;
  };
  club?: {
    id: number;
    name: string;
    monthly_fee: number | string;
  };
}

export interface ClubReportRecord {
  id: number;
  month: string;
  student_id: number;
  student_name: string;
  student_code: string;
  level_name: string;
  section_name: string;
  club_id: number;
  club_name: string;
  amount_due: number;
  amount_paid: number;
  remaining: number;
  status: 'paid' | 'unpaid' | 'partial';
  status_label: string;
  status_color: string;
  paid_at: string | null;
  method: string | null;
  notes: string | null;
}

export interface ClubReportSummary {
  enrolled_count: number;
  paid_count: number;
  unpaid_count: number;
  partial_count: number;
  total_due: number;
  total_paid: number;
  total_remaining: number;
}

export interface ClubReportData {
  month: string;
  academic_year_id: number;
  summary: ClubReportSummary;
  records: ClubReportRecord[];
}

export interface ClubArrearsDetail {
  id: number;
  month: string;
  student_id: number;
  student_name: string;
  student_code: string;
  guardian_phone?: string | null;
  level_name: string;
  section_id: number | null;
  section_name: string;
  club_id: number;
  club_name: string;
  amount_due: number;
  amount_paid: number;
  remaining: number;
  status: string;
}

export interface ClubArrearsStudent {
  student_id: number;
  student_name: string;
  student_code: string;
  guardian_phone?: string | null;
  level_name: string;
  section_id: number | null;
  section_name: string;
  clubs_count: number;
  months_count: number;
  total_remaining: number;
  details: ClubArrearsDetail[];
}

export interface ClubArrearsSection {
  section_id: number | null;
  section_name: string;
  students_count: number;
  clubs_count: number;
  fees_count: number;
  total_remaining: number;
  students: ClubArrearsStudent[];
}

export interface ClubArrearsDashboardData {
  academic_year_id: number;
  summary: {
    sections_count: number;
    students_count: number;
    clubs_count: number;
    fees_count: number;
    total_due: number;
    total_paid: number;
    total_remaining: number;
  };
  sections: ClubArrearsSection[];
  students: ClubArrearsStudent[];
}

export function fetchClubs(params?: { active_only?: boolean }): Promise<ClubItem[]> {
  return apiFetch<ClubItem[]>('/clubs', { params });
}

export function createClub(data: {
  name: string;
  description?: string;
  monthly_fee: number;
  is_active?: boolean;
  level_ids?: number[];
}): Promise<ClubItem> {
  return apiFetch<ClubItem>('/clubs', { method: 'POST', body: data });
}

export function updateClub(
  id: number,
  data: {
    name?: string;
    description?: string;
    monthly_fee?: number;
    is_active?: boolean;
    level_ids?: number[];
  }
): Promise<ClubItem> {
  return apiFetch<ClubItem>(`/clubs/${id}`, { method: 'PUT', body: data });
}

export function deleteClub(id: number): Promise<{ message?: string }> {
  return apiFetch<{ message?: string }>(`/clubs/${id}`, { method: 'DELETE' });
}

export function fetchClubSubscriptions(params?: {
  academic_year_id?: number;
  club_id?: number;
  student_id?: number;
  status?: string;
}): Promise<{ data: ClubSubscriptionItem[] }> {
  return apiFetch<{ data: ClubSubscriptionItem[] }>('/club-subscriptions', { params });
}

export function subscribeStudentToClub(data: {
  student_id: number;
  club_id: number;
  academic_year_id: number;
  start_date?: string;
  monthly_fee_override?: number;
}): Promise<ClubSubscriptionItem> {
  return apiFetch<ClubSubscriptionItem>('/club-subscriptions', { method: 'POST', body: data });
}

export function cancelClubSubscription(id: number): Promise<{ message: string }> {
  return apiFetch<{ message: string }>(`/club-subscriptions/${id}`, { method: 'DELETE' });
}

export function excludeStudentFromClub(
  subscriptionId: number,
  reason?: string
): Promise<{ message: string; subscription: ClubSubscriptionItem }> {
  return apiFetch(`/club-subscriptions/${subscriptionId}/exclude`, { method: 'POST', body: { reason } });
}

export function restoreStudentToClub(
  subscriptionId: number
): Promise<{ message: string; subscription: ClubSubscriptionItem }> {
  return apiFetch(`/club-subscriptions/${subscriptionId}/restore`, { method: 'POST' });
}

export function fetchClubArrearsDashboard(params: {
  academic_year_id?: number;
  club_id?: number;
  level_id?: number;
  section_id?: number;
  search?: string;
}): Promise<ClubArrearsDashboardData> {
  return apiFetch<ClubArrearsDashboardData>('/reports/club-arrears', { params });
}

export function fetchClubFeesReport(params: {
  month?: string;
  academic_year_id?: number;
  club_id?: number;
  level_id?: number;
  section_id?: number;
  status?: string;
  search?: string;
}): Promise<ClubReportData> {
  return apiFetch<ClubReportData>('/reports/club-fees', { params });
}

export function generateClubMonthFees(data: {
  academic_year_id: number;
  month: string;
  club_id?: number;
  section_id?: number;
}): Promise<{ message: string; result: { month: string; created: number; skipped: number } }> {
  return apiFetch('/reports/club-fees/generate', { method: 'POST', body: data });
}

export function collectClubFeePayment(
  monthlyFeeId: number,
  data: {
    amount_paid: number;
    paid_at: string;
    method: 'cash' | 'bank_transfer' | 'check' | 'card';
    reference?: string;
    notes?: string;
  }
): Promise<{ message: string; record: ClubReportRecord }> {
  return apiFetch(`/club-monthly-fees/${monthlyFeeId}/collect`, { method: 'POST', body: data });
}

export function cancelClubFeePayment(
  monthlyFeeId: number,
  reason: string
): Promise<{ message: string; record: ClubReportRecord }> {
  return apiFetch(`/club-monthly-fees/${monthlyFeeId}/cancel`, { method: 'POST', body: { reason } });
}
