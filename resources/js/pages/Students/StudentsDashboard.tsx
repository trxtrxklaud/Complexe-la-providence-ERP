import { useState, useEffect, useRef, useCallback } from 'react';
import type { ComponentType } from 'react';

import { Link, useSearchParams } from 'react-router-dom';
import {
  Users, UserPlus, Search, ArrowRightLeft, CreditCard,
  GraduationCap, UserRound, HelpCircle, Printer, Filter, AlertCircle,
} from 'lucide-react';
import {
  getStudentsFullResponse,
  getStudentSearchOptions,
  type Student,
  type StudentSearchOptions,
  type StudentSearchResponse,
} from '../../api/students';
import { TableRowsSkeleton } from '../../components/DataSkeleton';
import { EnterpriseHeader } from '../../components/ui/EnterpriseHeader';
import { EnterpriseStatCard } from '../../components/ui/EnterpriseStatCard';
import { EnterpriseBadge } from '../../components/ui/EnterpriseBadge';

const C = {
  forest: '#3B4A36',
  sage:   '#E3EBDB',
  rose:   '#F1E4E2',
  beige:  '#EFEAE0',
  muted:  '#7C8677',
};

function StudentCountCard({
  label, count, total, icon: Icon, tint, iconColor,
}: {
  label: string; count: number; total?: number;
  icon: ComponentType<{ size?: number }>;
  tint: string; iconColor: string;
}) {
  const pct = total && total > 0 && count > 0
    ? `(${((count / total) * 100).toFixed(1)}%)`
    : null;
  return (
    <div
      className="rounded-2xl p-5 shadow-sm border border-slate-200/80 flex items-center justify-between"
      style={{ backgroundColor: tint }}
    >
      <div>
        <p className="text-xs font-semibold text-slate-600 mb-1">{label}</p>
        <div className="flex items-baseline gap-2">
          <span className="text-2xl font-black text-slate-800">{count}</span>
          {pct && <span className="text-xs font-medium text-slate-500">{pct}</span>}
        </div>
      </div>
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/80" style={{ color: iconColor }}>
        <Icon size={20} />
      </div>
    </div>
  );
}

function genderBadge(gender: string | null | undefined) {
  if (gender === 'female') return { label: 'أنثى', cls: 'bg-[#F1E4E2] text-[#A46E67]' };
  if (gender === 'male')   return { label: 'ذكر',  cls: 'bg-[#EFEAE0] text-[#8A7C57]' };
  return { label: 'غير محدد', cls: 'bg-slate-100 text-slate-500' };
}

