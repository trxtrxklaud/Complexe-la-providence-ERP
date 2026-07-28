import { apiFetch, type QueryParams } from './http';

/** شكل الترقيم القياسي في Laravel. */
export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type NameRef = { id: number; name: string };

export type ExpenseCategory = {
  id: number;
  name: string;
  is_active: boolean;
  notes?: string | null;
  /** يأتي من withCount('expenses') — يمنع حذف صنف مستعمل. */
  expenses_count?: number;
};

export type Expense = {
  id: number;
  expense_category_id: number | null;
  academic_year_id: number | null;
  label: string;
  amount: number | string;
  expense_date: string;
  method?: string | null;
  reference?: string | null;
  notes?: string | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  category?: NameRef | null;
  academic_year?: NameRef | null;
  created_by?: unknown;
  cancelled_by?: unknown;
};

export type ExpenseInput = {
  label: string;
  amount: number;
  expense_date: string;
  expense_category_id?: number | null;
  academic_year_id?: number | null;
  method?: string | null;
  reference?: string | null;
  notes?: string | null;
};

export type ExpenseFilters = {
  expense_category_id?: number | null;
  academic_year_id?: number | null;
  date_from?: string | null;
  date_to?: string | null;
  exclude_cancelled?: boolean;
  cancelled?: boolean;
  per_page?: number;
  page?: number;
};

// ===== أصناف المصاريف =====

export function fetchExpenseCategories(activeOnly = false): Promise<ExpenseCategory[]> {
  return apiFetch<ExpenseCategory[]>('/expense-categories', {
    params: { active_only: activeOnly ? 1 : undefined },
    fallbackMessage: 'تعذّر تحميل أصناف المصاريف',
  });
}

export function createExpenseCategory(payload: { name: string; is_active?: boolean; notes?: string | null }): Promise<ExpenseCategory> {
  return apiFetch<ExpenseCategory>('/expense-categories', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر إضافة الصنف',
  });
}

export function updateExpenseCategory(id: number, payload: { name?: string; is_active?: boolean; notes?: string | null }): Promise<ExpenseCategory> {
  return apiFetch<ExpenseCategory>('/expense-categories/' + id, {
    method: 'PUT',
    body: payload,
    fallbackMessage: 'تعذّر تعديل الصنف',
  });
}

export function deleteExpenseCategory(id: number): Promise<{ message: string }> {
  return apiFetch<{ message: string }>('/expense-categories/' + id, {
    method: 'DELETE',
    fallbackMessage: 'تعذّر حذف الصنف',
  });
}

// ===== المصاريف =====

export function fetchExpenses(filters: ExpenseFilters = {}): Promise<Paginated<Expense>> {
  return apiFetch<Paginated<Expense>>('/expenses', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل المصاريف',
  });
}

export function createExpense(payload: ExpenseInput): Promise<Expense> {
  return apiFetch<Expense>('/expenses', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تسجيل المصروف',
  });
}

export function updateExpense(id: number, payload: Partial<ExpenseInput>): Promise<Expense> {
  return apiFetch<Expense>('/expenses/' + id, {
    method: 'PUT',
    body: payload,
    fallbackMessage: 'تعذّر تعديل المصروف',
  });
}

/** إلغاء موثّق بسبب — لا يوجد حذف للمصاريف حفاظاً على أثر التقارير. */
export function cancelExpense(id: number, reason: string): Promise<Expense> {
  return apiFetch<Expense>('/expenses/' + id + '/cancel', {
    method: 'POST',
    body: { reason },
    fallbackMessage: 'تعذّر إلغاء المصروف',
  });
}
