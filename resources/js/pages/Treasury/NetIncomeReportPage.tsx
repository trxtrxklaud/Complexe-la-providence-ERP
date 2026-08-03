import { useEffect, useState } from 'react';
import { AlertCircle, Printer, TrendingUp } from 'lucide-react';
import { fetchNetIncome, type NetIncomeReport, type PeriodSummary } from '../../api/reports';
import { errorMessage, money, today } from '../../lib/format';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/**
 * سطر من أسطر الكشف.
 */
function Line({
  label,
  value,
  strong = false,
  color,
}: {
  key?: string | number;
  label: string;
  value: number;
  strong?: boolean;
  color?: string;
}) {
  return (
    <div
      className="flex items-center justify-between px-4 py-2 text-sm"
      style={{ borderTop: `1px solid ${C.line}` }}
    >
      <span style={{ color: strong ? C.ink : C.muted, fontWeight: strong ? 700 : 400 }}>{label}</span>
      <span style={{ color: color ?? (strong ? C.ink : C.muted), fontWeight: strong ? 700 : 500 }}>
        {money(value)}
      </span>
    </div>
  );
}

/**
 * لوح كشف واحد: بنود المداخيل ثم بنود المصاريف ثم الخواتم،
 * بترتيب ثابت للبنود حتّى يمكن مقابلة أيّ طباعة بالشاشة سطراً بسطر.
 */
function StatementPanel({
  title,
  subtitle,
  data,
}: {
  title: string;
  subtitle: string;
  data: PeriodSummary;
}) {
  return (
    <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
      <div className="px-4 py-3" style={{ backgroundColor: C.sage }}>
        <p className="font-bold" style={{ color: C.ink }}>
          {title}
        </p>
        <p className="text-xs" style={{ color: C.muted }}>
          {subtitle}
        </p>
      </div>

      <p className="px-4 pt-3 pb-1 text-xs font-semibold" style={{ color: C.forest }}>
        المداخيل
      </p>
      {data.income.lines.map((line) => (
        <Line key={line.category} label={line.label} value={line.total} />
      ))}
      <Line label="مجموع المداخيل" value={data.income.total} strong color={C.forest} />

      <p className="px-4 pt-4 pb-1 text-xs font-semibold" style={{ color: C.error }}>
        المصاريف
      </p>
      {data.expenses.lines.map((line) => (
        <Line key={line.category} label={line.label} value={line.total} />
      ))}
      <Line label="مجموع المصاريف" value={data.expenses.total} strong color={C.error} />

      <div className="mt-4" style={{ backgroundColor: '#F7F9F4' }}>
        <Line label="الدخل الصافي" value={data.net_income} strong />
        <Line label="السحوبات" value={data.withdrawals} />
        <Line label="الرصيد النهائي" value={data.balance} strong color={C.forest} />
      </div>
    </div>
  );
}

/**
 * كشف الدخل الصافي.
 *
 * العمودان ليسا تقريرين منفصلين بل قراءتان لنفس الدالة على الخادم:
 * الأول ليوم واحد والثاني من بداية السجل حتّى ذلك اليوم، فيستحيل أن يختلف منطق
 * اليومي عن منطق التراكمي. والسحب لا يُحتسب مصروفاً لأنّه نقل أموال لا استهلاك،
 * لكنّه يُنقِص الرصيد النهائي.
 *
 * التاريخ المعروض هو تاريخ الدفع المُدوّن في الحركة، لا تاريخ إدخال البيانات:
 * دفعة سُجّلت اليوم بتاريخ أمس تظهر في كشف أمس.
 */
export function NetIncomeReportPage() {
  const [date, setDate] = useState(today());
  const [showDetails, setShowDetails] = useState(false);
  const [data, setData] = useState<NetIncomeReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');
    fetchNetIncome({ date, details: showDetails }, controller.signal)
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
  }, [date, showDetails]);

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <div className="flex items-center gap-3 mb-4">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <TrendingUp size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>
            كشف الدخل الصافي
          </h2>
          <p className="text-sm" style={{ color: C.muted }}>
            كشف يوم واحد وكشف تراكمي حتّى نفس اليوم
          </p>
        </div>
      </div>

      <div
        className="bg-white rounded-2xl p-4 mb-4 flex flex-wrap items-end gap-4"
        style={{ border: `1px solid ${C.line}` }}
      >
        <div>
          <label htmlFor="net_income_date" className="block text-sm mb-1" style={{ color: C.muted }}>
            التاريخ
          </label>
          <input
            id="net_income_date"
            name="net_income_date"
            type="date"
            autoComplete="off"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            aria-describedby="net_income_date_hint"
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
        <label htmlFor="net_income_details" className="flex items-center gap-2 text-sm" style={{ color: C.ink }}>
          <input
            id="net_income_details"
            name="net_income_details"
            type="checkbox"
            checked={showDetails}
            onChange={(e) => setShowDetails(e.target.checked)}
          />
          إظهار الحركات التفصيلية لليوم
        </label>
        <button
          type="button"
          onClick={() => window.print()}
          className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm text-white"
          style={{ backgroundColor: C.forest }}
        >
          <Printer size={16} />
          طباعة
        </button>
        <p id="net_income_date_hint" className="w-full text-xs" style={{ color: C.muted }}>
          الكشف يتبع تاريخ الدفع المُدوّن في الوصل، لا تاريخ إدخاله في المنصة.
        </p>
      </div>

      {error && (
        <div
          className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm"
          style={{ backgroundColor: C.errorBg, color: C.error }}
        >
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && (
        <p className="text-sm py-6 text-center" style={{ color: C.muted }}>
          جارٍ التحميل…
        </p>
      )}

      {!loading && data && (
        <>
          <div className="grid gap-4 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(18rem, 1fr))' }}>
            <StatementPanel title="كشف اليوم" subtitle={data.date} data={data.day} />
            <StatementPanel title="الكشف التراكمي" subtitle={`من بداية السجل إلى ${data.date}`} data={data.cumulative} />
          </div>

          {showDetails && (
            <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
              <div className="px-4 py-3" style={{ backgroundColor: C.sage }}>
                <p className="font-bold" style={{ color: C.ink }}>
                  حركات يوم {data.date}
                </p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ borderTop: `1px solid ${C.line}` }}>
                      <th className="text-right px-4 py-2 font-semibold" style={{ color: C.ink }}>
                        البند
                      </th>
                      <th className="text-right px-4 py-2 font-semibold" style={{ color: C.ink }}>
                        البيان
                      </th>
                      <th className="text-right px-4 py-2 font-semibold" style={{ color: C.ink }}>
                        داخل
                      </th>
                      <th className="text-right px-4 py-2 font-semibold" style={{ color: C.ink }}>
                        خارج
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {(data.details ?? []).length === 0 && (
                      <tr>
                        <td colSpan={4} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                          لا توجد حركات في هذا اليوم.
                        </td>
                      </tr>
                    )}
                    {(data.details ?? []).map((line) => (
                      <tr key={line.id} style={{ borderTop: `1px solid ${C.line}` }}>
                        <td className="px-4 py-2 whitespace-nowrap" style={{ color: C.ink }}>
                          {line.label}
                        </td>
                        <td className="px-4 py-2" style={{ color: C.muted }}>
                          {line.description ?? '—'}
                        </td>
                        <td className="px-4 py-2" style={{ color: C.forest }}>
                          {line.direction === 'in' ? money(line.amount) : '—'}
                        </td>
                        <td className="px-4 py-2" style={{ color: C.error }}>
                          {line.direction === 'out' ? money(line.amount) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
