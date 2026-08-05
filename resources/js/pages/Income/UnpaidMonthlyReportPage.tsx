import { FormEvent, useEffect, useMemo, useState } from 'react';
import { AlertCircle, ClipboardList, Filter, Printer, Search } from 'lucide-react';
import {
  fetchUnpaidMonthlyOptions,
  fetchUnpaidMonthlyReport,
  type UnpaidMonthlyOptions,
  type UnpaidMonthlyReport,
  type UnpaidMonthlyRow,
} from '../../api/reports';
import { PageDataSkeleton } from '../../components/DataSkeleton';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };

type ColumnKey = 'father_name' | 'father_phone' | 'mother_name' | 'mother_phone';

const COLUMNS: Array<{ key: ColumnKey; label: string; kind: 'name' | 'phone' }> = [
  { key: 'father_name', label: 'اسم الأب', kind: 'name' },
  { key: 'father_phone', label: 'هاتف الأب', kind: 'phone' },
  { key: 'mother_name', label: 'اسم الأم', kind: 'name' },
  { key: 'mother_phone', label: 'هاتف الأم', kind: 'phone' },
];

const STORAGE_KEY = 'unpaid_monthly_visible_columns';

const DEFAULT_VISIBILITY: Record<ColumnKey, boolean> = {
  father_name: true,
  father_phone: true,
  mother_name: true,
  mother_phone: true,
};

function loadVisibility(): Record<ColumnKey, boolean> {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return { ...DEFAULT_VISIBILITY };
    const parsed = JSON.parse(raw) as Partial<Record<ColumnKey, boolean>>;
    return { ...DEFAULT_VISIBILITY, ...parsed };
  } catch {
    return { ...DEFAULT_VISIBILITY };
  }
}

function formatPhone(value: string | null): string {
  if (!value) return '—';

  let digits = value.replace(/\D+/g, '');
  if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.length === 11 && digits.startsWith('216')) digits = digits.slice(3);

  if (digits.length === 8) {
    return digits.slice(0, 2) + ' ' + digits.slice(2, 5) + ' ' + digits.slice(5);
  }

  return digits === '' ? '—' : digits;
}

function cellValue(row: UnpaidMonthlyRow, key: ColumnKey): string {
  if (key === 'father_phone') return formatPhone(row.father_phone);
  if (key === 'mother_phone') return formatPhone(row.mother_phone);
  if (key === 'father_name') return row.father_name ?? '—';

  return row.mother_name ?? '—';
}

