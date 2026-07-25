import { apiFetch } from './http';

export type AcademicYear = {
  id: number;
  name: string;
  is_active?: boolean;
};

export type RosterStudent = {
  enrollment_id: number;
  student_id: number;
  student_code: string | null;
  first_name: string;
  last_name: string;
  father_name: string | null;
  mother_name: string | null;
  father_phone: string | null;
  mother_phone: string | null;
};

export type RosterResponse = {
  year: string;
  level: string;
  section: string;
  capacity: number;
  students: RosterStudent[];
};

export type BulkResult = {
  created: number;
  skipped: string[];
  message: string;
};

export const fetchYears = async (): Promise<AcademicYear[]> => {
  const data = await apiFetch<AcademicYear[] | { data: AcademicYear[] }>('/academic-years', {
    fallbackMessage: 'تعذّر تحميل السنوات الدراسية',
  });
  if (Array.isArray(data)) return data;
  return data?.data ?? [];
};

export const fetchRoster = (academicYearId: number, sectionId: number) =>
  apiFetch<RosterResponse>('/rosters', {
    params: { academic_year_id: academicYearId, section_id: sectionId },
    fallbackMessage: 'تعذّر تحميل قائمة القسم',
  });

export const bulkEnroll = (payload: { academic_year_id: number; section_id: number; names: string[] }) =>
  apiFetch<BulkResult>('/rosters/bulk', {
    method: 'POST',
    body: payload,
    fallbackMessage: 'تعذّر تسجيل التلاميذ',
  });

export const removeFromRoster = (enrollmentId: number) =>
  apiFetch<{ message: string }>('/rosters/' + enrollmentId, {
    method: 'DELETE',
    fallbackMessage: 'تعذّر حذف التسجيل',
  });
