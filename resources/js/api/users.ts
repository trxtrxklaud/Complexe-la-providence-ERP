import type { User, Role } from '../types';
export type { User, Role };

const API_BASE = '/api';

function getHeaders() {
    const token = localStorage.getItem('token');
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
    };
}
export async function fetchUsers(): Promise<User[]> {
    const res = await fetch(`${API_BASE}/users`, { headers: getHeaders() });
    if (!res.ok) throw new Error('Failed to fetch users');
    const data = await res.json();
    return data.data ?? data;
}
export async function fetchUser(id: number): Promise<User> {
    const res = await fetch(`${API_BASE}/users/${id}`, { headers: getHeaders() });
    if (!res.ok) throw new Error('Failed to fetch user');
    return res.json();
}
export async function createUser(data: Partial<User> & { password?: string; password_confirmation?: string }): Promise<User> {
    const res = await fetch(`${API_BASE}/users`, {
        method: 'POST',
        headers: getHeaders(),
        body: JSON.stringify(data),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Failed to create user');
    }
    return res.json();
}
export async function updateUser(id: number, data: Partial<User> & { password?: string; password_confirmation?: string }): Promise<User> {
    const res = await fetch(`${API_BASE}/users/${id}`, {
        method: 'PUT',
        headers: getHeaders(),
        body: JSON.stringify(data),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Failed to update user');
    }
    return res.json();
}
export async function deleteUser(id: number): Promise<void> {
    const res = await fetch(`${API_BASE}/users/${id}`, {
        method: 'DELETE',
        headers: getHeaders(),
    });
    if (!res.ok) throw new Error('Failed to delete user');
}
export async function fetchRoles(): Promise<Role[]> {
    const res = await fetch(`${API_BASE}/roles`, { headers: getHeaders() });
    if (!res.ok) throw new Error('Failed to fetch roles');
    return res.json();
}
