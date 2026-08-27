import { API_BASE, getHeaders } from './http';
import type { UserPermissionBreakdown } from '../types';

export async function fetchUserPermissionBreakdown(userId: number): Promise<UserPermissionBreakdown> {
  const res = await fetch(`${API_BASE}/users/${userId}/permission-overrides`, {
    headers: getHeaders(),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'فشل تحميل صلاحيات المستخدم.');
  }
  return res.json();
}

export async function setUserPermissionOverride(
  userId: number,
  permissionId: number,
  effect: 'grant' | 'deny'
): Promise<{ message: string; effective_permissions: string[] }> {
  const res = await fetch(`${API_BASE}/users/${userId}/permission-overrides`, {
    method: 'POST',
    headers: getHeaders(),
    body: JSON.stringify({ permission_id: permissionId, effect }),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'فشل تحديث الصلاحية المباشرة.');
  }
  return res.json();
}

export async function updateUserPermissionOverride(
  userId: number,
  permissionId: number,
  effect: 'grant' | 'deny'
): Promise<{ message: string; effective_permissions: string[] }> {
  const res = await fetch(`${API_BASE}/users/${userId}/permission-overrides/${permissionId}`, {
    method: 'PUT',
    headers: getHeaders(),
    body: JSON.stringify({ effect }),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'فشل تحديث الصلاحية المباشرة.');
  }
  return res.json();
}

export async function deleteUserPermissionOverride(
  userId: number,
  permissionId: number
): Promise<{ message: string; effective_permissions: string[] }> {
  const res = await fetch(`${API_BASE}/users/${userId}/permission-overrides/${permissionId}`, {
    method: 'DELETE',
    headers: getHeaders(),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'فشل حذف الاستثناء المباشر.');
  }
  return res.json();
}
