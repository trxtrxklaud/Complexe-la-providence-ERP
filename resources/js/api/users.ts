export interface Role {
  id: number;
  name: string;
  display_name: string;
}

export interface User {
  id: number;
  first_name: string;
  last_name: string;
  username: string;
  email: string;
  phone: string | null;
  role_id: number;
  role?: Role;
}

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
  return res.json();
}

export async function fetchUser(id: number): Promise<User> {
  const res = await fetch(`${API_BASE}/users/${id}`, { headers: getHeaders() });
  if (!res.ok) throw new Error('Failed to fetch user');
  return res.json();
}

export async function createUser(data: Partial<User> & { password?: string, password_confirmation?: string }): Promise<User> {
  const res = await fetch(`${API_BASE}/users`, {
    method: 'POST',
    headers: getHeaders(),
    body: JSON.stringify(data),
  });
  if (!res.ok) {
    const errorData = await res.json().catch(() => ({}));
    throw new Error(errorData.message || 'Failed to create user');
  }
  return res.json();
}

export async function updateUser(id: number, data: Partial<User> & { password?: string, password_confirmation?: string }): Promise<User> {
  const res = await fetch(`${API_BASE}/users/${id}`, {
    method: 'PUT',
    headers: getHeaders(),
    body: JSON.stringify(data),
  });
  if (!res.ok) {
    const errorData = await res.json().catch(() => ({}));
    throw new Error(errorData.message || 'Failed to update user');
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
