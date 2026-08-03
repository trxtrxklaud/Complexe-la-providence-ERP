import { useEffect, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import {
  fetchAcademicYears,
  fetchNetIncomePeriods,
  type NetPeriodReport,
} from '../../api/netIncome';
import { errorMessage } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import { C, FilterBar, NetReportTable, PrintHeader, PrintStyles } from './NetPeriodPanels';

type YearOption = { id: number | string; name: string };

/**
 * الدخل الصافي السنوي — مرشِّح واحد كما في الصفحة القديمة: السنة الدراسية.
 *
 * الكشف المعروض هو مجموع السنة الدراسية كاملة (summary)، لا السنة التقويمية،
 * لأنّ السنة الدراسية تمتدّ على سنتين تقويميتين (سبتمبر → جوان)، فخلطهما يعطي
 * رقماً لا يطابق التقويم المدرسي. ويُعرَض تحته تفصيل السنوات التقويمية للشفافية.
 */
export function NetRevenueYearlyPage() {
  const [years, setYears] = useState<YearOption[]>([]);
  const [yearId, setYearId] = useState('');
  const [report, setReport] = useState<NetPeriodReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    fetchAcademicYears(controller.signal)
      .then((list) => {
        if (!controller.signal.aborted) setYears(list as YearOption[]);
      })
      .catch(() => {
        /* فشل قائمة السنوات لا يمنع عرض الكشف العامّ. */
      });
    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchNetIncomePeriods(
      { granularity: 'year', academic_year_id: yearId || undefined },
      controller.signal,
    )
      .then((result) => {
        if (!controller.signal.aborted) setReport(result);
      })
      .catch((e) => {
        if (!controller.signal.aborted) {
          setReport(null);
          setError(errorMessage(e));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [yearId]);

  const selectedYearName =
    years.find((year) => String(year.id) === yearId)?.name ?? 'كلّ السنوات';
  const summary = report?.summary ?? null;

  return (
    <div className="px-6 pb-10" dir="rtl">
      <PrintStyles />

      <FilterBar>
        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>
            السنة الدراسية
          </label>
          <select
            value={yearId}
            onChange={(e) => setYearId(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm bg-white"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          >
            <option value="">كلّ السنوات</option>
            {years.map((year) => (
              <option key={year.id} value={String(year.id)}>
                {year.name}
              </option>
            ))}
          </select>
        </div>
      </FilterBar>

      {error && (
        <div
          className="no-print rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm"
          style={{ backgroundColor: C.errorBg, color: C.error }}
        >
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && (
        <div className="no-print"><PageDataSkeleton cards={2} rows={4} /></div>
      )}

      {!loading && !error && summary && (
        <div id="net-print-area">
          <PrintHeader date={selectedYearName} />

          <NetReportTable
            caption={`الدخل الصافي للسنة الدراسية: ${selectedYearName}`}
            income={summary.income}
            expenses={summary.expenses}
            netIncome={summary.net_income}
            withdrawals={summary.withdrawals}
            balance={summary.balance}
          />

          {(report?.rows ?? []).length > 1 && (
            <table className="w-full bg-white text-sm mt-4" style={{ border: `1px solid ${C.line}` }}>
              <thead>
                <tr style={{ backgroundColor: C.sage }}>
                  <th className="text-right px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                    السنة التقويمية
                  </th>
                  <th className="text-right px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                    المداخيل
                  </th>
                  <th className="text-right px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                    المصاريف
                  </th>
                  <th className="text-right px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                    الدخل الصافي
                  </th>
                </tr>
              </thead>
              <tbody>
                {(report?.rows ?? []).map((item) => (
                  <tr key={item.period}>
                    <td className="px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                      {item.period}
                    </td>
                    <td className="px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.forest }}>
                      {item.income.total.toFixed(3)}
                    </td>
                    <td className="px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.error }}>
                      {item.expenses.total.toFixed(3)}
                    </td>
                    <td className="px-4 py-2 font-bold" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                      {item.net_income.toFixed(3)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