export function StudentsDashboard() {
  const [searchParams, setSearchParams] = useSearchParams();

  // ─── state ───────────────────────────────────────────────────────────────
  const [students,  setStudents]  = useState<Student[]>([]);
  const [counts,    setCounts]    = useState({ total: 0, males: 0, females: 0, unknown: 0 });
  const [options,   setOptions]   = useState<StudentSearchOptions>({ levels: [], years: [] });
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState('');

  // ─── read filters from URL (single source of truth) ──────────────────────
  const genderFilter  = searchParams.get('gender')  || 'all';
  const sectionFilter = searchParams.get('level')   || '';
  const yearFilter    = searchParams.get('year')    || '';
  const nameSearch    = searchParams.get('search')  || '';

  // ─── load search-options once on mount ───────────────────────────────────
  useEffect(() => {
    const ctrl = new AbortController();
    getStudentSearchOptions(ctrl.signal)
      .then(setOptions)
      .catch(() => {});
    return () => ctrl.abort();
  }, []);

  // ─── load students whenever filters change ────────────────────────────────
  // Use a ref-based "latest request ID" to prevent stale-response overwriting
  const reqIdRef = useRef(0);

  const loadStudents = useCallback((signal?: AbortSignal) => {
    const myId = ++reqIdRef.current;
    setIsLoading(true);
    setLoadError('');

    getStudentsFullResponse(
      { gender: genderFilter, level: sectionFilter, year: yearFilter, search: nameSearch, per_page: 100 },
      signal,
    )
      .then((res: StudentSearchResponse) => {
        if (signal?.aborted || myId !== reqIdRef.current) return;   // stale — discard
        setStudents(res.data);
        setCounts({
          total:   res.total_count ?? res.data.length,
          males:   res.male_count  ?? 0,
          females: res.female_count ?? 0,
          unknown: res.unknown_count ?? 0,
        });
      })
      .catch((err) => {
        if (signal?.aborted || myId !== reqIdRef.current) return;
        setLoadError(err instanceof Error ? err.message : 'تعذّر تحميل قائمة التلاميذ');
        setStudents([]);
      })
      .finally(() => {
        if (signal?.aborted || myId !== reqIdRef.current) return;
        setIsLoading(false);
      });
  }, [genderFilter, sectionFilter, yearFilter, nameSearch]);

  useEffect(() => {
    const controller = new AbortController();
    loadStudents(controller.signal);
    return () => controller.abort();
  }, [loadStudents]);

  // ─── filter helpers ───────────────────────────────────────────────────────
  function updateFilter(key: string, value: string) {
    const next = new URLSearchParams(searchParams);
    if (value && value !== 'all') next.set(key, value);
    else next.delete(key);
    setSearchParams(next);
  }

  // ─── derived labels for print header ─────────────────────────────────────
  const sectionLabel = options.levels.find((l) => String(l.id) === sectionFilter)?.label || 'جميع الأقسام';
  const yearLabel    = options.years.find((y) => String(y.id) === yearFilter)?.name       || 'السنة الدراسية الحالية';
  const genderLabel  = genderFilter === 'male' ? 'ذكور' : genderFilter === 'female' ? 'إناث' : genderFilter === 'unknown' ? 'غير محدد' : 'الكل';

  const actionCards = [
    { title: 'ترسيم التلاميذ',                   icon: UserPlus,      link: '/students/enroll',        color: 'bg-blue-500'   },
    { title: 'بحث متقدم',                         icon: Search,        link: '/students/search',        color: 'bg-indigo-500' },
    { title: 'نقل التلاميذ',                      icon: ArrowRightLeft, link: '/students/transfer',     color: 'bg-orange-500' },
    { title: 'قائمة التلاميذ حسب حالة السداد',   icon: CreditCard,    link: '/income/payment-status',  color: 'bg-rose-500'   },
  ];

  return (
    <div className="p-6 md:p-8 max-w-7xl mx-auto" dir="rtl">

      {/* ── Print Styles ─────────────────────────────────────────── */}
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 10mm 8mm; }
          body { background: white !important; font-size: 10pt !important; color: black !important; }
          .no-print, header, nav, aside, sidebar, button, form, .no-print * { display: none !important; }
          .print-only { display: block !important; }
          table { width: 100% !important; border-collapse: collapse !important; margin-top: 15px !important; direction: rtl !important; }
          thead { display: table-header-group !important; }
          tr, th, td { break-inside: avoid !important; page-break-inside: avoid !important; }
          th, td { border: 1px solid #000000 !important; padding: 5px 6px !important; font-size: 9.5pt !important; text-align: right !important; color: black !important; }
          th { background-color: #F2F2F2 !important; font-weight: bold !important; }
        }
        .print-only { display: none; }
      `}</style>

      {/* ── Printable Header ──────────────────────────────────────── */}
      <div className="print-only mb-6 border-b border-slate-300 pb-4">
        <h1 className="text-xl font-bold text-slate-900">مدرسة العناية — قائمة التلاميذ الجملية</h1>
        <p className="text-sm text-slate-600 mt-1">
          القسم: <strong>{sectionLabel}</strong> | السنة: <strong>{yearLabel}</strong> | تصفية الجنس: <strong>{genderLabel}</strong>
        </p>
        <div className="mt-4 flex gap-6 text-sm bg-slate-50 p-3 rounded-lg border border-slate-200">
          <span>إجمالي التلاميذ: <strong>{counts.total}</strong></span>
          <span>الذكور: <strong>{counts.males}</strong></span>
          <span>الإناث: <strong>{counts.females}</strong></span>
          <span>غير محدد: <strong>{counts.unknown}</strong></span>
        </div>
      </div>

      {/* ── Page Header ───────────────────────────────────────────── */}
      <div className="no-print">
        <EnterpriseHeader
          title="شؤون التلاميذ والتسجيل"
          subtitle="إدارة بيانات التلاميذ المسجلين، الشعب الدراسية، والتوزيع الإحصائي حسب الجنس"
          icon={GraduationCap}
          actions={
            <div className="flex items-center gap-2.5">
              <Link
                to="/students/enroll"
                className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-[#2E3B2A] to-[#1E271B] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all hover:opacity-95 active:scale-95"
              >
                <UserPlus size={17} />
                <span>تسجيل جديد</span>
              </Link>
              <button
                type="button"
                onClick={() => window.print()}
                className="inline-flex items-center justify-center gap-2 rounded-xl bg-white border border-slate-200/90 px-4 py-2.5 text-sm font-bold text-slate-700 shadow-xs transition hover:bg-slate-50 hover:border-slate-300"
              >
                <Printer size={17} />
                <span>طباعة القائمة (A4)</span>
              </button>
            </div>
          }
        />
      </div>

      {/* ── KPI Cards ─────────────────────────────────────────────── */}
      <div className="no-print grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <EnterpriseStatCard
          label="إجمالي التلاميذ"
          value={counts.total}
          icon={GraduationCap}
          variant="blue"
        />
        <EnterpriseStatCard
          label="عدد الذكور"
          value={counts.males}
          subValue={counts.total > 0 && counts.males > 0 ? `(${((counts.males / counts.total) * 100).toFixed(1)}%)` : null}
          icon={Users}
          variant="emerald"
        />
        <EnterpriseStatCard
          label="عدد الإناث"
          value={counts.females}
          subValue={counts.total > 0 && counts.females > 0 ? `(${((counts.females / counts.total) * 100).toFixed(1)}%)` : null}
          icon={UserRound}
          variant="rose"
        />
        <EnterpriseStatCard
          label="غير محدد"
          value={counts.unknown}
          icon={HelpCircle}
          variant="neutral"
        />
      </div>

      {/* ── Filter Bar ────────────────────────────────────────────── */}
      <div className="no-print bg-white p-5 rounded-2xl shadow-sm border border-slate-200 mb-8">
        <div className="flex items-center gap-2 font-bold text-slate-800 mb-4 text-sm">
          <Filter size={16} />
          <span>تصفية قوائم التلاميذ</span>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Name search */}
          <label className="space-y-1 text-sm font-bold text-slate-800">
            <span>بحث بالاسم</span>
            <div className="relative">
              <Search size={14} className="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 pointer-events-none" />
              <input
                type="text"
                value={nameSearch}
                placeholder="اسم أو رمز التلميذ…"
                onChange={(e) => updateFilter('search', e.target.value)}
                className="w-full rounded-xl border border-slate-200 bg-white pr-8 pl-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
              />
            </div>
          </label>

          {/* Gender */}
          <label className="space-y-1 text-sm font-bold text-slate-800">
            <span>تصفية حسب الجنس</span>
            <select
              value={genderFilter}
              onChange={(e) => updateFilter('gender', e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
            >
              <option value="all">الكل</option>
              <option value="male">ذكور فقط</option>
              <option value="female">إناث فقط</option>
              <option value="unknown">غير محدد فقط</option>
            </select>
          </label>

          {/* Section */}
          <label className="space-y-1 text-sm font-bold text-slate-800">
            <span>تصفية حسب القسم</span>
            <select
              value={sectionFilter}
              onChange={(e) => updateFilter('level', e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
            >
              <option value="">جميع الأقسام</option>
              {options.levels.map((l) => (
                <option key={l.id} value={l.id}>{l.label}</option>
              ))}
            </select>
          </label>

          {/* Year */}
          <label className="space-y-1 text-sm font-bold text-slate-800">
            <span>تصفية حسب السنة الدراسية</span>
            <select
              value={yearFilter}
              onChange={(e) => updateFilter('year', e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/10"
            >
              <option value="">السنة الدراسية الحالية</option>
              {options.years.map((y) => (
                <option key={y.id} value={y.id}>{y.name}</option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {/* ── Quick Action Cards ────────────────────────────────────── */}
      <div className="no-print grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        {actionCards.map((card) => {
          const Icon = card.icon;
          return (
            <Link
              key={card.link}
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

      {/* ── Error State ───────────────────────────────────────────── */}
      {loadError && (
        <div className="no-print mb-6 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <AlertCircle size={18} className="shrink-0" />
          <span>{loadError}</span>
          <button
            type="button"
            onClick={() => loadStudents()}
            className="mr-auto text-xs font-semibold underline"
          >
            إعادة المحاولة
          </button>
        </div>
      )}

      {/* ── Student Table ─────────────────────────────────────────── */}
      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="no-print p-5 border-b border-slate-100 flex justify-between items-center">
          <h2 className="text-lg font-bold text-slate-900">
            قائمة التلاميذ
            {!isLoading && (
              <span className="mr-2 text-sm font-semibold text-slate-600">
                ({students.length} معروض من أصل {counts.total} تلميذ)
              </span>
            )}
          </h2>
          {isLoading && (
            <span className="text-xs text-slate-400 animate-pulse">جارٍ التحميل…</span>
          )}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="bg-slate-50 border-b border-slate-300 text-slate-800">
              <tr className="text-sm">
                <th className="px-5 py-3.5 font-bold w-24">CNTE</th>
                <th className="px-5 py-3.5 font-bold">الاسم الكامل</th>
                <th className="px-5 py-3.5 font-bold">الجنس</th>
                <th className="px-5 py-3.5 font-bold">القسم</th>
                <th className="px-5 py-3.5 font-bold">تاريخ الميلاد</th>
                <th className="px-5 py-3.5 font-bold">الولي</th>
                <th className="px-5 py-3.5 font-bold">رقم الاتصال</th>
                <th className="no-print px-5 py-3.5 font-bold">التفاصيل</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading ? (
                <TableRowsSkeleton columns={8} rows={8} />
              ) : students.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center text-slate-500">
                    <div className="flex flex-col items-center gap-2">
                      <GraduationCap size={32} className="text-slate-300" />
                      <span>لا توجد بيانات مطابقة للتصفية المختارة.</span>
                      {(genderFilter !== 'all' || sectionFilter || yearFilter || nameSearch) && (
                        <button
                          type="button"
                          onClick={() => setSearchParams(new URLSearchParams())}
                          className="mt-2 text-xs font-semibold text-[#3B4A36] underline"
                        >
                          مسح التصفية وعرض الكل
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ) : (
                students.map((student) => {
                  const badge   = genderBadge(student.gender);
                  const enrollment = student.enrollments?.[0];
                  const sectionName = [
                    enrollment?.level?.name,
                    enrollment?.section?.name,
                  ].filter(Boolean).join(' ') || '—';
                  const guardianName = [
                    student.guardians?.[0]?.first_name || student.guardian_first_name,
                    student.guardians?.[0]?.last_name  || student.guardian_last_name,
                  ].filter(Boolean).join(' ') || '—';
                  const guardianPhone =
                    student.guardians?.[0]?.phone || student.guardian_phone || student.mother_phone || '—';

                  return (
                    <tr key={student.id} className="hover:bg-slate-50/70 transition-colors">
                      <td className="px-5 py-3 font-bold text-slate-700 text-xs" dir="ltr">
                        {student.student_code || '—'}
                      </td>
                      <td className="px-5 py-3 font-bold text-slate-900 text-[15px]">
                        <Link
                          to={`/students/search/${student.id}`}
                          className="hover:underline hover:text-[#3B4A36] transition-colors"
                        >
                          {student.first_name} {student.last_name}
                        </Link>
                      </td>
                      <td className="px-5 py-3.5">
                        <EnterpriseBadge
                          variant={student.gender === 'female' ? 'rose' : student.gender === 'male' ? 'blue' : 'neutral'}
                        >
                          {badge.label}
                        </EnterpriseBadge>
                      </td>
                      <td className="px-5 py-3.5 font-bold text-slate-800 text-sm">{sectionName}</td>
                      <td className="px-5 py-3.5 font-medium text-slate-600 text-sm">
                        {student.dob ? new Date(student.dob).toLocaleDateString('ar-TN') : '—'}
                      </td>
                      <td className="px-5 py-3.5 font-bold text-slate-900 text-sm">{guardianName}</td>
                      <td className="px-5 py-3.5 font-bold text-slate-700 text-sm font-mono" dir="ltr">{guardianPhone}</td>
                      <td className="no-print px-5 py-3.5">
                        <Link
                          to={`/students/search/${student.id}`}
                          className="inline-flex items-center gap-1.5 rounded-xl bg-slate-100 hover:bg-[#2E3B2A] hover:text-white px-3.5 py-1.5 text-xs font-bold text-slate-800 transition-all active:scale-95 shadow-2xs"
                        >
                          عرض الملف
                        </Link>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination info */}
        {!isLoading && counts.total > 100 && (
          <div className="no-print px-5 py-3 border-t border-slate-100 text-xs text-slate-500 text-center">
            يُعرض أول 100 تلميذ. استخدم <Link to="/students/search" className="font-semibold text-[#3B4A36] underline">البحث المتقدم</Link> لتضييق النطاق.
          </div>
        )}
      </div>
    </div>
  );
}
