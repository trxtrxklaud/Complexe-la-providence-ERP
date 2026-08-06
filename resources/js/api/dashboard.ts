import { apiFetch } from './http';

/**
 * أرقام الصندوق لفترة واحدة، محسوبة على الخادم من cash_transactions.
 */
export type CashFigures = {
  income: number;
  expenses: number;
  net_income: number;
  withdrawals: number;
  balance: number;
};

export type DashboardData = {
  current_date: string;
  academic_year: { id: number; name: string } | null;
  total_students: number;
  new_students_this_year: number;
  total_males: number;
  total_females: number;
  total_unspecified_gender: number;
  outstanding_balance: number;
  financial_summary: {
    total_expected: number;
    collected_amount: number;
    pending_amount: number;
  };
  cash: {
    today: CashFigures;
    month: CashFigures;
    all_time: CashFigures;
  };
  treasury_balance: number;
};

/**
 * الخادم يغلّف النتيجة في { success, message, data }، فيُستخرج data هنا
 * كي لا تعرف الشاشات شيئاً عن شكل التغليف.
 */
export async function fetchDashboard(signal?: AbortSignal): Promise<DashboardData> {
  const response = await apiFetch<{ success: boolean; message: string; data: DashboardData }>(
    '/dashboard',
    { fallbackMessage: 'فشل تحميل لوحة المعلومات', signal },
  );

  return response.data;
}
