import { apiFetch, type QueryParams } from './http';
import type { Paginated } from './expenses';

export type { Paginated };

/** سطر واحد في سجل العمليات كما يعيده الخادم (مع علاقة المستخدم المختصرة). */
export type AuditLog = {
  id: number;
  user_id: number | null;
  user_name: string | null;
  action: string;
  model_type: string | null;
  model_id: number | null;
  description: string;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string | null;
  updated_at: string | null;
  user?: { id: number; first_name: string; last_name: string } | null;
};

export type AuditLogFilters = {
  action?: string | null;
  user_id?: number | null;
  model_type?: string | null;
  date_from?: string | null;
  date_to?: string | null;
  page?: number;
};

export function fetchAuditLogs(filters: AuditLogFilters = {}): Promise<Paginated<AuditLog>> {
  return apiFetch<Paginated<AuditLog>>('/audit-logs', {
    params: filters as QueryParams,
    fallbackMessage: 'تعذّر تحميل سجل العمليات',
  });
}
