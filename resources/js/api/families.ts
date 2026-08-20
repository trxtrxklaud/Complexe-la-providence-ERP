import { apiFetch, type QueryParams } from './http';
import type { ReceiptData } from '../pages/Payments/ReceiptModal';

export interface FamilyStudentSummary {
  id: number;
  name: string;
  student_code?: string;
  level_name?: string;
  section_name?: string;
  remaining_debt: number;
  total_paid?: number;
}

export interface FamilySummary {
  id: number | string;
  guardian_name: string;
  phone: string;
  mother_name?: string;
  mother_phone?: string;
  address?: string;
  students_count: number;
  students: FamilyStudentSummary[];
  family_total_due: number;
  family_total_paid: number;
  family_remaining_debt: number;
}

export interface FamilyMonthGridItem {
  month: string;
  month_number: number;
  name_ar: string;
  status: 'paid' | 'unpaid' | 'waived' | 'partial';
  gross_amount: number;
  discount_amount: number;
  net_amount: number;
  paid_amount: number;
  payment_info?: {
    payment_id?: number;
    receipt_number?: string;
    date?: string;
    amount?: number;
  } | null;
}

export interface FamilyClubMonthItem {
  club_monthly_fee_id: number;
  month: string;
  name_ar: string;
  amount_due: number;
  amount_paid: number;
  remaining_amount: number;
  status: 'paid' | 'unpaid' | 'partial';
}

export interface FamilyClubItem {
  club_id: number;
  club_name: string;
  monthly_fee: number;
  months: FamilyClubMonthItem[];
}

export interface FamilyArrearItem {
  id?: number;
  student_fee_id: number;
  fee_type_id?: number;
  description: string;
  amount_due: number;
  amount_paid: number;
  remaining_amount: number;
  status?: string;
}

export interface FamilyStudentDetail {
  id: number;
  student_id: number;
  enrollment_id: number;
  student_code: string;
  first_name: string;
  last_name: string;
  name: string;
  full_name: string;
  level_name?: string;
  section_name?: string;
  base_monthly_fee: number;
  remaining_debt: number;
  total_paid: number;
  months_grid: FamilyMonthGridItem[];
  clubs: FamilyClubItem[];
  arrears: FamilyArrearItem[];
  unpaid_fees?: any[];
}

export interface FamilyFullDetails {
  id: number | string;
  guardian_name: string;
  phone: string;
  mother_name?: string;
  mother_phone?: string;
  address?: string;
  students_count: number;
  total_due: number;
  total_paid: number;
  remaining_debt: number;
  family_total_due?: number;
  family_total_paid?: number;
  family_remaining_debt?: number;
  students: FamilyStudentDetail[];
  available_clubs?: Array<{ id: number; name: string; monthly_fee: number }>;
  available_fee_types?: Array<{ id: number; name_ar: string; price: number; ledger_category: string }>;
}

export interface StudentAllocationInput {
  student_id: number;
  enrollment_id: number;
  months?: string[];
  club_items?: Array<{ club_monthly_fee_id: number; amount: number }>;
  prior_allocations?: Array<{ student_fee_id: number; amount: number }>;
}

export interface FamilyCollectionPayload {
  payment_date: string;
  method: string;
  reference?: string | null;
  notes?: string | null;
  students_allocations?: StudentAllocationInput[];
  allocations?: any[];
}

export function fetchFamilies(params: { search?: string; page?: number; per_page?: number } = {}) {
  return apiFetch<{
    data: FamilySummary[];
    current_page: number;
    last_page: number;
    total: number;
  }>('/families', {
    params: params as QueryParams,
    fallbackMessage: 'تعذّر تحميل قائمة العائلات',
  });
}

export function fetchFamilyDetails(familyId: number | string) {
  return apiFetch<FamilyFullDetails>(`/families/${familyId}`, {
    fallbackMessage: 'تعذّر تحميل بيانات العائلة',
  });
}

export function collectFamilyPayment(familyId: number | string, payload: FamilyCollectionPayload) {
  return apiFetch<{ message: string; receipt: ReceiptData }>(`/families/${familyId}/collect`, {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تنفيذ الاستخلاص الجماعي للعائلة',
  });
}
