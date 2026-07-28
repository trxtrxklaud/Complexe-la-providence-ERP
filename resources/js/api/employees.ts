import { API_BASE, getHeaders } from './http';

export interface Employee {
  id: number;
  first_name: string;
  last_name: string;
  phone?: string | null;
  email?: string | null;
  job_title?: string | null;
  default_salary?: string | number | null;
  is_active: boolean;
  notes?: string | null;
}

/**
 * تسبقة (advance) تُخصم كاملة من راتب الشهر نفسه،
 * أو سلفة (loan) تُردّ على مهل دفعات.
 */
export interface EmployeeAdvance {
  id: number;
  employee_id: number;
  academic_year_id?: number | null;
  type: 'advance' | 'loan';
  amount: string | number;
  settled_amount: string | number;
  advance_date: string;
  method?: string | null;
  reason?: string | null;
  status: string;
  is_opening?: boolean;
  settled_by_salary_id?: number | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  employee?: { id: number; first_name: string; last_name: string };
}

export interface Salary {
  id: number;
  employee_id: number;
  academic_year_id: number;
  /** الصافي المدفوع نقداً — وهو وحده ما يدخل الدفتر النقدي. */
  amount: string | number;
  gross_amount?: string | number | null;
  advance_deduction?: string | number | null;
  period_from: string;
  period_to: string;
  paid_at?: string | null;
  method?: string | null;
  reference?: string | null;
  notes?: string | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  employee?: { id: number; first_name: string; last_name: string };
  academic_year?: { id: number; name: string };
  settled_advances?: EmployeeAdvance[];
}

async function parse(res: Response) {
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'حدث خطأ');
  }
  return res.json();
}

export async function getEmployees(): Promise<Employee[]> {
  return parse(await fetch(`${API_BASE}/employees`, { headers: getHeaders() }));
}

export async function createEmployee(data: Partial<Employee>): Promise<Employee> {
  return parse(await fetch(`${API_BASE}/employees`, {
    method: 'POST', headers: getHeaders(), body: JSON.stringify(data),
  }));
}

export async function updateEmployee(id: number, data: Partial<Employee>): Promise<Employee> {
  return parse(await fetch(`${API_BASE}/employees/${id}`, {
    method: 'PUT', headers: getHeaders(), body: JSON.stringify(data),
  }));
}

export async function deleteEmployee(id: number): Promise<void> {
  await parse(await fetch(`${API_BASE}/employees/${id}`, {
    method: 'DELETE', headers: getHeaders(),
  }));
}

export async function getSalaries(params?: {
  academic_year_id?: number;
  employee_id?: number;
  page?: number;
}): Promise<{ data: Salary[]; total: number; current_page: number; last_page: number }> {
  const q = new URLSearchParams();
  if (params?.academic_year_id) q.set('academic_year_id', String(params.academic_year_id));
  if (params?.employee_id) q.set('employee_id', String(params.employee_id));
  if (params?.page) q.set('page', String(params.page));
  const url = `${API_BASE}/salaries` + (q.toString() ? `?${q}` : '');
  return parse(await fetch(url, { headers: getHeaders() }));
}

/** قائمة التسبقات والسلف مع مرشّحات اختيارية. */
export async function getAdvances(params?: {
  employee_id?: number;
  type?: 'advance' | 'loan';
  outstanding?: boolean;
}): Promise<EmployeeAdvance[]> {
  const q = new URLSearchParams({ per_page: '100' });
  if (params?.employee_id) q.set('employee_id', String(params.employee_id));
  if (params?.type) q.set('type', params.type);
  if (params?.outstanding) q.set('outstanding', '1');
  const res = await parse(await fetch(`${API_BASE}/employee-advances?${q}`, { headers: getHeaders() }));

  return res?.data ?? [];
}

/**
 * التسبقات القائمة لإطار واحد: غير ملغاة، ولم تُخلّص بعد.
 * السلف (loan) مستثناة لأنّها تُردّ على مهل ولا تُخصم من الراتب.
 */
export async function getOutstandingAdvances(employeeId: number): Promise<EmployeeAdvance[]> {
  const q = new URLSearchParams({
    employee_id: String(employeeId),
    type: 'advance',
    outstanding: '1',
    exclude_cancelled: '1',
    per_page: '100',
  });
  const res = await parse(await fetch(`${API_BASE}/employee-advances?${q}`, { headers: getHeaders() }));

  return res?.data ?? [];
}

export async function createAdvance(data: {
  employee_id: number;
  academic_year_id?: number;
  type: 'advance' | 'loan';
  amount: number;
  advance_date: string;
  method?: string;
  reason?: string;
}): Promise<EmployeeAdvance> {
  return parse(await fetch(`${API_BASE}/employee-advances`, {
    method: 'POST', headers: getHeaders(), body: JSON.stringify(data),
  }));
}

/** خلاص جزئي أو كلّي لسلفة تُردّ على مهل. لا يُستعمل مع التسبقة. */
export async function settleAdvance(id: number, amount: number): Promise<EmployeeAdvance> {
  return parse(await fetch(`${API_BASE}/employee-advances/${id}/settle`, {
    method: 'POST', headers: getHeaders(), body: JSON.stringify({ amount }),
  }));
}

export async function cancelAdvance(id: number, reason: string): Promise<EmployeeAdvance> {
  return parse(await fetch(`${API_BASE}/employee-advances/${id}/cancel`, {
    method: 'POST', headers: getHeaders(), body: JSON.stringify({ reason }),
  }));
}

/**
 * خلاص راتب.
 *
 * يُرسَل الراتب الخام وقائمة التسبقات المراد خصمها، والخادم وحده
 * يحسب الصافي. لا تُحتسب المبالغ في الواجهة.
 */
export async function createSalary(data: {
  employee_id: number;
  academic_year_id: number;
  gross_amount: number;
  advance_ids?: number[];
  period_from: string;
  period_to: string;
  paid_at?: string;
  method?: string;
  notes?: string;
}): Promise<Salary> {
  return parse(await fetch(`${API_BASE}/salaries`, {
    method: 'POST', headers: getHeaders(), body: JSON.stringify(data),
  }));
}

/**
 * الرواتب لا تُحذف: تُلغى بسبب موثّق، فيُسحب أثرها من الدفتر
 * وتعود التسبقات المخصومة بها قائمة من جديد.
 */
export async function cancelSalary(id: number, reason: string): Promise<Salary> {
  return parse(await fetch(`${API_BASE}/salaries/${id}/cancel`, {
    method: 'POST', headers: getHeaders(), body: JSON.stringify({ reason }),
  }));
}
