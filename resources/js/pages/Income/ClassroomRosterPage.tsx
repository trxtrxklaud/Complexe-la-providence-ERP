import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, Layers, Printer, RefreshCw } from 'lucide-react';
import {
  fetchClassroomRoster,
  fetchClassroomRosterOptions,
  type ClassroomRosterOptions,
  type ClassroomRosterReport,
  type RosterMonthStatus,
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
  grid: '#D9E1D0',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/**
 * دلالة ألوان الأشهر — قاعدة واحدة لا تتكرر في الملف:
 *  أخضر  خالص
 *  أحمر  فات الشهر ولم يدفع — متخلّد فعلاً
 *  أصفر  الشهر الجاري وما زالت أيامه — لا يُحاسب عليه بعد
 *  رمادي لم يأتِ دوره
 */
const STATUS: Record<RosterMonthStatus, { color: string; bg: string; label: string }> = {
  paid:     { color: '#2F7A3E', bg: '#E8F5EA', label: 'خالص' },
  late:     { color: '#A03434', bg: '#FDECEC', label: 'متخلّد' },
  due:      { color: '#B5820A', bg: '#FDF6E3', label: 'جارٍ — ما زالت أيامه' },
  upcoming: { color: '#9AA595', bg: '#F4F6F1', label: 'لم يأتِ بعد' },
};

/**
 * كشف مداخيل القسم — نفس الجدول الورقي القديم: سطر لكل تلميذ مرتّبين
 * أبجدياً، خانة لكل شهر دراسي، وأمام كل تلميذ ما تخلّد بذمّته.
 *
 * لماذا شهراً شهراً وليس مجموعاً: من خلّص شهرين من ثلاثة يظهر في المجموع
 * كمن دفع، والمطلوب معرفة أيّ شهر بقي لا كم ديناراً دخل.
 */
export function ClassroomRosterPage() {
  const [options, setOptions] = useState<ClassroomRosterOptions | null>(null);
  const [sectionId, setSectionId] = useState('');
  const [yearId, setYearId] = useState('');
  const [monthFrom, setMonthFrom] = useState('');
  const [monthTo, setMonthTo] = useState('');
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
        month_from: monthFrom || undefined,
        month_to: monthTo || undefined,
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
  }, [sectionId, yearId, monthFrom, monthTo, from, to, reloadKey]);

  const periodLabel = useMemo(() => {
    if (!from && !to) return 'كل الفترات';
    return `${from || 'البداية'} ← ${to || 'اليوم'}`;
  }, [from, to]);

  const monthOptions = data?.months ?? options?.months ?? [];
  const inputStyle = { border: `1px solid ${C.line}`, color: C.ink } as const;
  const cell = { border: `1px solid ${C.grid}` } as const;

  return (
    <div className="px-6 pb-10 max-w-full mx-auto" dir="rtl">
      <PrintStyles />

      {/*
        طباعة أفقية بلا هامش ورقة (المتصفّح يرسم الرابط والتاريخ داخل الهامش)،
        وحشو داخلي يعوّضه. وإلزام المتصفّح بإبقاء الألوان: كشف بلا أحمر وأخضر
        يفقد معناه ورقياً.

        وأهمّ من ذلك: الجدول هنا أعرض جدول في المنصة (عشرة أشهر + ستة أعمدة)،
        فلزم إجباره على عرض الورقة: table-layout: fixed مع كسر الكلمات وإلغاء
        whitespace-nowrap، وإلغاء overflow لأنّ شريط التمرير على الشاشة يصير قصّاً على الورق.
      */}
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 0; }
          * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

          #net-print-area { padding: 8mm !important; }

          #net-print-area .overflow-x-auto,
          #net-print-area .overflow-hidden {
            overflow: visible !important;
          }

          #net-print-area table {
            font-size: 8pt !important;
            width: 100% !important;
            max-width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
          }

          #net-print-area td,
          #net-print-area th {
            padding: 2px 3px !important;
            white-space: normal !important;
            word-break: break-word !important;
            overflow-wrap: break-word !important;
          }

          #net-print-area tr { page-break-inside: avoid; break-inside: avoid; }
          #net-print-area thead { display: table-header-group; }

          /* بطاقات الملخّص أربع في سطر واحد حتّى لا تأكل نصف الورقة */
          #net-print-area .grid { gap: 6px !important; }
        }
      `}</style>

      <div className="flex items-center gap-3 mb-4 no-print">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <Layers size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>كشف مداخيل القسم</h2>
          <p className="text-sm" style={{ color: C.muted }}>
            كل تلاميذ القسم مرتّبين أبجدياً، وحالة كل شهر لوناً، وما تخلّد بذمّة كل واحد
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
          <label htmlFor="roster_month_from" className="block text-sm mb-1" style={{ color: C.muted }}>
            من شهر
          </label>
          <select
            id="roster_month_from"
            name="roster_month_from"
            value={monthFrom}
            onChange={(e) => setMonthFrom(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={inputStyle}
          >
            <option value="">أول السنة</option>
            {monthOptions.map((month) => (
              <option key={month.key} value={month.key}>
                {month.label} {month.year}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="roster_month_to" className="block text-sm mb-1" style={{ color: C.muted }}>
            إلى شهر
          </label>
          <select
            id="roster_month_to"
            name="roster_month_to"
            value={monthTo}
            onChange={(e) => setMonthTo(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={inputStyle}
          >
            <option value="">آخر السنة</option>
            {monthOptions.map((month) => (
              <option key={month.key} value={month.key}>
                {month.label} {month.year}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="roster_from" className="block text-sm mb-1" style={{ color: C.muted }}>
            من تاريخ قبض
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
            إلى تاريخ قبض
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
              فترة القبض: {periodLabel} — تاريخ الطباعة: {data.report_date} {data.report_time}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-4 mb-3 text-xs">
            {(Object.keys(STATUS) as RosterMonthStatus[]).map((key) => (
              <span key={key} className="inline-flex items-center gap-2" style={{ color: C.muted }}>
                <span
                  className="inline-block w-3 h-3 rounded-full"
                  style={{ backgroundColor: STATUS[key].color }}
                  aria-hidden="true"
                />
                {STATUS[key].label}
              </span>
            ))}
            {data.reference_monthly_fee > 0 && (
              <span style={{ color: C.muted }}>القسط المرجعي: {money(data.reference_monthly_fee)}</span>
            )}
          </div>

          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>عدد التلاميذ</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{data.summary.students_count}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المداخيل</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.total)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>متخلّد الأشهر المنقضية</p>
              <p
                className="text-xl font-bold"
                style={{ color: data.summary.months_arrears > 0 ? C.error : C.ink }}
              >
                {money(data.summary.months_arrears)}
              </p>
              <p className="text-xs mt-1" style={{ color: C.muted }}>
                {data.summary.debtors_count} تلميذاً عليه شهر أو أكثر
              </p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>الرصيد المحاسبي</p>
              <p
                className="text-xl font-bold"
                style={{ color: data.summary.outstanding_total > 0 ? C.error : C.ink }}
              >
                {money(data.summary.outstanding_total)}
              </p>
              <p className="text-xs mt-1" style={{ color: C.muted }}>من جدول المعاليم</p>
            </div>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-3 py-2 font-semibold" style={{ ...cell, color: C.ink }}>#</th>
                    <th className="text-right px-3 py-2 font-semibold whitespace-nowrap" style={{ ...cell, color: C.ink }}>الإسم واللقب</th>
                    <th className="text-right px-3 py-2 font-semibold" style={{ ...cell, color: C.ink }}>الرقم</th>
                    {data.months.map((month) => (
                      <th key={month.key} className="text-center px-2 py-2 font-semibold" style={{ ...cell, color: C.ink }}>
                        {month.label}
                      </th>
                    ))}
                    <th className="text-right px-3 py-2 font-semibold" style={{ ...cell, color: C.ink }}>الأشهر الخالصة</th>
                    <th className="text-right px-3 py-2 font-semibold" style={{ ...cell, color: C.ink }}>مجموع المداخيل</th>
                    <th className="text-right px-3 py-2 font-semibold" style={{ ...cell, color: C.ink }}>متخلّد بالذمّة</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.length === 0 && (
                    <tr>
                      <td colSpan={data.months.length + 6} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                        لا يوجد تلاميذ مُرسّمون في هذا القسم للسنة المختارة.
                      </td>
                    </tr>
                  )}
                  {data.rows.map((row, index) => (
                    <tr key={row.student_id}>
                      <td className="px-3 py-2" style={{ ...cell, color: C.muted }}>{index + 1}</td>
                      <td className="px-3 py-2 whitespace-nowrap" style={cell}>
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
                      <td className="px-3 py-2 whitespace-nowrap" style={{ ...cell, color: C.muted }}>{row.student_code ?? '—'}</td>
                      {row.months.map((month) => {
                        const style = STATUS[month.status];
                        return (
                          <td
                            key={month.key}
                            className="px-2 py-2 text-center"
                            style={{ ...cell, backgroundColor: style.bg }}
                            title={`${month.label}: ${style.label}${month.payment_date ? ' — قُبض في ' + month.payment_date : ''}`}
                          >
                            <span
                              className="inline-block w-3 h-3 rounded-full"
                              style={{ backgroundColor: style.color }}
                              aria-hidden="true"
                            />
                            <span className="sr-only">{style.label}</span>
                            {month.status === 'paid' && month.amount > 0 && (
                              <span className="block text-[10px] mt-1" style={{ color: style.color }}>
                                {money(month.amount)}
                              </span>
                            )}
                          </td>
                        );
                      })}
                      <td className="px-3 py-2 text-center" style={{ ...cell, color: C.ink }}>
                        {row.paid_months} / {data.months.length}
                      </td>
                      <td className="px-3 py-2 font-semibold" style={{ ...cell, color: C.forest }}>{money(row.total)}</td>
                      <td
                        className="px-3 py-2 font-semibold whitespace-nowrap"
                        style={{ ...cell, color: row.late_count > 0 ? C.error : C.muted }}
                      >
                        {row.late_count > 0 ? (
                          <>
                            {money(row.months_arrears)}
                            <span className="block text-[10px] font-normal">{row.unpaid_months.join(' / ')}</span>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
                {data.rows.length > 0 && (
                  <tfoot>
                    <tr style={{ backgroundColor: C.sage }}>
                      <td className="px-3 py-2 font-bold" colSpan={3} style={{ ...cell, color: C.ink }}>المجموع العام</td>
                      {data.by_month.map((month) => (
                        <td key={month.key} className="px-2 py-2 text-center text-xs font-bold" style={{ ...cell, color: C.ink }}>
                          <span style={{ color: STATUS.paid.color }}>{month.paid_count}</span>
                          {' / '}
                          <span style={{ color: STATUS.late.color }}>{month.late_count}</span>
                        </td>
                      ))}
                      <td className="px-3 py-2" style={cell} />
                      <td className="px-3 py-2 font-bold" style={{ ...cell, color: C.forest }}>{money(data.summary.total)}</td>
                      <td
                        className="px-3 py-2 font-bold"
                        style={{ ...cell, color: data.summary.months_arrears > 0 ? C.error : C.ink }}
                      >
                        {money(data.summary.months_arrears)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          <p className="text-xs mt-3" style={{ color: C.muted }}>
            حالة الشهر تُقرأ من الأشهر التي اختارها القابض في الوصل، لا من تاريخ القبض:
            من خلّص ديسمبر في جانفي يبقى ديسمبر أخضر. المبالغ المقبوضة مقروءة من الدفتر النقدي،
            ومبلغ الشهر الواحد قسط الوصل الشهري مقسوماً بالتساوي على أشهره.
          </p>
        </div>
      )}
    </div>
  );
}