export function UnpaidMonthlyReportPage() {
  const [options, setOptions] = useState<UnpaidMonthlyOptions>({ years: [], selected_year_id: null, months: [], sections: [] });
  const [yearId, setYearId] = useState('');
  const [month, setMonth] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [report, setReport] = useState<UnpaidMonthlyReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState('');
  const [visible, setVisible] = useState<Record<ColumnKey, boolean>>(loadVisibility);

  const shownColumns = useMemo(() => COLUMNS.filter((column) => visible[column.key]), [visible]);
  const totalColumns = 5 + shownColumns.length;

  function toggleColumn(key: ColumnKey) {
    setVisible((current) => {
      const next = { ...current, [key]: !current[key] };
      try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
      } catch {
        // حفظ التفضيل رفاهية؛ فشله لا يعطّل الشاشة.
      }
      return next;
    });
  }

  useEffect(() => {
    const controller = new AbortController();
    fetchUnpaidMonthlyOptions(undefined, controller.signal)
      .then((data) => {
        setOptions(data);
        setYearId(data.selected_year_id ? String(data.selected_year_id) : '');
        setMonth(data.months[0]?.value ?? '');
      })
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(requestError instanceof Error ? requestError.message : 'فشل تحميل خيارات التقرير');
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, []);

  useEffect(() => {
    if (!yearId) return;
    const controller = new AbortController();
    fetchUnpaidMonthlyOptions(Number(yearId), controller.signal)
      .then((data) => {
        setOptions(data);
        setMonth((current) => data.months.some((item) => item.value === current) ? current : (data.months[0]?.value ?? ''));
        setSectionId('');
        setReport(null);
      })
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(requestError instanceof Error ? requestError.message : 'فشل تحميل أقسام السنة');
      });
    return () => controller.abort();
  }, [yearId]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!yearId || !month || !sectionId) {
      setError('اختر السنة والشهر والقسم قبل توليد التقرير.');
      return;
    }
    setGenerating(true);
    setError('');
    try {
      setReport(await fetchUnpaidMonthlyReport({ academic_year_id: Number(yearId), month, section_id: Number(sectionId) }));
    } catch (requestError) {
      setReport(null);
      setError(requestError instanceof Error ? requestError.message : 'فشل تحميل التقرير');
    } finally {
      setGenerating(false);
    }
  }

  return (
    <div className="unpaid-monthly-report-page mx-auto max-w-6xl px-6 pb-10" dir="rtl">
      <style>{`@media print { body * { visibility: hidden !important; } .unpaid-monthly-print, .unpaid-monthly-print * { visibility: visible !important; } .unpaid-monthly-print { position: absolute !important; inset: 0 !important; width: 100% !important; max-width: none !important; padding: 0 !important; } .unpaid-monthly-report-page button, .unpaid-monthly-report-page select, .unpaid-monthly-report-page input { display: none !important; } .unpaid-monthly-print thead { display: table-header-group !important; } .unpaid-monthly-print tr { break-inside: avoid !important; } .unpaid-monthly-print table { font-size: 12px !important; } }`}</style>

      <div className="mb-5 flex items-center gap-3 print:hidden">
        <div className="flex h-11 w-11 items-center justify-center rounded-2xl" style={{ backgroundColor: C.sage }}><ClipboardList size={22} style={{ color: C.forest }} /></div>
        <div><h2 className="text-xl font-bold" style={{ color: C.ink }}>جرد التلاميذ غير المسددين</h2><p className="text-sm" style={{ color: C.muted }}>تقرير شهري حسب القسم والسنة الدراسية</p></div>
      </div>

      <form onSubmit={handleSubmit} className="mb-5 flex flex-wrap items-end gap-4 rounded-2xl bg-white p-5 shadow-sm print:hidden" style={{ border: `1px solid ${C.line}` }}>
        <div className="min-w-52 flex-1">
          <label htmlFor="unpaid_year_id" className="text-sm font-semibold" style={{ color: C.ink }}>السنة الدراسية</label>
          <select id="unpaid_year_id" name="unpaid_year_id" value={yearId} onChange={(event) => setYearId(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            <option value="">اختر السنة</option>
            {options.years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
          </select>
        </div>
        <div className="min-w-52 flex-1">
          <label htmlFor="unpaid_month" className="text-sm font-semibold" style={{ color: C.ink }}>الشهر</label>
          <select id="unpaid_month" name="unpaid_month" value={month} onChange={(event) => setMonth(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            <option value="">اختر الشهر</option>
            {options.months.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </div>
        <div className="min-w-52 flex-1">
          <label htmlFor="unpaid_section_id" className="text-sm font-semibold" style={{ color: C.ink }}>القسم / الفصل</label>
          <select id="unpaid_section_id" name="unpaid_section_id" value={sectionId} onChange={(event) => setSectionId(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5">
            <option value="">اختر القسم</option>
            {options.sections.map((section) => (
              <option key={section.id} value={section.id}>{section.label} ({section.students_count})</option>
            ))}
          </select>
          <p className="mt-1 text-xs" style={{ color: C.muted }}>الرقم بين قوسين: عدد التلاميذ المسجّلين في القسم</p>
        </div>
        <button type="submit" disabled={generating} className="inline-flex items-center gap-2 rounded-xl px-5 py-2.5 font-semibold text-white disabled:opacity-60" style={{ backgroundColor: C.forest }}><Search size={17} />{generating ? 'جاري التوليد...' : 'توليد التقرير'}</button>
      </form>

      {error && <div className="mb-5 flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 print:hidden"><AlertCircle size={18} />{error}</div>}
      {loading && <PageDataSkeleton cards={3} rows={5} />}

      {report && !loading && (
        <>
          <div className="mb-4 rounded-2xl bg-white p-4 shadow-sm print:hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="mb-3 flex items-center gap-2 text-sm font-semibold" style={{ color: C.ink }}>
              <Filter size={16} style={{ color: C.forest }} />
              الأعمدة المعروضة في الجدول وفي الطباعة
            </div>
            <div className="flex flex-wrap gap-2">
              {COLUMNS.map((column) => {
                const active = visible[column.key];
                return (
                  <label
                    key={column.key}
                    htmlFor={'col_' + column.key}
                    className="inline-flex cursor-pointer select-none items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold"
                    style={{
                      backgroundColor: active ? C.sage : '#FFFFFF',
                      color: active ? C.forest : C.muted,
                      border: `1px solid ${active ? C.forest : C.line}`,
                    }}
                  >
                    <input
                      id={'col_' + column.key}
                      name={'col_' + column.key}
                      type="checkbox"
                      className="h-4 w-4 accent-[#3B4A36]"
                      checked={active}
                      onChange={() => toggleColumn(column.key)}
                    />
                    {column.label}
                  </label>
                );
              })}
            </div>
            <p className="mt-3 text-xs" style={{ color: C.muted }}>العمود المحجوب لا يظهر في الجدول ولا يُطبع، والاختيار يُحفظ للمرة القادمة.</p>
          </div>

          <div className="unpaid-monthly-print rounded-2xl bg-white shadow-sm" style={{ border: `1px solid ${C.line}` }}>
            <div className="border-b px-5 py-5 text-center" style={{ borderColor: C.line }}>
              <p className="text-lg font-bold" style={{ color: C.ink }}>{report.school_name}</p>
              <h1 className="mt-1 text-xl font-extrabold" style={{ color: C.forest }}>{report.title}</h1>
              <div className="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-3">
                <span>القسم: <b>{report.section.label}</b></span>
                <span>الشهر: <b>{report.month.label}</b></span>
                <span>السنة: <b>{report.academic_year.name}</b></span>
              </div>
              <div className="mt-2 text-xs text-slate-500">تاريخ التقرير: {report.report_date} — الوقت: {report.report_time}</div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-[#F7F9F4] px-5 py-4">
              <span className="text-sm font-semibold text-slate-700">عدد التلاميذ غير المسددين</span>
              <span className="text-2xl font-extrabold tabular-nums" style={{ color: C.forest }}>{report.summary.unpaid_students_count}</span>
              <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-[#3B4A36] px-4 py-2 text-sm font-semibold text-white print:hidden"><Printer size={16} />طباعة التقرير</button>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-right text-sm">
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="border px-3 py-3 text-center font-bold" style={{ borderColor: C.line, color: C.ink, width: '3rem' }}>#</th>
                    <th className="border px-3 py-3 font-bold" style={{ borderColor: C.line, color: C.ink }}>الرقم المدرسي</th>
                    <th className="border px-3 py-3 font-bold" style={{ borderColor: C.line, color: C.ink }}>اسم التلميذ</th>
                    <th className="border px-3 py-3 font-bold" style={{ borderColor: C.line, color: C.ink }}>القسم</th>
                    {shownColumns.map((column) => (
                      <th key={column.key} className="border px-3 py-3 font-bold" style={{ borderColor: C.line, color: C.ink }}>{column.label}</th>
                    ))}
                    <th className="border px-3 py-3 font-bold" style={{ borderColor: C.line, color: C.ink }}>الحالة</th>
                  </tr>
                </thead>
                <tbody>
                  {report.rows.length === 0 ? (
                    <tr>
                      <td colSpan={totalColumns} className="border px-5 py-10 text-center text-slate-500" style={{ borderColor: C.line }}>لا يوجد تلاميذ متخلفون لهذا الشهر والقسم.</td>
                    </tr>
                  ) : (
                    report.rows.map((row, index) => (
                      <tr key={row.enrollment_id} className={index % 2 === 1 ? 'bg-[#FAFCF8]' : undefined}>
                        <td className="border px-3 py-2.5 text-center tabular-nums text-slate-500" style={{ borderColor: C.line }}>{index + 1}</td>
                        <td className="border px-3 py-2.5 tabular-nums text-slate-700" style={{ borderColor: C.line }} dir="ltr">{row.student_code || '—'}</td>
                        <td className="border px-3 py-2.5 font-semibold text-slate-800" style={{ borderColor: C.line }}>{row.student_name}</td>
                        <td className="border px-3 py-2.5 text-slate-600" style={{ borderColor: C.line }}>{report.section.label}</td>
                        {shownColumns.map((column) => (
                          <td
                            key={column.key}
                            className={'border px-3 py-2.5 text-slate-700' + (column.kind === 'phone' ? ' tabular-nums tracking-wide' : '')}
                            style={{ borderColor: C.line }}
                            dir={column.kind === 'phone' ? 'ltr' : undefined}
                          >
                            {cellValue(row, column.key)}
                          </td>
                        ))}
                        <td className="border px-3 py-2.5" style={{ borderColor: C.line }}>
                          <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-700">غير مسدد</span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
