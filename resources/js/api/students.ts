import type { User } from '../types';

export interface Guardian {
    first_name: string;
    last_name: string;
    phone: string;
    relationship?: string;
}

export interface Enrollment {
    id: number;
    level: { name: string };
    section: { name: string };
    academicYear: { name: string };
}

export interface Student {
    id: number;
    student_code: string;
    first_name: string;
    last_name: string;
    gender: 'male' | 'female';
    dob: string;
    guardians: Guardian[];
    enrollments: Enrollment[];
}

const API_BASE = '/api';

function getHeaders(): Record<string, string> {
    const token = localStorage.getItem('token');
    return {
        'Accept': 'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    };
}

export async function getStudents(): Promise<Student[]> {
    const res = await fetch(`${API_BASE}/students`, { headers: getHeaders() });
    if (!res.ok) throw new Error('حدث خطأ أثناء جلب قائمة التلاميذ');
    const data = await res.json();
    return data.data ?? data;
}

export async function getStudent(id: number): Promise<Student> {
    const res = await fetch(`${API_BASE}/students/${id}`, { headers: getHeaders() });
    if (!res.ok) throw new Error('حدث خطأ أثناء جلب بيانات التلميذ');
    return res.json();
}

export async function enrollStudent(formData: FormData): Promise<Student> {
    const res = await fetch(`${API_BASE}/students/enroll`, {
        method: 'POST',
        headers: getHeaders(), // بدون Content-Type — browser يضبطه تلقائياً مع FormData
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
    section_id: number;
    academic_year_id: number;
}): Promise<Student> {
    const res = await fetch(`${API_BASE}/students/${studentId}/reenroll`, {
        method: 'POST',
        headers: { ...getHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'حدث خطأ أثناء إعادة التسجيل');
    }
    return res.json();
}
