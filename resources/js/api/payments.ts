import type {
  Payment,
  StorePaymentPayload,
  StudentFeesEnrollment,
  PaginatedResponse,
} from '../types';
import { API_BASE, getHeaders } from './http';

export interface PaymentFilters {
  student_id?: number;
  enrollment_id?: number;
  method?: string;
  date_from?: string;
  date_to?: string;
  per_page?: number;
  page?: number;
  // إرجاع الوصولات الملغاة فقط (صفحة Historique)
  cancelled?: boolean;
  // استبعاد الملغاة من النتائج
  exclude_cancelled?: boolean;
}

export const paymentsApi = {
  async index(filters?: PaymentFilters): Promise<PaginatedResponse<Payment>> {
    const params = new URLSearchParams();
    if (filters) {
      Object.entries(filters).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') params.append(k, String(v));
      });
    }
    const q = params.toString();
    const url = API_BASE + '/payments' + (q ? '?' + q : '');
    const res = await fetch(url, { headers: getHeaders() });
    if (!res.ok) throw new Error('فشل جلب المدفوعات');
    return res.json();
  },

  async store(data: StorePaymentPayload): Promise<Payment> {
    const res = await fetch(API_BASE + '/payments', {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify(data),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'فشل تسجيل الدفعة');
    }
    return res.json();
  },

  async show(id: number): Promise<Payment> {
    const res = await fetch(API_BASE + '/payments/' + id, { headers: getHeaders() });
    if (!res.ok) throw new Error('فشل جلب الدفعة');
    return res.json();
  },

  // إلغاء موثّق بدل الحذف النهائي: يشترط سبباً ويُبقي السجل للمراجعة.
  async cancel(id: number, reason: string): Promise<Payment> {
    const res = await fetch(API_BASE + '/payments/' + id + '/cancel', {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ reason }),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'فشل إلغاء الدفعة');
    }
    return res.json();
  },
};

export const studentFeesApi = {
  async balance(studentId: number): Promise<{ student_id: number; balance: number }> {
    const res = await fetch(API_BASE + '/students/' + studentId + '/balance', {
      headers: getHeaders(),
    });
    if (!res.ok) throw new Error('فشل جلب الرصيد');
    return res.json();
  },

  async fees(studentId: number, enrollmentId?: number): Promise<StudentFeesEnrollment[]> {
    const q = enrollmentId ? '?enrollment_id=' + enrollmentId : '';
    const res = await fetch(API_BASE + '/students/' + studentId + '/fees' + q, {
      headers: getHeaders(),
    });
    if (!res.ok) throw new Error('فشل جلب رسوم التلميذ');
    return res.json();
  },
};

export async function collectPayment(data: {
  student_id: number;
  enrollment_id: number;
  months: string[];
  payment_date: string;
  method: 'cash' | 'bank_transfer' | 'check' | 'card';
  reference?: string | null;
  notes?: string | null;
  items: { fee_type_id: number; amount: number }[];
  // توزيع صريح على متخلّدات السنوات السابقة (اختياري).
  prior_allocations?: { student_fee_id: number; amount: number }[];
}) {
  const res = await fetch(API_BASE + '/payments/collect', {
    method: 'POST',
    headers: getHeaders(),
    body: JSON.stringify(data),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'فشل الاستخلاص');
  }
  return res.json();
}

export async function getEnrollmentLedger(enrollmentId: number) {
  const res = await fetch(API_BASE + '/enrollments/' + enrollmentId + '/ledger', {
    headers: getHeaders(),
  });
  if (!res.ok) throw new Error('فشل جلب سجل الأشهر');
  return res.json();
}

export async function getCollectionYears() {
  const res = await fetch(API_BASE + '/collection/years', { headers: getHeaders() });
  if (!res.ok) throw new Error('فشل جلب السنوات');
  return res.json();
}

export async function getSectionsByYear(yearId: number) {
  const res = await fetch(API_BASE + '/collection/years/' + yearId + '/sections', { headers: getHeaders() });
  if (!res.ok) throw new Error('فشل جلب الأقسام');
  return res.json();
}

export async function getStudentsBySection(sectionId: number, yearId: number) {
  const res = await fetch(API_BASE + '/collection/sections/' + sectionId + '/students?year_id=' + yearId, { headers: getHeaders() });
  if (!res.ok) throw new Error('فشل جلب التلاميذ');
  return res.json();
}

export type CollectionPreview = {
  enrollment_id: number;
  student_id: number;
  months: string[];
  gross_amount: number;
  discount_type: 'full_waiver' | 'humanitarian_fixed' | 'normal' | null;
  discount_amount: number;
  net_due: number;
  amount_paid: number;
  remaining_amount: number;
  is_fully_waived: boolean;
  discount_reason: string | null;
  can_collect: boolean;
  items: Array<{
    month: string;
    gross_amount: number;
    discount_type: string | null;
    discount_amount: number;
    net_due: number;
    amount_paid: number;
    remaining_amount: number;
    is_fully_waived: boolean;
    discount_reason: string | null;
  }>;
};

export async function getCollectionPreview(enrollmentId: number, months: string[], feeTypeId?: number): Promise<CollectionPreview> {
  const params = new URLSearchParams();
  params.append('enrollment_id', String(enrollmentId));
  months.forEach((m) => params.append('months[]', m));
  if (feeTypeId) params.append('fee_type_id', String(feeTypeId));

  const res = await fetch(API_BASE + '/payments/collect/preview?' + params.toString(), { headers: getHeaders() });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    let msg = err.message || 'فشل معاينة الاستخلاص والتخفيضات';
    if (err.errors && typeof err.errors === 'object') {
      const firstKey = Object.keys(err.errors)[0];
      if (firstKey && Array.isArray(err.errors[firstKey]) && err.errors[firstKey][0]) {
        msg = err.errors[firstKey][0];
      }
    }
    if (typeof msg === 'string' && (msg.includes('SQLSTATE') || msg.includes('no such table'))) {
      msg = 'حدث خطأ غير متوقع في قاعدة البيانات أثناء معاينة التخفيضات';
    }
    throw new Error(msg);
  }
  return res.json();

}

/**
 * الرصيد الافتتاحي: متخلّدات السنوات السابقة المنقولة للسنة النشطة.
 */
export interface OpeningBalanceItem {
  opening_balance_id: number;
  student_fee_id: number;
  source_year_id: number | null;
  description: string;
  amount: number;
  paid: number;
  outstanding: number;
}

export async function getStudentOpeningBalances(studentId: number, academicYearId?: number) {
  const q = academicYearId ? '?academic_year_id=' + academicYearId : '';
  const res = await fetch(API_BASE + '/collection/students/' + studentId + '/opening-balances' + q, {
    headers: getHeaders(),
  });
  if (!res.ok) throw new Error('فشل جلب متخلّدات السنوات السابقة');
  return res.json() as Promise<{
    student_id: number;
    academic_year_id: number | null;
    summary: { count: number; total: number; outstanding: number; paid: number };
    items: OpeningBalanceItem[];
  }>;
}

/**
 * معاينة توزيع الدفعة وفق الترتيب الافتراضي (الأقدم أولاً) — يراها المحاسب
 * قبل التثبيت ويعدّلها يدوياً عبر prior_allocations في شاشة الاستخلاص.
 */
export async function getAllocationPreview(studentId: number, amount: number) {
  const res = await fetch(API_BASE + '/collection/students/' + studentId + '/allocation-preview?amount=' + amount, {
    headers: getHeaders(),
  });
  if (!res.ok) throw new Error('فشل معاينة توزيع الدفعة');
  return res.json();
}
