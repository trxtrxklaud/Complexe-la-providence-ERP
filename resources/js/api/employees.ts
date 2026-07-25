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

export interface Salary {
  id: number;
  employee_id: number;
  academic_year_id: number;
  amount: string | number;
  period_from: string;
  period_to: string;
  paid_at?: string | null;
  method?: string | null;
  reference?: string | null;
  notes?: string | null;
  employee?: { id: number; first_name: string; last_name: string };
  academic_year?: { id: number; name: string };
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

export async function createSalary(data: {
  employee_id: number;
  academic_year_id: number;
  amount: number;
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

export async function deleteSalary(id: number): Promise<void> {
  await parse(await fetch(`${API_BASE}/salaries/${id}`, {
    method: 'DELETE', headers: getHeaders(),
  }));
}
