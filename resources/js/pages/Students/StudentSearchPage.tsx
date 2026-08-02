import { type FormEvent, useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  getStudents,
  getStudentSearchOptions,
  type Student,
  type StudentSearchFilters,
  type StudentSearchOptions,
} from '../../api/students';
import { TableRowsSkeleton } from '../../components/DataSkeleton';

function filtersFromParams(params: URLSearchParams) {
  return {
    level: params.get('level') ?? '',
    student_name: params.get('student_name') ?? '',
    phone: params.get('phone') ?? '',
    birthday: params.get('birthday') ?? '',
    year: params.get('year') ?? '',
    cnte: params.get('cnte') ?? '',
  };
}

export function StudentSearchPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const queryString = searchParams.toString();
  const [filters, setFilters] = useState(() => filtersFromParams(searchParams));
  const [students, setStudents] = useState<Student[]>([]);
  const [options, setOptions] = useState<StudentSearchOptions>({ levels: [], years: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [submitVersion, setSubmitVersion] = useState(0);

  useEffect(() => {
    setFilters(filtersFromParams(new URLSearchParams(queryString)));
  }, [queryString]);

  useEffect(() => {
    const controller = new AbortController();
    getStudentSearchOptions(controller.signal)
      .then(setOptions)
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(requestError instanceof Error ? requestError.message : 'تعذّر تحميل خيارات البحث');
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');

    getStudents({ ...filtersFromParams(new URLSearchParams(queryString)), per_page: 100 }, controller.signal)
      .then(setStudents)
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(requestError instanceof Error ? requestError.message : 'تعذّر البحث عن التلاميذ');
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [queryString, submitVersion]);

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const nextParams = new URLSearchParams();

    Object.entries(filters).forEach(([name, value]) => {
      const normalized = String(value).trim();
      if (normalized) nextParams.set(name, normalized);
    });

    setSearchParams(nextParams);
    setSubmitVersion((version) => version + 1);
  }

  function handleLevelChange(level: string) {
    const nextFilters = {
      ...filters,
      level,
      student_name: '',
      phone: '',
      birthday: '',
      cnte: '',
    };
    const nextParams = new URLSearchParams();

    if (level) nextParams.set('level', level);
    if (nextFilters.year) nextParams.set('year', nextFilters.year);

    setFilters(nextFilters);
    setSearchParams(nextParams);
    setSubmitVersion((version) => version + 1);
  }

  const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10';

  return (
    <div className="mx-auto max-w-7xl p-6 md:p-8" dir="rtl">
      <div className="mb-5">
        <h1 className="text-2xl font-bold text-slate-800">البحث عن تلميذ</h1>
        <p className="mt-1 text-sm text-slate-500">ابحث حسب القسم أو الاسم أو الهاتف أو تاريخ الولادة أو السنة الدراسية أو CNTE.</p>
      </div>

      <div className="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <form action="/students/search" method="get" onSubmit={handleSubmit}>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>القسم</span>
              <select name="level" value={filters.level} onChange={(event) => handleLevelChange(event.target.value)} className={inputClass}>
                <option value="">حدد القسم</option>
                {options.levels.map((level) => <option key={level.id} value={level.id}>{level.label}</option>)}
              </select>
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>اسم التلميذ</span>
              <input type="text" name="student_name" value={filters.student_name} onChange={(event) => setFilters({ ...filters, student_name: event.target.value })} className={inputClass} />
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>رقم هاتف الأب أو الأم</span>
              <input type="tel" inputMode="numeric" name="phone" value={filters.phone} onChange={(event) => setFilters({ ...filters, phone: event.target.value })} className={inputClass} dir="ltr" />
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>تاريخ الولادة</span>
              <input type="date" name="birthday" value={filters.birthday} onChange={(event) => setFilters({ ...filters, birthday: event.target.value })} className={inputClass} />
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>السنة الدراسية</span>
              <select name="year" value={filters.year} onChange={(event) => setFilters({ ...filters, year: event.target.value })} className={inputClass}>
                <option value="">اختر السنة الدراسية</option>
                {options.years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
              </select>
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>CNTE</span>
              <input type="text" name="cnte" value={filters.cnte} onChange={(event) => setFilters({ ...filters, cnte: event.target.value })} className={inputClass} dir="ltr" />
            </label>

            <div className="flex items-end">
              <button type="submit" className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#3B4A36] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#2E3B2A] focus:outline-none focus:ring-2 focus:ring-[#3B4A36]/30 md:w-auto">
                <Search size={17} />
                <span>إبحث</span>
              </button>
            </div>
          </div>
        </form>
      </div>

      {error && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>}

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-800">نتائج البحث</h2>
          {!loading && <span className="text-xs text-slate-500">{students.length} تلميذ</span>}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th className="px-5 py-3 font-semibold">CNTE</th>
                <th className="px-5 py-3 font-semibold">الاسم الكامل</th>
                <th className="px-5 py-3 font-semibold">القسم</th>
                <th className="px-5 py-3 font-semibold">تاريخ الولادة</th>
                <th className="px-5 py-3 font-semibold">الهاتف</th>
                <th className="px-5 py-3 font-semibold">التفاصيل</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <TableRowsSkeleton columns={6} />
              ) : students.length === 0 ? (
                <tr><td colSpan={6} className="px-5 py-10 text-center text-slate-500">لا توجد نتائج مطابقة.</td></tr>
              ) : students.map((student) => {
                const enrollment = student.enrollments?.[0];
                const section = enrollment?.section?.name;
                const level = enrollment?.level?.name;
                return (
                  <tr key={student.id} className="hover:bg-slate-50/70">
                    <td className="px-5 py-3 font-medium text-slate-700" dir="ltr">{student.student_code || '—'}</td>
                    <td className="px-5 py-3 text-slate-800">
                      <Link
                        to={`/students/search/${student.id}${queryString ? `?${queryString}` : ''}`}
                        className="font-semibold text-[#3B4A36] hover:underline"
                      >
                        {student.first_name} {student.last_name}
                      </Link>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{[level, section].filter(Boolean).join(' ') || '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{student.dob ? new Date(student.dob).toLocaleDateString('ar-TN') : '—'}</td>
                    <td className="px-5 py-3 text-slate-600" dir="ltr">{student.guardians?.[0]?.phone || student.guardian_phone || student.mother_phone || '—'}</td>
                    <td className="px-5 py-3">
                      <Link to={`/students/search/${student.id}${queryString ? `?${queryString}` : ''}`} className="inline-flex rounded-lg bg-[#E3EBDB] px-3 py-1.5 text-xs font-semibold text-[#3B4A36] hover:bg-[#D5E1CC]">
                        عرض التفاصيل
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
