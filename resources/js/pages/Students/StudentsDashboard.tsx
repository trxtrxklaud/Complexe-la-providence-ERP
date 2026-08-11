import React, { useState, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { 
  Users, UserPlus, Search, ArrowRightLeft, CreditCard,
  GraduationCap, UserRound, HelpCircle, Printer, Filter
} from 'lucide-react';
import {
  getStudentsFullResponse,
  getStudentSearchOptions,
  type Student,
  type StudentSearchOptions,
  type StudentSearchResponse,
} from '../../api/students';
import { TableRowsSkeleton } from '../../components/DataSkeleton';

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
    <div className="rounded-2xl p-5 shadow-sm border border-slate-200/80 flex items-center justify-between" style={{ backgroundColor: tint }}>
      <div>
        <p className="text-xs font-semibold text-slate-600 mb-1">{label}</p>
        <div className="flex items-baseline gap-2">
          <span className="text-2xl font-black text-slate-800">{count}</span>
          {percentage && <span className="text-xs font-medium text-slate-500">{percentage}</span>}
        </div>
      </div>
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/80" style={{ color: iconColor }}>
        <Icon size={20} />
      </div>
    </div>
  );
}

export function StudentsDashboard() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [students, setStudents] = useState<Student[]>([]);
  const [counts, setCounts] = useState({ total: 0, males: 0, females: 0, unknown: 0 });
  const [options, setOptions] = useState<StudentSearchOptions>({ levels: [], years: [] });
  const [genderFilter, setGenderFilter] = useState(() => searchParams.get('gender') || 'all');
  const [sectionFilter, setSectionFilter] = useState(() => searchParams.get('level') || '');
  const [yearFilter, setYearFilter] = useState(() => searchParams.get('year') || '');
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    getStudentSearchOptions(controller.signal)
      .then(setOptions)
      .catch(() => {});
    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setIsLoading(true);

    const queryGender = searchParams.get('gender') || 'all';
    const querySection = searchParams.get('level') || '';
    const queryYear = searchParams.get('year') || '';

    setGenderFilter(queryGender);
    setSectionFilter(querySection);
    setYearFilter(queryYear);

    getStudentsFullResponse({
      gender: queryGender,
      level: querySection,
      year: queryYear,
      per_page: 100,
    }, controller.signal)
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
      .catch((err) => {
        if (!controller.signal.aborted) console.error('Failed to load students:', err);
      })
      .finally(() => {
        if (!controller.signal.aborted) setIsLoading(false);
      });

    return () => controller.abort();
  }, [searchParams]);

  function updateFilter(key: string, value: string) {
    const nextParams = new URLSearchParams(searchParams);
    if (value && value !== 'all') {
      nextParams.set(key, value);
    } else {
      nextParams.delete(key);
    }
    setSearchParams(nextParams);
  }

  function handlePrint() {
    window.print();
  }

  const actionCards = [
    { title: 'ترسيم التلاميذ', icon: UserPlus, link: '/students/enroll', color: 'bg-blue-500' },
    { title: 'بحث متقدم', icon: Search, link: '/students/search', color: 'bg-indigo-500' },
    { title: 'نقل التلاميذ', icon: ArrowRightLeft, link: '/students/transfer', color: 'bg-orange-500' },
    { title: 'قائمة التلاميذ حسب حالة السداد', icon: CreditCard, link: '/students/payment-status', color: 'bg-rose-500' },
  ];

  const selectedSectionLabel = options.levels.find((l) => String(l.id) === String(sectionFilter))?.label || 'جميع الأقسام';
  const selectedYearLabel = options.years.find((y) => String(y.id) === String(yearFilter))?.name || 'السنة الدراسية الحالية';
  const genderLabel = genderFilter === 'male' ? 'ذكور' : genderFilter === 'female' ? 'إناث' : genderFilter === 'unknown' ? 'غير محدد' : 'الكل';

  return (
    <div className="p-6 md:p-8 max-w-7xl mx-auto" dir="rtl">
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
            <h1 className="text-xl font-bold text-slate-900">مدرسة العناية — قائمة التلاميذ الجملية</h1>
            <p className="text-sm text-slate-600 mt-1">
              القسم: <strong>{selectedSectionLabel}</strong> | السنة: <strong>{selectedYearLabel}</strong> | تصفية الجنس: <strong>{genderLabel}</strong>
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
          <span>غير محدد: <strong>{counts.unknown}</strong></span>
        </div>
      </div>

      {/* Page Header */}
      <div className="no-print mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">التلاميذ</h1>
          <p className="text-slate-500 mt-1">إدارة شؤون التلاميذ والتسجيلات والتوزيع الحقيقي للجنس</p>
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

      {/* Summary KPI Cards */}
      <div className="no-print grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
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

      {/* Filter Bar */}
      <div className="no-print bg-white p-5 rounded-2xl shadow-sm border border-slate-200 mb-8">
        <div className="flex items-center gap-2 font-bold text-slate-800 mb-4 text-sm">
          <Filter size={16} />
          <span>تصفية قوائم التلاميذ</span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <label className="space-y-1 text-xs font-semibold text-slate-700">
            <span>تصفية حسب الجنس</span>
            <select
              value={genderFilter}
              onChange={(e) => updateFilter('gender', e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
            >
              <option value="all">الكل (جميع الجنسين)</option>
              <option value="male">ذكور فقط</option>
              <option value="female">إناث فقط</option>
              <option value="unknown">غير محدد فقط</option>
            </select>
          </label>

          <label className="space-y-1 text-xs font-semibold text-slate-700">
            <span>تصفية حسب القسم</span>
            <select
              value={sectionFilter}
              onChange={(e) => updateFilter('level', e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
            >
              <option value="">جميع الأقسام</option>
              {options.levels.map((l) => (
                <option key={l.id} value={l.id}>{l.label}</option>
              ))}
            </select>
          </label>

          <label className="space-y-1 text-xs font-semibold text-slate-700">
            <span>تصفية حسب السنة الدراسية</span>
            <select
              value={yearFilter}
              onChange={(e) => updateFilter('year', e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
            >
              <option value="">السنة الدراسية الحالية</option>
              {options.years.map((y) => (
                <option key={y.id} value={y.id}>{y.name}</option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {/* Quick Action Cards */}
      <div className="no-print grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        {actionCards.map((card, index) => {
          const Icon = card.icon;
          return (
            <Link 
              key={index} 
              to={card.link}
              className="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 hover:border-[#3B4A36] hover:shadow-md transition-all flex flex-col items-center justify-center gap-2.5 text-center group"
            >
              <div className={`p-3.5 rounded-full text-white ${card.color} shadow-sm group-hover:scale-110 transition-transform`}>
                <Icon size={24} strokeWidth={2} />
              </div>
              <span className="font-semibold text-xs text-slate-700">{card.title}</span>
            </Link>
          );
        })}
      </div>

      {/* Student Table */}
      <div className="print-container bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="no-print p-5 border-b border-slate-100 flex justify-between items-center">
          <h2 className="text-base font-bold text-slate-800">قائمة التلاميذ ({students.length})</h2>
          {!isLoading && (
            <span className="text-xs font-medium text-slate-500">
              المعروض: {students.length} من أصل {counts.total} تلميذ
            </span>
          )}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-600">
              <tr>
                <th className="px-5 py-3.5 font-semibold w-24">CNTE</th>
                <th className="px-5 py-3.5 font-semibold">الاسم الكامل</th>
                <th className="px-5 py-3.5 font-semibold">الجنس</th>
                <th className="px-5 py-3.5 font-semibold">تاريخ الميلاد</th>
                <th className="px-5 py-3.5 font-semibold">الولي</th>
                <th className="px-5 py-3.5 font-semibold">رقم الاتصال</th>
                <th className="no-print px-5 py-3.5 font-semibold">التفاصيل</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading ? (
                <TableRowsSkeleton columns={7} />
              ) : students.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-10 text-center text-slate-500">
                    لا توجد بيانات مطابقة للتصفية المختارة.
                  </td>
                </tr>
              ) : (
                students.map((student) => {
                  const genderDisplay = student.gender === 'female' || (student as any).gender === 'أنثى' ? 'أنثى' : (student.gender === 'male' || (student as any).gender === 'ذكر' ? 'ذكر' : 'غير محدد');

                  return (
                    <tr key={student.id} className="hover:bg-slate-50/70">
                      <td className="px-5 py-3 font-medium text-slate-700" dir="ltr">{student.student_code || '—'}</td>
                      <td className="px-5 py-3 font-semibold text-slate-800">
                        <Link to={`/students/search/${student.id}`} className="hover:underline text-[#3B4A36]">
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
                      <td className="px-5 py-3 text-slate-600">
                        {[student.guardians?.[0]?.first_name || student.guardian_first_name, student.guardians?.[0]?.last_name || student.guardian_last_name].filter(Boolean).join(' ') || '—'}
                      </td>
                      <td className="px-5 py-3 text-slate-600" dir="ltr">
                        {student.guardians?.[0]?.phone || student.guardian_phone || student.mother_phone || '—'}
                      </td>
                      <td className="no-print px-5 py-3">
                        <Link to={`/students/search/${student.id}`} className="inline-flex rounded-lg bg-[#E3EBDB] px-3 py-1.5 text-xs font-semibold text-[#3B4A36] hover:bg-[#D5E1CC]">
                          عرض التفاصيل
                        </Link>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
