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
}

export interface EnrollmentResponse {
    message: string;
    enrollment: Enrollment;
}

export interface Student {
    id: number;
    student_code: string;
    first_name: string;
    last_name: string;
    gender: 'male' | 'female';
    dob: string;
    guardian_first_name?: string | null;
    guardian_last_name?: string | null;
    guardian_phone?: string | null;
    mother_phone?: string | null;
    guardians: Guardian[];
    enrollments: Enrollment[];
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

export type StudentSearchOptions = {
    levels: Array<{ id: number; label: string }>;
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

export async function enrollStudent(formData: FormData): Promise<EnrollmentResponse> {
    const res = await fetch(`${API_BASE}/students/enroll`, {
        method: 'POST',
        headers: authHeaders(), // بدون Content-Type — browser يضبطه تلقائياً مع FormData
        body: formData,
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'حدث خطأ أثناء التسجيل');
    }
    return res.json();
}

export async function reenrollStudent(studentId: number, data: {
    level_id: number;
    section_name?: string;
    notes?: string;
}): Promise<EnrollmentResponse> {
    const res = await fetch(`${API_BASE}/students/${studentId}/reenroll`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'حدث خطأ أثناء إعادة التسجيل');
    }
    return res.json();
}
