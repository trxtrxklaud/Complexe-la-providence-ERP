import { type FormEvent, useEffect, useState } from 'react';
import { Search, Printer, GraduationCap, Users, UserRound, HelpCircle } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  getStudentsFullResponse,
  getStudentSearchOptions,
  type Student,
  type StudentSearchFilters,
  type StudentSearchOptions,
  type StudentSearchResponse,
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
    gender: params.get('gender') ?? 'all',
  };
}

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  rose: '#F1E4E2',
  beige: '#EFEAE0',
  ink: '#1F261C',
  muted: '#7C8677',
};

function StudentCountCard({
  label,
  count,
  total,
  icon: Icon,
  tint,
  iconColor,
}: {
  label: string;
  count: number;
  total?: number;
  icon: any;
  tint: string;
  iconColor: string;
}) {
  const percentage = total && total > 0 && count > 0 ? `(${((count / total) * 100).toFixed(1)}%)` : null;

  return (
    <div className="rounded-2xl p-4 shadow-sm border border-slate-200/80" style={{ backgroundColor: tint }}>
      <div className="flex items-center justify-between">
        <span className="text-xs font-semibold" style={{ color: C.muted }}>{label}</span>
        <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-white/80" style={{ color: iconColor }}>
          <Icon size={16} />
        </div>
      </div>
      <div className="mt-2 flex items-baseline gap-2">
        <span className="text-2xl font-black text-slate-800">{count}</span>
        {percentage && <span className="text-xs font-medium text-slate-500">{percentage}</span>}
      </div>
    </div>
  );
}

