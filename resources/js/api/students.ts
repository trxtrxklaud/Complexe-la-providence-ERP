import type { User } from '../types';
import { apiFetch, API_BASE, getHeaders } from './http';

export interface Guardian {
    first_name: string;
    last_name: string;
    phone: string;
    relationship?: string;
}

export interface StudentFeeItem {
    id: number;
    fee_type_id?: number | null;
    fee_type?: { id?: number; name_ar?: string; ledger_category?: string } | null;
    description: string;
    amount_due: number | string;
    direct_paid_amount?: number | string;
    status: 'pending' | 'partial' | 'paid' | 'cancelled';
    due_date?: string | null;
}

export interface Enrollment {
    id: number;
    academic_year_id?: number;
    section_id?: number;
    level_id?: number;
    level: { id?: number; name: string } | null;
    section: { id?: number; name: string } | null;
    academic_year?: { id?: number; name: string; is_active?: boolean } | null;
    status?: string | null;
    student_fees?: StudentFeeItem[];
}

export interface EnrollmentResponse {
    message: string;
    enrollment: Enrollment;
    /** يرجع معبّأً فقط حين ُقبض مبلغ مع الترسيم؛ null يعني ترسيماً بلا دفع. */
    payment?: {
        id: number;
        amount: number | string;
        payment_date: string | null;
        method: string;
    } | null;
}

export interface Student {
    id: number;
    student_code: string | null;
    first_name: string;
    last_name: string;
    gender: 'male' | 'female' | 'unknown' | null;
    dob: string | null;
    photo?: string | null;
    notes?: string | null;
    status?: string | null;
    guardian_first_name?: string | null;
    guardian_last_name?: string | null;
    mother_name?: string | null;
    guardian_phone?: string | null;
    mother_phone?: string | null;
    guardian_email?: string | null;
    mother_email?: string | null;
    address?: string | null;
    guardians: Guardian[];
    enrollments: Enrollment[];
}

export interface StudentPaymentHistoryEntry {
    id: number;
    amount: number | string;
    payment_date: string | null;
    months: string[];
    method: string;
    reference: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    enrollment: Enrollment | null;
    allocations: Array<{
        amount: number | string;
        fee: {
            id: number;
            description: string | null;
            amount_due: number | string;
            due_date: string;
            status: string;
        } | null;
    }>;
}

export type StudentSearchFilters = {
    level?: string;
    student_name?: string;
    phone?: string;
    birthday?: string;
    year?: string;
    cnte?: string;
    gender?: string;
    per_page?: number;
    search?: string;
};

export type StudentSearchResponse = {
    data: Student[];
    total_count: number;
    male_count: number;
    female_count: number;
    unknown_count: number;
};

/** قسم واحد من جدول sections، مع عنوان جاهز للعرض مثل «السنة الثالثة ب». */
export type SectionOption = {
    id: number;
    level_id?: number;
    label: string;
};

export type StudentSearchOptions = {
    /** اسم تاريخي: المحتوى أقسام لا مستويات. استعمل sections في الكود الجديد. */
    levels: SectionOption[];
    sections?: SectionOption[];
    years: Array<{ id: number; name: string }>;
    active_year?: { id: number; name: string } | null;
};

export type TransferStudent = {
    id: number;
    student_code: string | null;
    first_name: string;
    last_name: string;
    dob: string | null;
    gender: 'male' | 'female' | 'unknown' | null;
    guardian_name: string;
    mother_name: string | null;
    phone: string | null;
};

export type TransferStudentsPayload = {
    academic_year_id: number;
    source_section_id: number;
    destination_section_id: number;
    student_ids: number[];
};

/** رؤوس موحّدة تتضمن توكن Sanctum، بلا Content-Type: مطلوبة لطلبات FormData. */
function authHeaders(): Record<string, string> {
    const { 'Content-Type': _contentType, ...rest } = getHeaders();
    return rest;
}

/** يستخرج أول رسالة تحقّق من ردّ 422، وإلا فرسالة الخطأ العامة. */
function firstValidationMessage(payload: any, fallback: string): string {
    const errors = payload?.errors;
    if (errors && typeof errors === 'object') {
        for (const key of Object.keys(errors)) {
            const list = errors[key];
            if (Array.isArray(list) && typeof list[0] === 'string') return list[0];
        }
    }
    return payload?.message || fallback;
}

