import { apiFetch } from './http';
import type { AcademicYear } from '../types';

export async function fetchAcademicYears(signal?: AbortSignal): Promise<AcademicYear[]> {
  const raw = await apiFetch<unknown>('/academic-years', {
    signal,
    fallbackMessage: 'تعذّر تحميل السنوات الدراسية',
  });

  const list: any[] = Array.isArray(raw) ? raw : ((raw as any)?.data ?? []);

  return list
    .filter((item) => item && item.id !== undefined)
    .map((item) => ({
      id: Number(item.id),
      name: String(item.name ?? item.label ?? item.title ?? item.id),
      start_date: String(item.start_date ?? ''),
      end_date: String(item.end_date ?? ''),
      is_active: Boolean(item.is_active),
    }));
}

export const fetchYears = fetchAcademicYears;
