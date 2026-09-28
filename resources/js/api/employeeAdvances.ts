import { API_BASE, getHeaders } from './http';
import {
  type EmployeeAdvance,
  type AdvanceRepayment,
  type Employee,
  getEmployees,
  getAdvances,
  createAdvance,
  settleAdvance,
  cancelAdvance,
  getRepayments,
  cancelRepayment,
} from './employees';

export {
  type EmployeeAdvance,
  type AdvanceRepayment,
  type Employee,
  getEmployees,
};

export interface CreateAdvancePayload {
  employee_id: number;
  academic_year_id?: number;
  type: 'advance' | 'loan';
  amount: number;
  advance_date?: string;
  due_date?: string | null;
  installment_count?: number;
  repayment_method?: 'salary_deduction' | 'cash';
  purpose?: string;
  method?: string;
  reason?: string;
  notes?: string | null;
}

export async function fetchEmployeeAdvances(params?: {
  employee_id?: number;
  academic_year_id?: number;
  type?: 'advance' | 'loan';
  outstanding?: boolean;
}): Promise<EmployeeAdvance[]> {
  return getAdvances(params);
}

export async function createEmployeeAdvance(data: CreateAdvancePayload): Promise<EmployeeAdvance> {
  const payload: any = {
    ...data,
    advance_date: data.advance_date || new Date().toISOString().slice(0, 10),
  };
  const res = await fetch(`${API_BASE}/employee-advances`, {
    method: 'POST',
    headers: getHeaders(),
    body: JSON.stringify(payload),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'فشل حفظ السلفة/التسبقة');
  }
  return res.json();
}

export async function settleAdvanceRepayment(
  advanceId: number,
  data: {
    amount: number;
    repaid_at?: string;
    method?: 'cash' | 'salary_deduction';
    notes?: string;
  }
): Promise<{ repayment: AdvanceRepayment; advance: EmployeeAdvance }> {
  return settleAdvance(advanceId, data);
}

export async function cancelEmployeeAdvance(
  advanceId: number,
  reason: string
): Promise<EmployeeAdvance> {
  return cancelAdvance(advanceId, reason);
}

export async function fetchAdvanceRepayments(advanceId: number): Promise<AdvanceRepayment[]> {
  return getRepayments(advanceId);
}

export async function cancelSingleRepayment(
  repaymentId: number,
  reason: string
): Promise<void> {
  return cancelRepayment(repaymentId, reason);
}
