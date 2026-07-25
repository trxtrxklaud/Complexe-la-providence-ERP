export interface FeeType {
    id: number;
    name_ar: string;
    name_fr: string | null;
    price: string;
    is_active: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface FeeTypePayload {
    name_ar: string;
    name_fr?: string | null;
    price: number;
    is_active?: boolean;
}

const API_BASE = '/api';

function getHeaders(): Record<string, string> {
    const token = localStorage.getItem('token');
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    };
}

export async function getFeeTypes(): Promise<FeeType[]> {
    const res = await fetch(`${API_BASE}/fee-types`, { headers: getHeaders() });
    if (!res.ok) throw new Error('فشل جلب أنواع المعاليم');
    return res.json();
}

export async function createFeeType(data: FeeTypePayload): Promise<FeeType> {
    const res = await fetch(`${API_BASE}/fee-types`, {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify(data),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'فشل إنشاء نوع المعلوم');
    }
    return res.json();
}

export async function updateFeeType(id: number, data: FeeTypePayload): Promise<FeeType> {
    const res = await fetch(`${API_BASE}/fee-types/${id}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify(data),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'فشل تحديث نوع المعلوم');
    }
    return res.json();
}

export async function deleteFeeType(id: number): Promise<void> {
    const res = await fetch(`${API_BASE}/fee-types/${id}`, {
        method: 'DELETE',
        headers: getHeaders(),
    });
    if (!res.ok) throw new Error('فشل حذف نوع المعلوم');
}
