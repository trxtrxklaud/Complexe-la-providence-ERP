import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, Layers, Printer, RefreshCw } from 'lucide-react';
import {
  fetchClassroomRoster,
  fetchClassroomRosterOptions,
  type ClassroomRosterOptions,
  type ClassroomRosterReport,
} from '../../api/reportDetails';
import { errorMessage, money } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import { PrintHeader, PrintStyles } from '../NetIncome/NetPeriodPanels';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/**
 * كشف مداخيل القسم — نفس الجدول الورقي القديم: سطر لكل تلميذ مرتّبين
 * أبجدياً، عمود لكل بند مداخيل، وأمام كل تلميذ ما تخلّد بذمّته.
 *
 * من لم يدفع يظهر أيضاً بأصفار: كشف يخفي غير الدافعين يخفي بالضبط من
 * يُراد من الكشف أن يكشفهم.
 */
export function ClassroomRosterPage() {
  const [options, setOptions] = useState<ClassroomRosterOptions | null>(null);
  const [sectionId, setSectionId] = useState('');
  const [yearId, setYearId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [data, setData] = useState<ClassroomRosterReport | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    fetchClassroomRosterOptions(controller.signal)
      .then((result) => {
        if (controller.signal.aborted) return;
        setOptions(result);
        if (result.active_year_id) setYearId(String(result.active_year_id));
      })
      .catch((e) => {
        if (!controller.signal.aborted) setError(errorMessage(e));
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    if (!sectionId) {
      setData(null);
      return;
    }

    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchClassroomRoster(
      sectionId,
      {
        date_from: from || undefined,
        date_to: to || undefined,
        academic_year_id: yearId ? Number(yearId) : undefined,
      },
      controller.signal,
    )
      .then((result) => {
        if (!controller.signal.aborted) setData(result);
      })
      .catch((e) => {
        if (!controller.signal.aborted) {
          setData(null);
          setError(errorMessage(e));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [sectionId, yearId, from, to, reloadKey]);

  const periodLabel = useMemo(() => {
    if (!from && !to) return 'كل الفترات';
    return `${from || 'البداية'} ← ${to || 'اليوم'}`;
  }, [from, to]);

  const inputStyle = { border: `1px solid ${C.line}`, color: C.ink } as const;

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <PrintStyles />

      <div className="flex items-center gap-3 mb-4 no-print">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <Layers size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>كشف مداخيل القسم</h2>
          <p className="text-sm" style={{ color: C.muted }}>
            كل تلاميذ القسم مرتّبين أبجدياً، مع ما دفع كل واحد مفصّلاً وما تخلّد بذمّته
          </p>
        </div>
      </div>

      <div
        className="bg-white rounded-2xl p-4 mb-4 flex flex-wrap items-end gap-4 no-print"
        style={{ border: `1px solid ${C.line}` }}
      >
        <div>
          <label htmlFor="roster_section_id" className="block text-sm mb-1" style={{ color: C.muted }}>
            القسم
          </label>
          <select
            id="roster_section_id"
            name="roster_section_id"
            value={sectionId}
            onChange={(e) => setSectionId(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm min-w-[14rem]"
            style={inputStyle}
          >
            <option value="">حدّد القسم…</option>
            {(options?.sections ?? []).map((section) => (
              <option key={section.id} value={section.id}>
                {section.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="roster_year_id" className="block text-sm mb-1" style={{ color: C.muted }}>
            السنة الدراسية
          </label>
          <select
            id="roster_year_id"
            name="roster_year_id"
            value={yearId}
            onChange={(e) => setYearId(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={inputStyle}
          >
            {(options?.years ?? []).map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="roster_from" className="block text-sm mb-1" style={{ color: C.muted }}>
            من تاريخ
          </label>
          <input
            id="roster_from"
            name="roster_from"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={inputStyle}
          />
        </div>

        <div>
          <label htmlFor="roster_to" className="block text-sm mb-1" style={{ color: C.muted }}>
            إلى تاريخ
          </label>
          <input
            id="roster_to"
            name="roster_to"
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={inputStyle}
          />
        </div>

        <button
          type="button"
          onClick={() => setReloadKey((key) => key + 1)}
          disabled={!sectionId || loading}
          className="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm disabled:opacity-50"
          style={{ border: `1px solid ${C.line}`, color: C.forest }}
        >
          <RefreshCw size={16} />
          <span>تحديث</span>
        </button>

        <button
          type="button"
          onClick={() => window.print()}
          disabled={!data}
          className="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm text-white disabled:opacity-50"
          style={{ backgroundColor: C.deep }}
        >
          <Printer size={16} />
          <span>طباعة التقرير</span>
        </button>
      </div>

      {error && (
        <div
          className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm no-print"
          style={{ backgroundColor: C.errorBg, color: C.error }}
          role="alert"
        >
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {!sectionId && !error && (
        <div
          className="bg-white rounded-2xl p-8 text-center text-sm no-print"
          style={{ border: `1px solid ${C.line}`, color: C.muted }}
        >
          اختر قسماً من القائمة ليظهر كشف تلاميذه ومداخيله.
        </div>
      )}

      {loading && <PageDataSkeleton />}

      {!loading && data && (
        <div id="net-print-area">
          <PrintHeader date={data.report_date} />

          <div className="mb-3">
            <h3 className="text-base font-bold" style={{ color: C.ink }}>
              مداخيل قسم {data.section.label} — السنة الدراسية {data.academic_year?.name ?? '—'}
            </h3>
            <p className="text-sm" style={{ color: C.muted }}>
              الفترة: {periodLabel} — تاريخ الطباعة: {data.report_date} {data.report_time}
            </p>
          </div>

          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>عدد التلاميذ</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.students_count}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>من دفع</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.payers_count}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المداخيل</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المتخلَّد</p>
              <p
                className="text-xl font-bold"
                style={{ color: data.summary.outstanding_total > 0 ? C.error : C.ink }}
              >
                {money(data.summary.outstanding_total)}
              </p>
              <p className="text-xs mt-1" style={{ color: C.muted }}>
                {data.summary.debtors_count} تلميذاً عليه متخلّد
              </p>
            </div>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-3 py-3 font-semibold" style={{ color: C.ink }}>#</th>
                    <th className="text-right px-3 py-3 font-semibold" style={{ color: C.ink }}>الإسم واللقب</th>
                    <th className="text-right px-3 py-3 font-semibold" style={{ color: C.ink }}>رقم التلميذ</th>
                    {data.categories.map((column) => (
                      <th key={column.category} className="text-right px-3 py-3 font-semibold" style={{ color: C.ink }}>
                        {column.label}
                      </th>
                    ))}
                    <th className="text-right px-3 py-3 font-semibold" style={{ color: C.ink }}>مجموع المداخيل</th>
                    <th className="text-right px-3 py-3 font-semibold" style={{ color: C.ink }}>متخلّد بالذمّة</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.length === 0 && (
                    <tr>
                      <td colSpan={data.categories.length + 5} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                        لا يوجد تلاميذ مُرسّمون في هذا القسم للسنة المختارة.
                      </td>
                    </tr>
                  )}
                  {data.rows.map((row, index) => (
                    <tr key={row.student_id} style={{ borderTop: `1px solid ${C.line}` }} className="hover:bg-[#F7F9F4]">
                      <td className="px-3 py-2" style={{ color: C.muted }}>{index + 1}</td>
                      <td className="px-3 py-2">
                        <Link
                          to={`/income/revenue/${row.student_id}`}
                          className="font-semibold hover:underline"
                          style={{ color: C.forest }}
                        >
                          {row.name}
                        </Link>
                        {!row.enrolled && (
                          <span className="text-xs mr-2" style={{ color: C.muted }}>(دفع دون ترسيم قائم)</span>
                        )}
                      </td>
                      <td className="px-3 py-2 whitespace-nowrap" style={{ color: C.muted }}>{row.student_code ?? '—'}</td>
                      {data.categories.map((column) => {
                        const value = row.by_category[column.category] ?? 0;
                        return (
                          <td
                            key={column.category}
                            className="px-3 py-2"
                            style={{ color: value > 0 ? C.ink : C.muted }}
                          >
                            {value > 0 ? money(value) : '—'}
                          </td>
                        );
                      })}
                      <td className="px-3 py-2 font-semibold" style={{ color: C.forest }}>{money(row.total)}</td>
                      <td
                        className="px-3 py-2 font-semibold"
                        style={{ color: row.outstanding > 0 ? C.error : C.muted }}
                      >
                        {row.outstanding > 0 ? money(row.outstanding) : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
                {data.rows.length > 0 && (
                  <tfoot>
                    <tr style={{ backgroundColor: C.sage }}>
                      <td className="px-3 py-3 font-bold" colSpan={3} style={{ color: C.ink }}>المجموع العام</td>
                      {data.by_category.map((line) => (
                        <td key={line.category} className="px-3 py-3 font-bold" style={{ color: C.ink }}>
                          {money(line.total)}
                        </td>
                      ))}
                      <td className="px-3 py-3 font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</td>
                      <td
                        className="px-3 py-3 font-bold"
                        style={{ color: data.summary.outstanding_total > 0 ? C.error : C.ink }}
                      >
                        {money(data.summary.outstanding_total)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          <p className="text-xs mt-3" style={{ color: C.muted }}>
            المبالغ المقبوضة مقروءة من الدفتر النقدي، والمتخلّد محسوب من المعاليم المستحقّة ناقص ما خُلّص منها.
          </p>
        </div>
      )}
    </div>
  );
}
