import { apiFetch } from './http';

export interface DiscountRosterSection {
  id: number;
  name: string;
  level_id: number | null;
  level: string | null;
  label: string;
}

export interface DiscountRosterYear {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  is_active: boolean;
}

export interface DiscountRosterMonthDef {
  key: string;       // e.g. "2025-09"
  label: string;     // e.g. "سبتمبر"
  year: number;      // e.g. 2025
  month_num: number; // e.g. 9
}

export interface DiscountRosterOptions {
  sections: DiscountRosterSection[];
  years: DiscountRosterYear[];
  active_year_id: number | null;
  months: DiscountRosterMonthDef[];
}

export interface MonthDiscountCell {
  has_discount: boolean;
  discount_id: number | null;
  discount_source: 'monthly' | 'annual' | null;
  discount_type: 'normal_monthly' | 'humanitarian_fixed' | 'full_waiver' | 'normal' | null;
  amount: number;
  amount_formatted: string;
  reason: string | null;
  notes: string | null;
  start_month: string | null;
  end_month: string | null;
  is_single_month: boolean;
  created_by: string | null;
}

export interface DiscountRosterRow {
  enrollment_id: number;
  student_id: number;
  student_code: string | null;
  name: string;
  monthly_fee: number;
  discount_cap: number;
  has_any_discount: boolean;
  total_discount: number;
  months: Record<string, MonthDiscountCell>;
}

export interface DiscountRosterSummary {
  students_count: number;
  with_discount_count: number;
  without_discount_count: number;
  total_discount_amount: number;
}

export interface DiscountRosterReport {
  section: {
    id: number;
    name: string;
    level_id: number | null;
    level_name: string | null;
    label: string;
  };
  academic_year: {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
  };
  months: DiscountRosterMonthDef[];
  reference_monthly_fee: number;
  discount_cap: number;
  summary: DiscountRosterSummary;
  rows: DiscountRosterRow[];
}

export interface ApplyDiscountPayload {
  enrollment_id: number;
  scope: 'single_month' | 'range' | 'full_year';
  target_month?: string;
  start_month?: string;
  end_month?: string;
  discount_type: 'normal_monthly' | 'humanitarian_fixed' | 'full_waiver';
  monthly_amount?: number | null;
  reason: string;
  notes?: string;
}

export interface RemoveDiscountPayload {
  enrollment_id: number;
  target_month?: string;
  discount_id?: number;
  reason: string;
}

export async function fetchDiscountRosterOptions(signal?: AbortSignal): Promise<DiscountRosterOptions> {
  return apiFetch<DiscountRosterOptions>('/discounts/roster-options', { signal });
}

export async function fetchDiscountRoster(
  sectionId: number | string,
  academicYearId?: number | string,
  signal?: AbortSignal
): Promise<DiscountRosterReport> {
  const query = academicYearId ? `?academic_year_id=${academicYearId}` : '';
  return apiFetch<DiscountRosterReport>(`/discounts/sections/${sectionId}/roster${query}`, { signal });
}

export async function applyDiscountRoster(payload: ApplyDiscountPayload): Promise<{ message: string }> {
  return apiFetch<{ message: string }>('/discounts/roster/apply', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export async function removeDiscountRoster(payload: RemoveDiscountPayload): Promise<{ message: string }> {
  return apiFetch<{ message: string }>('/discounts/roster/remove', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}