/**
 * خطأ يحمل code القادم من الخادم.
 *
 * Error العادي يحمل نصّاً فقط، ومطابقة النصوص العربية في الواجهة هشّة:
 * تعديل حرف واحد في الرسالة يكسر المنطق صامتاً. لذلك نمرّر code منفصلاً.
 */
export class ApiError extends Error {
    code: string | null;

    constructor(message: string, code: string | null = null) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
    }
}

export async function getStudents(filters: StudentSearchFilters = {}, signal?: AbortSignal): Promise<Student[]> {
    const data = await apiFetch<Student[] | { data: Student[] }>('/students', {
        params: filters,
        signal,
        fallbackMessage: 'حدث خطأ أثناء جلب قائمة التلاميذ',
    });
    return Array.isArray(data) ? data : (data.data ?? []);
}

export async function getStudentsFullResponse(filters: StudentSearchFilters = {}, signal?: AbortSignal): Promise<StudentSearchResponse> {
    const raw = await apiFetch<any>('/students', {
        params: filters,
        signal,
        fallbackMessage: 'حدث خطأ أثناء جلب قائمة التلاميذ',
    });

    const rows: Student[] = Array.isArray(raw) ? raw : (raw.data ?? []);
    const totalCount = raw.total_count ?? (Array.isArray(raw) ? raw.length : (raw.total ?? rows.length));
    const maleCount = raw.male_count ?? rows.filter((s: Student) => s.gender === 'male' || (s as any).gender === 'ذكر').length;
    const femaleCount = raw.female_count ?? rows.filter((s: Student) => s.gender === 'female' || (s as any).gender === 'أنثى').length;
    const unknownCount = raw.unknown_count ?? Math.max(0, totalCount - maleCount - femaleCount);

    return {
        data: rows,
        total_count: totalCount,
        male_count: maleCount,
        female_count: femaleCount,
        unknown_count: unknownCount,
    };
}

export const getStudentSearchOptions = (signal?: AbortSignal) =>
    apiFetch<StudentSearchOptions>('/students/search-options', {
        signal,
        fallbackMessage: 'تعذّر تحميل خيارات البحث',
    });

/**
 * كلّ الأقسام من جدول sections مرتّبة حسب المستوى ثم الاسم.
 *
 * تعتمد على /students/search-options لا على /levels، لأنّ الثاني محميّ بـ manage_users
 * فيما الأوّل محميّ بـ manage_students؛ والقابض الذي يرسّم لا يملك manage_users.
 */
export const getSectionOptions = async (signal?: AbortSignal): Promise<SectionOption[]> => {
    const options = await getStudentSearchOptions(signal);
    return options.sections ?? options.levels ?? [];
};

export const getTransferStudents = (
    academicYearId: number,
    sectionId: number,
    signal?: AbortSignal,
) => apiFetch<{ students: TransferStudent[] }>('/students/transfer-roster', {
    params: { academic_year_id: academicYearId, section_id: sectionId },
    signal,
    fallbackMessage: 'تعذّر تحميل تلاميذ القسم المصدر',
});

export const transferStudents = (payload: TransferStudentsPayload) =>
    apiFetch<{ transferred: number; message: string }>('/students/transfer', {
        method: 'POST',
        body: payload,
        fallbackMessage: 'تعذّر نقل التلاميذ',
    });

export async function getStudent(id: number): Promise<Student> {
    const res = await fetch(`${API_BASE}/students/${id}`, { headers: authHeaders(), credentials: 'include' });
    if (!res.ok) throw new Error('حدث خطأ أثناء جلب بيانات التلميذ');
    return res.json();
}

export async function getStudentPaymentHistory(id: number): Promise<StudentPaymentHistoryEntry[]> {
    const res = await fetch(`${API_BASE}/students/${id}/payments`, { headers: authHeaders(), credentials: 'include' });
    if (!res.ok) throw new Error('تعذّر تحميل سجل دفعات التلميذ');
    return res.json();
}

