import type { User, Role, Permission } from '../types';
export type { User, Role, Permission };

const API_BASE = '/api';

export interface AuthResponse {
    access_token: string;
    token_type: string;
    user: User;
}
export async function login(credentials: { username: string; password: string }): Promise<AuthResponse> {
    const res = await fetch(`${API_BASE}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(credentials),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'فشل تسجيل الدخول');
    }
    return res.json();
}
export async function logout(): Promise<void> {
    const token = localStorage.getItem('token');
    if (!token) return;
    await fetch(`${API_BASE}/logout`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
    });
}
export async function fetchCurrentUser(): Promise<User> {
    const token = localStorage.getItem('token');
    const res = await fetch(`${API_BASE}/user`, {
        headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
    });
    if (!res.ok) throw new Error('فشل جلب بيانات المستخدم');
    return res.json();
}
