import type { User } from '../types';
import { apiFetch, API_BASE, getToken } from './http';

export interface Guardian {
    first_name: string;
    last_name: string;
    phone: string;
    relationship?: string;
}

export interface Enrollment {
    id: number;
    level: { name: string } | null;
    section: { name: string } | null;
    academic_year?: { name: string } | null;
    status?: string | null;
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
    gender: 'male' | 'female';
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
    per_page?: number;
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
};

export type TransferStudent = {
    id: number;
    student_code: string | null;
    first_name: string;
    last_name: string;
    dob: string | null;
    gender: 'male' | 'female';
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

/** رؤوس بدون Content-Type: مطلوبة لطلبات FormData. */
function authHeaders(): Record<string, string> {
    const token = getToken();
    return {
        'Accept': 'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    };
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
    return Array.isArray(data) ? data : data.data;
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
    const res = await fetch(`${API_BASE}/students/${id}`, { headers: authHeaders() });
    if (!res.ok) throw new Error('حدث خطأ أثناء جلب بيانات التلميذ');
    return res.json();
}

export async function getStudentPaymentHistory(id: number): Promise<StudentPaymentHistoryEntry[]> {
    const res = await fetch(`${API_BASE}/students/${id}/payments`, { headers: authHeaders() });
    if (!res.ok) throw new Error('تعذّر تحميل سجل دفعات التلميذ');
    return res.json();
}

export async function enrollStudent(formData: FormData): Promise<EnrollmentResponse> {
    const res = await fetch(`${API_BASE}/students/enroll`, {
        method: 'POST',
        headers: authHeaders(), // بدون Content-Type — browser يضبطه تلقائياً مع FormData
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
    registration_amount: number;
    payment_method: 'cash' | 'bank_transfer' | 'check' | 'card';
    payment_date: string;
    payment_notes?: string;
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
    const res = await fetch(`${API_BASE}/students/${studentId}/reenroll`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
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
    const res = await fetch(`${API_BASE}/students/${studentId}/registration-payment`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
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