export async function enrollStudent(formData: FormData): Promise<EnrollmentResponse> {
    const regAmount = formData.get('registration_amount');
    if (regAmount && Number(regAmount) > 0 && !formData.get('client_request_id')) {
        const reqId = typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `req-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
        formData.set('client_request_id', reqId);
    }

    const res = await fetch(`${API_BASE}/students/enroll`, {
        method: 'POST',
        headers: authHeaders(), // بدون Content-Type — browser يضبطه تلقائياً مع FormData
        credentials: 'include',
        body: formData,
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(firstValidationMessage(err, 'حدث خطأ أثناء التسجيل'));
    }
    return res.json();
}

/** معلوم التجديد المقبوض لحظة الترسيم؛ الثلاثة معاً أو لا شيء منها. */
export type ReenrollPayment = {
    client_request_id?: string;
    registration_amount: number;
    payment_method: 'cash' | 'bank_transfer' | 'check' | 'card';
    payment_date: string;
    payment_notes?: string;
    fee_items?: Array<{ fee_type_id: number; amount: number; description?: string }>;
};

/**
 * ترسيم تلميذ قديم في السنة النشطة، مع معلوم التجديد إن قُبض.
 *
 * section_id إجباري: الخادم يشتقّ level_id من القسم، فلا يمكن أن يقع تناقض بينهما.
 * حقول الدفع اختيارية، وإن أُرسل المبلغ وجب معه الطريقة والتاريخ (يفرضه الخادم أيضاً).
 *
 * يرمي ApiError بـ code = 'already_enrolled' حين يكون التلميذ مُرسَّماً سلفاً،
 * وعندئذ العلاج هو recordRegistrationPayment لا تكرار الترسيم.
 */
export async function reenrollStudent(studentId: number, data: {
    section_id: number;
    notes?: string;
} & Partial<ReenrollPayment>): Promise<EnrollmentResponse> {
    const payload = { ...data };
    if (payload.registration_amount && Number(payload.registration_amount) > 0 && !payload.client_request_id) {
        payload.client_request_id = typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `req-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
    }

    const res = await fetch(`${API_BASE}/students/${studentId}/reenroll`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new ApiError(
            firstValidationMessage(err, 'حدث خطأ أثناء إعادة التسجيل'),
            typeof err?.code === 'string' ? err.code : null,
        );
    }
    return res.json();
}

/**
 * قبض معلوم الترسيم لتلميذ ترسيمه قائم في السنة النشطة.
 *
 * لا يُنشئ ترسيماً ولا يُعدّل قسماً: يسجّل المال فقط على الترسيم القائم،
 * فيدخل الدفتر النقدي تحت بند معاليم التسجيل ويظهر في اليومي والشهري والسنوي.
 */
export async function recordRegistrationPayment(
    studentId: number,
    data: ReenrollPayment,
): Promise<EnrollmentResponse> {
    const payload = { ...data };
    if (payload.registration_amount && Number(payload.registration_amount) > 0 && !payload.client_request_id) {
        payload.client_request_id = typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `req-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
    }

    const res = await fetch(`${API_BASE}/students/${studentId}/registration-payment`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new ApiError(
            firstValidationMessage(err, 'تعذّر تسجيل المبلغ'),
            typeof err?.code === 'string' ? err.code : null,
        );
    }
    return res.json();
}

/**
 * Update a single student's gender field.
 * Only authorized student managers can call this.
 * Sends PATCH /students/{id} with { gender: 'male' | 'female' }.
 */
export async function updateStudentGender(
    studentId: number,
    gender: 'male' | 'female',
): Promise<Student> {
    const res = await fetch(`${API_BASE}/students/${studentId}`, {
        method: 'PATCH',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ gender }),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(firstValidationMessage(err, 'تعذّر تحديث الجنس'));
    }
    return res.json();
}

/**
 * إلغاء ترسيم تلميذ في السنة النشطة مع سحب المبالغ من الخزينة وحذف الترسيم وتوثيق العملية.
 */
export async function cancelStudentEnrollment(
    studentId: number,
    reason: string,
): Promise<{ message: string }> {
    const res = await fetch(`${API_BASE}/students/${studentId}/cancel-enrollment`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ reason }),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new ApiError(
            firstValidationMessage(err, 'تعذّر إلغاء الترسيم'),
            typeof err?.code === 'string' ? err.code : null,
        );
    }
    return res.json();
}
