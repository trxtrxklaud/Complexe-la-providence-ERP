import { apiFetch } from './http';

export interface Teacher {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  staff_type?: string;
}

export interface Section {
  id: number;
  name: string;
  level: {
    id: number;
    name: string;
  };
  student_count: number;
  teacher_count: number;
}

export interface AssignedSection {
  id: number;
  section: {
    id: number;
    name: string;
    level: {
      id: number;
      name: string;
    };
  };
  subject: string | null;
  created_at: string;
}

export interface TeacherDetailResponse {
  teacher: {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
  };
  sections: AssignedSection[];
}

export interface AssignSectionPayload {
  employee_id: number;
  section_id: number;
  subject?: string | null;
}

export interface AssignResponse {
  success: boolean;
  data: AssignedSection;
  other_teachers: Array<{ name: string; subject: string | null }>;
}

export const fetchTeachers = () =>
  apiFetch<Teacher[]>('/teacher-sections/teachers', {
    fallbackMessage: 'تعذّر تحميل قائمة المعلمين',
    forceRefresh: true,
  });

export const fetchSections = () =>
  apiFetch<Section[]>('/teacher-sections/sections', {
    fallbackMessage: 'تعذّر تحميل قائمة الأقسام',
    forceRefresh: true,
  });

export const fetchTeacherSections = (employeeId: number) =>
  apiFetch<TeacherDetailResponse>(`/teacher-sections/teachers/${employeeId}`, {
    fallbackMessage: 'تعذّر تحميل أقسام المعلم',
    forceRefresh: true,
  });

export const assignTeacherSection = (payload: AssignSectionPayload) =>
  apiFetch<AssignResponse>('/teacher-sections/assign', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر إسناد القسم',
  });

export const removeTeacherSection = (payload: { employee_id: number; section_id: number } | { id: number }) =>
  apiFetch<{ success: boolean; message?: string }>('/teacher-sections/remove', {
    method: 'DELETE',
    body: payload,
    fallbackMessage: 'تعذّر إلغاء إسناد القسم',
  });

export const deleteTeacherSections = (
  employeeId: number,
  replacementEmployeeId?: number | null
) =>
  apiFetch<{ success: boolean; message: string; transferred_count: number }>(
    `/teacher-sections/teachers/${employeeId}`,
    {
      method: 'DELETE',
      body: replacementEmployeeId ? { replacement_employee_id: replacementEmployeeId } : {},
      fallbackMessage: 'تعذّر حذف أقسام المعلم',
    }
  );

