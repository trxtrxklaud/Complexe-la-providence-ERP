import { useCallback, useEffect, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { AlertCircle } from 'lucide-react';
import { errorMessage, money } from '../lib/format';
import type { Granularity, PeriodReportData } from '../api/reports';
import { PageDataSkeleton } from './DataSkeleton';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

type LoadParams = { granularity: Granularity; date_from: string; date_to: string };

type Props = {
  title: string;
  subtitle: string;
  icon: LucideIcon;
  granularity: Granularity;
  /** عنوان عمود الفترة: اليوم / الشهر / السنة */
  periodLabel: string;
  initialFrom: string;
  initialTo: string;
  load: (params: LoadParams, signal?: AbortSignal) => Promise<PeriodReportData>;
};

/**
 * تقرير دوري موحّد.
 *
 * التقارير اليومي والشهري والسنوي — للمصاريف أو للمداخيل — تستعمل هذا المكوّن
 * نفسه ولا تختلف إلا في درجة التجميع ونقطة القراءة. لو كُتب كل تقرير على حدة
 * لأصبح احتمال اختلاف مجموع الأشهر عن مجموع السنة مسألة وقت فقط.
 */
export function PeriodReport({
  title,
  subtitle,
  icon: Icon,
  granularity,
  periodLabel,
  initialFrom,
  initialTo,
  load,
}: Props) {
  const [from, setFrom] = useState(initialFrom);
  const [to, setTo] = useState(initialTo);
  const [data, setData] = useState<PeriodReportData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const run = useCallback(
    async (signal: AbortSignal) => {
      setLoading(true);
      setError('');
      try {
        const result = await load({ granularity, date_from: from, date_to: to }, signal);
        if (!signal.aborted) setData(result);
      } catch (e) {
        if (!signal.aborted) {
          setData(null);
          setError(errorMessage(e));
        }
      } finally {
        if (!signal.aborted) setLoading(false);
      }
    },
    [from, to, granularity, load],
  );

  useEffect(() => {
    const controller = new AbortController();
    void run(controller.signal);
    return () => controller.abort();
  }, [run]);

  const columns = data?.summary.by_category ?? [];

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <div className="flex items-center gap-3 mb-4">
        <div
          className="w-10 h-10 rounded-2xl flex items-center justify-center"
          style={{ backgroundColor: C.sage }}
        >
          <Icon size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>
            {title}
          </h2>
          <p className="text-sm" style={{ color: C.muted }}>
            {subtitle}
          </p>
        </div>
      </div>

      <div
        className="bg-white rounded-2xl p-4 mb-4 flex flex-wrap items-end gap-4"
        style={{ border: `1px solid ${C.line}` }}
      >
        <div>
          <label htmlFor="period-report-from" className="block text-sm mb-1" style={{ color: C.muted }}>
            من تاريخ
          </label>
          <input
            id="period-report-from"
            type="date"
            value={from}
            max={to}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
        <div>
          <label htmlFor="period-report-to" className="block text-sm mb-1" style={{ color: C.muted }}>
            إلى تاريخ
          </label>
          <input
            id="period-report-to"
            type="date"
            value={to}
            min={from}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
        <p className="text-xs flex-1 min-w-[12rem]" style={{ color: C.muted }}>
          الأرقام تُقرأ من دفتر الخزينة المركزي، والحركات الملغاة مستثناة دائماً.
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
        <PageDataSkeleton cards={3} />
      )}

      {!loading && data && (
        <>
          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>
                المجموع العام
              </p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>
                {money(data.summary.total)}
              </p>
            </div>
            {columns.map((line) => (
              <div
                key={line.category}
                className="bg-white rounded-2xl p-4"
                style={{ border: `1px solid ${C.line}` }}
              >
                <p className="text-sm mb-1" style={{ color: C.muted }}>
                  {line.label}
                </p>
                <p className="text-lg font-semibold" style={{ color: C.ink }}>
                  {money(line.total)}
                </p>
              </div>
            ))}
          </div>

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>
                      {periodLabel}
                    </th>
                    {columns.map((line) => (
                      <th
                        key={line.category}
                        className="text-right px-4 py-3 font-semibold whitespace-nowrap"
                        style={{ color: C.ink }}
                      >
                        {line.label}
                      </th>
                    ))}
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>
                      المجموع
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.length === 0 && (
                    <tr>
                      <td
                        colSpan={columns.length + 2}
                        className="px-4 py-8 text-center"
                        style={{ color: C.muted }}
                      >
                        لا توجد حركات في هذه الفترة.
                      </td>
                    </tr>
                  )}
                  {data.rows.map((row) => {
                    const byCategory = new Map<string, number>(
                      row.by_category.map((line): [string, number] => [line.category, line.total]),
                    );
                    return (
                      <tr key={row.period} style={{ borderTop: `1px solid ${C.line}` }}>
                        <td className="px-4 py-3 whitespace-nowrap" style={{ color: C.ink }}>
                          {row.period}
                        </td>
                        {columns.map((line) => (
                          <td key={line.category} className="px-4 py-3" style={{ color: C.muted }}>
                            {money(byCategory.get(line.category) ?? 0)}
                          </td>
                        ))}
                        <td className="px-4 py-3 font-semibold" style={{ color: C.forest }}>
                          {money(row.total)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                {data.rows.length > 0 && (
                  <tfoot>
                    <tr style={{ backgroundColor: C.sage }}>
                      <td className="px-4 py-3 font-bold" style={{ color: C.ink }}>
                        المجموع ({data.summary.periods_count})
                      </td>
                      {columns.map((line) => (
                        <td key={line.category} className="px-4 py-3 font-semibold" style={{ color: C.ink }}>
                          {money(line.total)}
                        </td>
                      ))}
                      <td className="px-4 py-3 font-bold" style={{ color: C.forest }}>
                        {money(data.summary.total)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
