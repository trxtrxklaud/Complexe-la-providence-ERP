import { apiFetch, type QueryParams } from './http';
import type { ReceiptData } from '../pages/Payments/ReceiptModal';

export interface FamilyStudentSummary {
  id: number;
  name: string;
  student_code?: string;
  section_name?: string;
  remaining_debt: number;
}

export interface FamilySummary {
  id: number | string;
  guardian_name: string;
  phone: string;
  address?: string;
  students_count: number;
  students: FamilyStudentSummary[];
  family_total_due: number;
  family_total_paid: number;
  family_remaining_debt: number;
}

export interface UnpaidFeeItem {
  id: number;
  fee_type_id: number;
  description: string;
  month_name?: string;
  gross_amount: number;
  discount_amount: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  is_new?: boolean;
  item_type?: 'tuition' | 'registration' | 'club';
}

export interface FamilyStudentDetail {
  id: number;
  first_name: string;
  last_name: string;
  name: string;
  student_code?: string;
  section_name?: string;
  academic_year?: string;
  enrollment_id?: number;
  remaining_debt: number;
  total_paid: number;
  unpaid_fees: UnpaidFeeItem[];
}

export interface AvailableClub {
  id: number;
  name: string;
  monthly_fee: number;
}

export interface AvailableFeeType {
  id: number;
  name_ar: string;
  price: number;
  ledger_category: string;
}

export interface FamilyFullDetails {
  id: number | string;
  guardian_name: string;
  phone: string;
  email?: string;
  address?: string;
  mother_phone?: string;
  students_count: number;
  students: FamilyStudentDetail[];
  family_total_due: number;
  family_total_paid: number;
  family_remaining_debt: number;
  available_clubs?: AvailableClub[];
  available_fee_types?: AvailableFeeType[];
}

export interface NewItemInput {
  student_id: number;
  enrollment_id: number;
  type: 'registration' | 'club' | 'custom';
  fee_type_id?: number;
  club_id?: number;
  description: string;
  month_name?: string;
  amount_due: number;
}

export interface CollectiveAllocationInput {
  student_id: number;
  student_fee_id: number;
  amount: number;
  new_item?: NewItemInput;
}

export interface CollectivePaymentPayload {
  allocations: CollectiveAllocationInput[];
  payment_date: string;
  method: string;
  reference?: string | null;
  notes?: string | null;
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

export function collectFamilyPayment(familyId: number | string, payload: CollectivePaymentPayload) {
  return apiFetch<{ message: string; receipt: ReceiptData }>(`/families/${familyId}/collect`, {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تنفيذ الاستخلاص الجماعي للعائلة',
  });
}
