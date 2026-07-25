import { apiFetch } from './http';

export type Section = {
  id: number;
  level_id: number;
  name: string;
  code: string;
  capacity: number;
  active_enrollments_count?: number;
};

export type Level = {
  id: number;
  name: string;
  code: string;
  order: number;
  description: string | null;
  sections: Section[];
};

export type LevelPayload = {
  name: string;
  code: string;
  order: number;
  description?: string | null;
};

export type SectionPayload = {
  level_id: number;
  name: string;
  code: string;
  capacity: number;
};

export const fetchLevels = () =>
  apiFetch<Level[]>('/levels', { fallbackMessage: 'تعذّر تحميل المستويات' });

export const createLevel = (payload: LevelPayload) =>
  apiFetch<Level>('/levels', { method: 'POST', body: payload, fallbackMessage: 'تعذّر إضافة المستوى' });

export const updateLevel = (id: number, payload: LevelPayload) =>
  apiFetch<Level>('/levels/' + id, { method: 'PUT', body: payload, fallbackMessage: 'تعذّر تعديل المستوى' });

export const deleteLevel = (id: number) =>
  apiFetch<{ message: string }>('/levels/' + id, { method: 'DELETE', fallbackMessage: 'تعذّر حذف المستوى' });

export const createSection = (payload: SectionPayload) =>
  apiFetch<Section>('/sections', { method: 'POST', body: payload, fallbackMessage: 'تعذّر إضافة القسم' });

export const updateSection = (id: number, payload: SectionPayload) =>
  apiFetch<Section>('/sections/' + id, { method: 'PUT', body: payload, fallbackMessage: 'تعذّر تعديل القسم' });

export const deleteSection = (id: number) =>
  apiFetch<{ message: string }>('/sections/' + id, { method: 'DELETE', fallbackMessage: 'تعذّر حذف القسم' });