export function StudentSearchPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const queryString = searchParams.toString();
  const [filters, setFilters] = useState(() => filtersFromParams(searchParams));
  const [students, setStudents] = useState<Student[]>([]);
  const [counts, setCounts] = useState({ total: 0, males: 0, females: 0, unknown: 0 });
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

    const currentFilters = filtersFromParams(new URLSearchParams(queryString));

    getStudentsFullResponse({ ...currentFilters, per_page: 100 }, controller.signal)
      .then((res: StudentSearchResponse) => {
        if (!controller.signal.aborted) {
          setStudents(res.data);
          setCounts({
            total: res.total_count ?? res.data.length,
            males: res.male_count ?? 0,
            females: res.female_count ?? 0,
            unknown: res.unknown_count ?? 0,
          });
        }
      })
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
      if (normalized && (name !== 'gender' || normalized !== 'all')) {
        nextParams.set(name, normalized);
      }
    });

    setSearchParams(nextParams);
    setSubmitVersion((version) => version + 1);
  }

  function handleFilterChange(key: string, value: string) {
    const nextFilters = { ...filters, [key]: value };
    const nextParams = new URLSearchParams();

    Object.entries(nextFilters).forEach(([name, val]) => {
      const normalized = String(val).trim();
      if (normalized && (name !== 'gender' || normalized !== 'all')) {
        nextParams.set(name, normalized);
      }
    });

    setFilters(nextFilters);
    setSearchParams(nextParams);
    setSubmitVersion((version) => version + 1);
  }

  function handlePrint() {
    window.print();
  }

  const selectedSectionLabel = options.levels.find((l) => String(l.id) === String(filters.level))?.label || 'جميع الأقسام';
  const selectedYearLabel = options.years.find((y) => String(y.id) === String(filters.year))?.name || 'السنة الدراسية الحالية';
  const genderFilterLabel = filters.gender === 'male' ? 'ذكور' : filters.gender === 'female' ? 'إناث' : filters.gender === 'unknown' ? 'غير محدد' : 'الكل';

  const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10';

  return (
    <div className="mx-auto max-w-7xl p-6 md:p-8" dir="rtl">
      {/* Print Styles */}
      <style>{`
        @media print {
          @page { size: A4 portrait; margin: 12mm; }
          body { background: white !important; font-size: 11pt !important; color: black !important; }
          .no-print, header, nav, sidebar, button, form, .no-print * { display: none !important; }
          .print-only { display: block !important; }
          .print-container { padding: 0 !important; margin: 0 !important; width: 100% !important; max-width: none !important; }
          table { width: 100% !important; border-collapse: collapse !important; margin-top: 15px !important; }
          th, td { border: 1px solid #CBD5E1 !important; padding: 6px 8px !important; font-size: 10pt !important; text-align: right !important; }
          th { background-color: #F8FAFC !important; font-weight: bold !important; }
        }
        .print-only { display: none; }
      `}</style>

      {/* Printable Header */}
      <div className="print-only mb-6 border-b border-slate-300 pb-4">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-slate-900">مدرسة العناية — قائمة التلاميذ</h1>
            <p className="text-sm text-slate-600 mt-1">
              القسم: <strong>{selectedSectionLabel}</strong> | السنة: <strong>{selectedYearLabel}</strong> | تصفية الجنس: <strong>{genderFilterLabel}</strong>
            </p>
          </div>
          <div className="text-left text-sm text-slate-500">
            <p>التاريخ: {new Date().toLocaleDateString('ar-TN')}</p>
          </div>
        </div>

        <div className="mt-4 flex gap-6 text-sm bg-slate-50 p-3 rounded-lg border border-slate-200">
          <span>إجمالي التلاميذ: <strong>{counts.total}</strong></span>
          <span>الذكور: <strong>{counts.males}</strong></span>
          <span>الإناث: <strong>{counts.females}</strong></span>
          {counts.unknown > 0 && <span>غير محدد: <strong>{counts.unknown}</strong></span>}
        </div>
      </div>

      <div className="no-print mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">البحث عن تلميذ وقائمة الأقسام</h1>
          <p className="mt-1 text-sm text-slate-500">جرد وقائمة التلاميذ مع التصفية حسب الجنس والأقسام والسنوات الدراسية.</p>
        </div>

        <button
          type="button"
          onClick={handlePrint}
          className="inline-flex items-center justify-center gap-2 rounded-xl bg-white border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:border-slate-400"
        >
          <Printer size={17} />
          <span>طباعة القائمة (A4)</span>
        </button>
      </div>

      {/* Summary Cards */}
      <div className="no-print grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <StudentCountCard
          label="إجمالي التلاميذ"
          count={counts.total}
          icon={GraduationCap}
          tint={C.sage}
          iconColor={C.forest}
        />
        <StudentCountCard
          label="عدد الذكور"
          count={counts.males}
          total={counts.total}
          icon={Users}
          tint={C.beige}
          iconColor="#8A7C57"
        />
        <StudentCountCard
          label="عدد الإناث"
          count={counts.females}
          total={counts.total}
          icon={UserRound}
          tint={C.rose}
          iconColor="#A46E67"
        />
        <StudentCountCard
          label="غير محدد"
          count={counts.unknown}
          total={counts.total}
          icon={HelpCircle}
          tint={C.beige}
          iconColor={C.muted}
        />
      </div>

      {/* Search & Filter Form */}
      <div className="no-print mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <form action="/students/search" method="get" onSubmit={handleSubmit}>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>القسم</span>
              <select name="level" value={filters.level} onChange={(event) => handleFilterChange('level', event.target.value)} className={inputClass}>
                <option value="">جميع الأقسام</option>
                {options.levels.map((level) => <option key={level.id} value={level.id}>{level.label}</option>)}
              </select>
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>تصفية حسب الجنس</span>
              <select name="gender" value={filters.gender} onChange={(event) => handleFilterChange('gender', event.target.value)} className={inputClass}>
                <option value="all">الكل (جميع الجنسين)</option>
                <option value="male">ذكور فقط</option>
                <option value="female">إناث فقط</option>
                <option value="unknown">غير محدد فقط</option>
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
              <select name="year" value={filters.year} onChange={(event) => handleFilterChange('year', event.target.value)} className={inputClass}>
                <option value="">السنة الدراسية الحالية</option>
                {options.years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
              </select>
            </label>

            <label className="space-y-1.5 text-sm font-medium text-slate-700">
              <span>CNTE</span>
              <input type="text" name="cnte" value={filters.cnte} onChange={(event) => setFilters({ ...filters, cnte: event.target.value })} className={inputClass} dir="ltr" />
            </label>

            <div className="flex items-end gap-2">
              <button type="submit" className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#3B4A36] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#2E3B2A] focus:outline-none focus:ring-2 focus:ring-[#3B4A36]/30">
                <Search size={17} />
                <span>تطبيق التصفية</span>
              </button>
            </div>
          </div>
        </form>
      </div>

      {error && <div className="no-print mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>}

      <div className="print-container overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="no-print flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 className="font-bold text-slate-800">قائمة التلاميذ ({students.length})</h2>
          {!loading && <span className="text-xs text-slate-500">المعروض: {students.length} من أصل {counts.total} تلميذ</span>}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th className="px-5 py-3 font-semibold">CNTE</th>
                <th className="px-5 py-3 font-semibold">الاسم الكامل</th>
                <th className="px-5 py-3 font-semibold">الجنس</th>
                <th className="px-5 py-3 font-semibold">تاريخ الولادة</th>
                <th className="px-5 py-3 font-semibold">الأب / الولي</th>
                <th className="px-5 py-3 font-semibold">الأم</th>
                <th className="px-5 py-3 font-semibold">الاتصال</th>
                <th className="px-5 py-3 font-semibold">العنوان</th>
                <th className="px-5 py-3 font-semibold">القسم والسنة</th>
                <th className="px-5 py-3 font-semibold">الحالة</th>
                <th className="no-print px-5 py-3 font-semibold">ملاحظات</th>
                <th className="no-print px-5 py-3 font-semibold">التفاصيل</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <TableRowsSkeleton columns={12} />
              ) : students.length === 0 ? (
                <tr><td colSpan={12} className="px-5 py-10 text-center text-slate-500">لا توجد نتائج مطابقة للتصفية المختارة.</td></tr>
              ) : students.map((student) => {
                const enrollment = student.enrollments?.[0];
                const section = enrollment?.section?.name;
                const level = enrollment?.level?.name;
                const genderDisplay = student.gender === 'female' || (student as any).gender === 'أنثى' ? 'أنثى' : (student.gender === 'male' || (student as any).gender === 'ذكر' ? 'ذكر' : 'غير محدد');

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
                    <td className="px-5 py-3 font-medium">
                      <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-semibold ${
                        genderDisplay === 'أنثى' ? 'bg-[#F1E4E2] text-[#A46E67]' : genderDisplay === 'ذكر' ? 'bg-[#EFEAE0] text-[#8A7C57]' : 'bg-slate-100 text-slate-600'
                      }`}>
                        {genderDisplay}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{student.dob ? new Date(student.dob).toLocaleDateString('ar-TN') : '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{[student.guardians?.[0]?.first_name || student.guardian_first_name, student.guardians?.[0]?.last_name || student.guardian_last_name].filter(Boolean).join(' ') || '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{student.mother_name || '—'}</td>
                    <td className="px-5 py-3 text-slate-600" dir="ltr">
                      <div>{student.guardians?.[0]?.phone || student.guardian_phone || student.mother_phone || '—'}</div>
                      <div className="text-xs text-slate-400 no-print">{student.guardian_email || student.mother_email || '—'}</div>
                    </td>
                    <td className="max-w-xs px-5 py-3 text-slate-600">{student.address || '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{[level, section, enrollment?.academic_year?.name].filter(Boolean).join(' — ') || '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{student.status || enrollment?.status || '—'}</td>
                    <td className="no-print max-w-xs px-5 py-3 text-slate-600">{student.notes || '—'}</td>
                    <td className="no-print px-5 py-3">
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
