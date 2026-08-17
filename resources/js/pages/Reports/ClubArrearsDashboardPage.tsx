import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, Printer, RefreshCw, Search, Users, Building2, WalletCards, BadgeDollarSign } from 'lucide-react';
import { fetchClubArrearsDashboard, fetchClubs, ClubArrearsDashboardData, ClubItem } from '../../api/clubs';
import { fetchAcademicYears } from '../../api/years';
import { fetchLevels, fetchSections } from '../../api/classrooms';
import { AcademicYear, Level, Section } from '../../types';

const money = (value: number) => {
  const amount = Number(value);
  return Number.isFinite(amount) ? `${amount.toFixed(2)} د.ت` : '—';
};

const asArray = <T,>(value: unknown): T[] => {
  return Array.isArray(value) ? (value as T[]) : [];
};

export default function ClubArrearsDashboardPage() {
  const [data, setData] = useState<ClubArrearsDashboardData | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [clubs, setClubs] = useState<ClubItem[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [sections, setSections] = useState<Section[]>([]);
  const [yearId, setYearId] = useState<number | ''>('');
  const [clubId, setClubId] = useState<number | ''>('');
  const [levelId, setLevelId] = useState<number | ''>('');
  const [sectionId, setSectionId] = useState<number | ''>('');
  const [fromMonth, setFromMonth] = useState<string>('');
  const [toMonth, setToMonth] = useState<string>('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([fetchAcademicYears(), fetchClubs(), fetchLevels(), fetchSections()])
      .then(([yearList, clubList, levelList, sectionList]) => {
        setYears(asArray<AcademicYear>(yearList));
        setClubs(asArray<ClubItem>(clubList));
        setLevels(asArray<Level>(levelList));
        setSections(asArray<Section>(sectionList));
        const active = asArray<AcademicYear>(yearList).find((year) => year.is_active) || asArray<AcademicYear>(yearList)[0];
        if (active) setYearId(active.id);
      })
      .catch((err: any) => setError(err.message || 'تعذر تحميل خيارات التقرير.'));
  }, []);

  useEffect(() => {
    let isCurrent = true;
    if (!yearId) return;
    setLoading(true);
    setError(null);
    fetchClubArrearsDashboard({
      academic_year_id: Number(yearId),
      club_id: clubId ? Number(clubId) : undefined,
      level_id: levelId ? Number(levelId) : undefined,
      section_id: sectionId ? Number(sectionId) : undefined,
      from_month: fromMonth || undefined,
      to_month: toMonth || undefined,
      search: search.trim() || undefined,
    })
      .then((res) => {
        if (isCurrent) {
          setData(res);
        }
      })
      .catch((err: any) => {
        if (isCurrent) {
          setError(err.message || 'تعذر تحميل بيانات متخلدات النوادي.');
        }
      })
      .finally(() => {
        if (isCurrent) {
          setLoading(false);
        }
      });

    return () => {
      isCurrent = false;
    };
  }, [yearId, clubId, levelId, sectionId, fromMonth, toMonth, search]);

  const filteredSections = useMemo(() => {
    return asArray<Section>(sections).filter((section) => !levelId || section.level_id === Number(levelId));
  }, [sections, levelId]);

  const dataSections = useMemo(() => {
    return asArray<any>(data?.sections);
  }, [data]);

  const summary = useMemo(() => {
    return data?.summary || {
      sections_count: 0,
      students_count: 0,
      clubs_count: 0,
      fees_count: 0,
      total_due: 0,
      total_paid: 0,
      total_remaining: 0,
    };
  }, [data]);

  const detailRows = useMemo(() => {
    return asArray<any>(data?.students || data?.records)
      .filter((student) => Number(student?.total_remaining) > 0)
      .flatMap((student) =>
        asArray<any>(student?.clubs || student?.details).map((detail) => ({
          student,
          detail,
        }))
      );
  }, [data]);

  const handlePrint = () => window.print();

  return (
    <div className="space-y-5 dir-rtl text-slate-800">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 8mm; }
          body * { visibility: hidden; }
          .club-arrears-print, .club-arrears-print * { visibility: visible; }
          .club-arrears-print { position: absolute; inset: 0; width: 100%; }
          .no-print, button, input, select { display: none !important; }
          .print-only { display: block !important; }
          tr { break-inside: avoid !important; }
        }
        .print-only { display: none; }
      `}</style>

      <div className="no-print flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#26352B]">لوحة متخلدات النوادي</h1>
          <p className="mt-1 text-sm text-slate-500">إجمالي المتخلدات حسب الأقسام والتلاميذ والنوادي</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => setData(null)}
            className="inline-flex items-center gap-2 rounded-xl border px-4 py-2 text-sm hover:bg-slate-50"
          >
            <RefreshCw size={16} /> تحديث
          </button>
          <button
            onClick={handlePrint}
            disabled={!data || loading}
            className="inline-flex items-center gap-2 rounded-xl bg-[#3B4A36] px-4 py-2 text-sm text-white disabled:opacity-50"
          >
            <Printer size={16} /> طباعة التقرير
          </button>
        </div>
      </div>

      {error && (
        <div className="no-print flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
          <AlertCircle size={17} /> {error}
        </div>
      )}

      <div className="no-print flex flex-wrap gap-4 rounded-2xl border bg-white p-4 *:min-w-[150px] *:flex-1">
        <label className="text-sm font-semibold">
          السنة الدراسية
          <select
            value={yearId}
            onChange={(event) => setYearId(event.target.value ? Number(event.target.value) : '')}
            className="mt-1 w-full rounded-xl border px-3 py-2 font-normal"
          >
            <option value="">اختر السنة</option>
            {asArray<AcademicYear>(years).map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
              </option>
            ))}
          </select>
        </label>

        <label className="text-sm font-semibold">
          النادي
          <select
            value={clubId}
            onChange={(event) => setClubId(event.target.value ? Number(event.target.value) : '')}
            className="mt-1 w-full rounded-xl border px-3 py-2 font-normal"
          >
            <option value="">كل النوادي</option>
            {asArray<ClubItem>(clubs).map((club) => (
              <option key={club.id} value={club.id}>
                {club.name}
              </option>
            ))}
          </select>
        </label>

        <label className="text-sm font-semibold">
          المستوى
          <select
            value={levelId}
            onChange={(event) => {
              setLevelId(event.target.value ? Number(event.target.value) : '');
              setSectionId('');
            }}
            className="mt-1 w-full rounded-xl border px-3 py-2 font-normal"
          >
            <option value="">كل المستويات</option>
            {asArray<Level>(levels).map((level) => (
              <option key={level.id} value={level.id}>
                {level.name}
              </option>
            ))}
          </select>
        </label>

        <label className="text-sm font-semibold">
          القسم
          <select
            value={sectionId}
            onChange={(event) => setSectionId(event.target.value ? Number(event.target.value) : '')}
            className="mt-1 w-full rounded-xl border px-3 py-2 font-normal"
          >
            <option value="">كل الأقسام</option>
            {filteredSections.map((section) => (
              <option key={section.id} value={section.id}>
                {section.name}
              </option>
            ))}
          </select>
        </label>

        <label className="text-sm font-semibold">
          من شهر
          <input
            type="month"
            value={fromMonth}
            onChange={(event) => setFromMonth(event.target.value)}
            className="mt-1 w-full rounded-xl border px-3 py-2 font-normal"
          />
        </label>

        <label className="text-sm font-semibold">
          إلى شهر
          <input
            type="month"
            value={toMonth}
            onChange={(event) => setToMonth(event.target.value)}
            className="mt-1 w-full rounded-xl border px-3 py-2 font-normal"
          />
        </label>

        <label className="text-sm font-semibold">
          بحث عن تلميذ
          <div className="relative mt-1">
            <Search size={16} className="absolute right-3 top-3 text-slate-400" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="اسم أو رقم التلميذ"
              className="w-full rounded-xl border py-2 pr-9 pl-3 font-normal"
            />
          </div>
        </label>
      </div>

      <div className="club-arrears-print space-y-5">
        <div className="print-only mb-4 text-center">
          <h1 className="text-xl font-bold">تقرير متخلدات النوادي حسب الأقسام والتلاميذ</h1>
          <p className="text-sm">
            السنة الدراسية: {years.find((year) => year.id === Number(yearId))?.name ?? '—'} — تاريخ الطباعة:{' '}
            {new Date().toLocaleDateString('ar-TN')}
          </p>
        </div>

        {loading && (
          <div className="rounded-2xl border bg-white p-10 text-center text-slate-500">
            جار تحميل الإحصائيات...
          </div>
        )}

        {!loading && !error && data && (dataSections.length > 0 || detailRows.length > 0 ? (
          <>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
              {[
                {
                  label: 'إجمالي المتخلدات',
                  value: money(summary.total_remaining),
                  icon: <BadgeDollarSign size={21} />,
                  tone: 'bg-amber-50 text-amber-800',
                },
                {
                  label: 'عدد التلاميذ',
                  value: summary.students_count ?? 0,
                  icon: <Users size={21} />,
                  tone: 'bg-blue-50 text-blue-800',
                },
                {
                  label: 'عدد الأقسام',
                  value: summary.sections_count ?? 0,
                  icon: <Building2 size={21} />,
                  tone: 'bg-emerald-50 text-emerald-800',
                },
                {
                  label: 'عدد رسوم النوادي',
                  value: summary.fees_count ?? 0,
                  icon: <WalletCards size={21} />,
                  tone: 'bg-violet-50 text-violet-800',
                },
              ].map((card) => (
                <div key={card.label} className={`rounded-2xl border p-4 ${card.tone}`}>
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold">{card.label}</span>
                    {card.icon}
                  </div>
                  <div className="mt-2 text-2xl font-extrabold">{card.value}</div>
                </div>
              ))}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {dataSections.map((section) => {
                const students = asArray<any>(section?.students);
                const max = Math.max(...dataSections.map((item) => Number(item?.total_remaining) || 0), 1);
                return (
                  <div
                    key={`${section.section_id}-${section.section_name}`}
                    className="rounded-2xl border bg-white p-4"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <h2 className="font-bold">{section.section_name}</h2>
                        <p className="text-xs text-slate-500">
                          {section.students_count ?? 0} تلميذًا · {section.fees_count ?? 0} معلومًا
                        </p>
                      </div>
                      <strong className="text-lg text-[#3B4A36]">{money(section.total_remaining)}</strong>
                    </div>
                    <div className="mt-3 h-3 overflow-hidden rounded-full bg-slate-100">
                      <div
                        className="h-full rounded-full bg-amber-500"
                        style={{ width: `${Math.max(4, (Number(section.total_remaining || 0) / max) * 100)}%` }}
                      />
                    </div>
                    <div className="mt-3 space-y-2">
                      {students.slice(0, 5).map((student) => (
                        <div
                          key={`${student.student_id}-${student.enrollment_id ?? '0'}`}
                          className="flex items-center justify-between border-b pb-2 text-sm last:border-0"
                        >
                          <span>
                            {student.student_name}
                            <small className="mr-2 text-slate-400">{student.student_code}</small>
                          </span>
                          <strong>{money(student.total_remaining)}</strong>
                        </div>
                      ))}
                    </div>
                    {students.length > 5 && (
                      <p className="mt-2 text-xs text-slate-500">يظهر في الجدول أدناه باقي تلاميذ القسم.</p>
                    )}
                  </div>
                );
              })}
            </div>

            <div className="overflow-x-auto rounded-2xl border bg-white">
              <div className="flex items-center justify-between border-b p-4">
                <div>
                  <h2 className="font-bold">التفصيل حسب التلميذ والنادي</h2>
                  <p className="text-xs text-slate-500">
                    كل سطر يمثل معلوم نادي غير مسدد أو مسدد جزئيًا.
                  </p>
                </div>
                <strong className="text-[#3B4A36]">{money(summary.total_remaining)}</strong>
              </div>
              <table className="w-full min-w-[900px] text-sm">
                <thead className="bg-slate-50 text-right">
                  <tr>
                    <th className="p-3">القسم</th>
                    <th className="p-3">التلميذ</th>
                    <th className="p-3">النادي</th>
                    <th className="p-3">الشهر</th>
                    <th className="p-3">المستحق</th>
                    <th className="p-3">المدفوع</th>
                    <th className="p-3">المتخلد</th>
                  </tr>
                </thead>
                <tbody>
                  {detailRows.map(({ student, detail }) => (
                    <tr
                      key={`${student.student_id}-${student.enrollment_id ?? '0'}-${detail.fee_id ?? detail.id ?? '0'}-${detail.month}`}
                      className="border-t"
                    >
                      <td className="p-3">{detail.section_name}</td>
                      <td className="p-3 font-semibold">
                        {student.student_name}
                        <div className="text-xs font-normal text-slate-400">{student.student_code}</div>
                      </td>
                      <td className="p-3">{detail.club_name}</td>
                      <td className="p-3">{detail.month}</td>
                      <td className="p-3">{money(detail.amount_due)}</td>
                      <td className="p-3 text-emerald-700">{money(detail.amount_paid)}</td>
                      <td className="p-3 font-bold text-amber-700">{money(detail.remaining)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t bg-slate-50 font-bold">
                    <td colSpan={6} className="p-3">
                      الإجمالي العام
                    </td>
                    <td className="p-3 text-amber-700">{money(summary.total_remaining)}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </>
        ) : (
          <div className="rounded-2xl border bg-white p-10 text-center text-slate-500">
            لا توجد بيانات متخلدات للمرشحات الحالية.
          </div>
        ))}
      </div>
    </div>
  );
}
