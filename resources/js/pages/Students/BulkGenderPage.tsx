import { useEffect, useMemo, useState } from 'react';
import { Users2 } from 'lucide-react';
import { apiFetch, ApiError } from '../../api/http';

type Gender = 'male' | 'female';

type StudentRow = {
  id: number;
  student_code: string | null;
  first_name: string;
  last_name: string;
  gender: Gender | 'unknown' | null;
  enrollments?: Array<{
    section?: { name: string } | null;
    level?: { name: string } | null;
  }>;
};

type Section = {
  id: number;
  name: string;
  level?: { name: string } | null;
};

type PaginatedStudents = {
  data: StudentRow[];
  current_page: number;
  last_page: number;
};

type RowState = {
  saving: boolean;
  error: string;
};

const selectClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-[#2E3B2A] focus:ring-2 focus:ring-[#2E3B2A]/15';

function requestErrorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.firstError;
  if (error instanceof Error && error.message) return error.message;
  return 'تعذّر حفظ الجنس. حاول مرة أخرى.';
}

export function BulkGenderPage() {
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [sectionId, setSectionId] = useState('');
  const [unsetOnly, setUnsetOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [rowStates, setRowStates] = useState<Record<number, RowState>>({});

  useEffect(() => {
    const controller = new AbortController();

    apiFetch<Section[]>('/sections', {
      signal: controller.signal,
      fallbackMessage: 'تعذّر تحميل قائمة الأقسام',
    })
      .then(setSections)
      .catch((error) => {
        if (!controller.signal.aborted) setLoadError(requestErrorMessage(error));
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setLoadError('');
    setPage(1);
    setLastPage(1);
    setStudents([]);
    setRowStates({});

    const params: Record<string, string | number> = { level: sectionId, per_page: 100, page: 1 };
    if (unsetOnly) {
      params.gender = 'unknown';
    }

    apiFetch<PaginatedStudents>('/students', {
      params,
      signal: controller.signal,
      fallbackMessage: 'تعذّر تحميل قائمة التلاميذ',
    })
      .then((response) => {
        setStudents(response.data);
        setPage(response.current_page);
        setLastPage(response.last_page);
      })
      .catch((error) => {
        if (!controller.signal.aborted) setLoadError(requestErrorMessage(error));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [sectionId, unsetOnly]);

  const unsetCount = useMemo(
    () => students.reduce((count, student) => count + (student.gender === null || student.gender === 'unknown' ? 1 : 0), 0),
    [students],
  );

  const visibleStudents = useMemo(
    () => (unsetOnly
      ? students.filter((student) => student.gender === null || student.gender === 'unknown' || rowStates[student.id] !== undefined)
      : students),
    [rowStates, students, unsetOnly],
  );

  async function loadMore() {
    const nextPage = page + 1;
    setLoadingMore(true);
    setLoadError('');

    try {
      const params: Record<string, string | number> = { level: sectionId, per_page: 100, page: nextPage };
      if (unsetOnly) {
        params.gender = 'unknown';
      }

      const response = await apiFetch<PaginatedStudents>('/students', {
        params,
        fallbackMessage: 'تعذّر تحميل المزيد من التلاميذ',
      });
      setStudents((current) => {
        const existingIds = new Set(current.map((student) => student.id));
        return [...current, ...response.data.filter((student) => !existingIds.has(student.id))];
      });
      setPage(response.current_page);
      setLastPage(response.last_page);
    } catch (error) {
      setLoadError(requestErrorMessage(error));
    } finally {
      setLoadingMore(false);
    }
  }

  async function setGender(studentId: number, gender: Gender) {
    const student = students.find((item) => item.id === studentId);
    if (!student || rowStates[studentId]?.saving) return;

    const previousGender = student.gender;
    setStudents((current) => current.map((item) => (
      item.id === studentId ? { ...item, gender } : item
    )));
    setRowStates((current) => ({ ...current, [studentId]: { saving: true, error: '' } }));

    try {
      await apiFetch(`/students/${studentId}`, {
        method: 'PUT',
        body: { gender },
        fallbackMessage: 'تعذّر حفظ الجنس',
      });
      setRowStates((current) => ({ ...current, [studentId]: { saving: false, error: '' } }));
    } catch (error) {
      setStudents((current) => current.map((item) => (
        item.id === studentId ? { ...item, gender: previousGender } : item
      )));
      setRowStates((current) => ({
        ...current,
        [studentId]: { saving: false, error: requestErrorMessage(error) },
      }));
    }
  }

  function sectionName(student: StudentRow): string {
    const enrollment = student.enrollments?.[0];
    return [enrollment?.level?.name, enrollment?.section?.name].filter(Boolean).join(' — ') || '—';
  }

  return (
    <div className="mx-auto max-w-7xl p-6 md:p-8" dir="rtl">
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#DCE5D5] text-[#2E3B2A]">
            <Users2 size={22} />
          </div>
          <h1 className="text-2xl font-bold text-slate-800">تحديد جنس التلاميذ</h1>
        </div>
        <span className="rounded-full bg-amber-100 px-4 py-2 text-sm font-bold text-amber-800">
          {unsetCount} غير محدد
        </span>
      </div>

      <div className="mb-5 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:flex-row md:items-end md:justify-between">
        <label className="w-full space-y-1.5 text-sm font-medium text-slate-700 md:max-w-sm">
          <span>القسم</span>
          <select value={sectionId} onChange={(event) => setSectionId(event.target.value)} className={selectClass}>
            <option value="">كل الأقسام</option>
            {sections.map((section) => (
              <option key={section.id} value={section.id}>
                {[section.level?.name, section.name].filter(Boolean).join(' — ')}
              </option>
            ))}
          </select>
        </label>

        <div className="inline-flex w-full rounded-xl bg-slate-100 p-1 md:w-auto" aria-label="تصفية التلاميذ">
          <button
            type="button"
            onClick={() => setUnsetOnly(false)}
            className={`flex-1 rounded-lg px-5 py-2 text-sm font-semibold transition md:flex-none ${!unsetOnly ? 'bg-white text-[#2E3B2A] shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
          >
            الكل
          </button>
          <button
            type="button"
            onClick={() => setUnsetOnly(true)}
            className={`flex-1 rounded-lg px-5 py-2 text-sm font-semibold transition md:flex-none ${unsetOnly ? 'bg-white text-[#2E3B2A] shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
          >
            غير المحددون فقط
          </button>
        </div>
      </div>

      {loadError && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{loadError}</div>}

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th className="px-5 py-3 font-semibold">CNTE</th>
                <th className="px-5 py-3 font-semibold">الاسم الكامل</th>
                <th className="px-5 py-3 font-semibold">القسم</th>
                <th className="px-5 py-3 font-semibold">الجنس</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                Array.from({ length: 6 }, (_, index) => (
                  <tr key={index} className="animate-pulse">
                    <td colSpan={4} className="px-5 py-4"><div className="h-7 rounded-lg bg-slate-100" /></td>
                  </tr>
                ))
              ) : visibleStudents.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-5 py-12 text-center text-slate-500">
                    {unsetOnly ? 'لا يوجد تلاميذ دون جنس محدد.' : 'لا يوجد تلاميذ في هذا القسم.'}
                  </td>
                </tr>
              ) : visibleStudents.map((student) => {
                const rowState = rowStates[student.id];
                const unset = student.gender === null || student.gender === 'unknown';
                return (
                  <tr key={student.id} className={unset ? 'bg-amber-50/80' : 'bg-white hover:bg-slate-50/70'}>
                    <td className="px-5 py-3 font-medium text-slate-700" dir="ltr">{student.student_code || '—'}</td>
                    <td className="px-5 py-3 font-semibold text-slate-800">{student.first_name} {student.last_name}</td>
                    <td className="px-5 py-3 text-slate-600">{sectionName(student)}</td>
                    <td className="px-5 py-3">
                      <div className="flex flex-wrap items-center gap-2">
                        <button
                          type="button"
                          disabled={rowState?.saving}
                          onClick={() => setGender(student.id, 'male')}
                          className={`rounded-lg border px-4 py-1.5 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60 ${student.gender === 'male' ? 'border-[#2E3B2A] bg-[#2E3B2A] text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-[#2E3B2A]/40 hover:text-[#2E3B2A]'}`}
                        >
                          ذكر
                        </button>
                        <button
                          type="button"
                          disabled={rowState?.saving}
                          onClick={() => setGender(student.id, 'female')}
                          className={`rounded-lg border px-4 py-1.5 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60 ${student.gender === 'female' ? 'border-rose-600 bg-rose-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-rose-300 hover:text-rose-700'}`}
                        >
                          أنثى
                        </button>
                        {rowState?.saving && <span className="text-xs text-slate-500">جارٍ الحفظ...</span>}
                      </div>
                      {rowState?.error && <p className="mt-1.5 text-xs font-medium text-red-600">{rowState.error}</p>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {!loading && page < lastPage && (
          <div className="border-t border-slate-100 p-4 text-center">
            <button
              type="button"
              onClick={loadMore}
              disabled={loadingMore}
              className="rounded-xl bg-[#2E3B2A] px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-[#26311F] disabled:cursor-wait disabled:opacity-60"
            >
              {loadingMore ? 'جارٍ التحميل...' : 'تحميل المزيد'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
