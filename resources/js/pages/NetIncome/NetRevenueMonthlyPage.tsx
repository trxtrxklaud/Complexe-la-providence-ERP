import { useEffect, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import {
  fetchAcademicYears,
  fetchNetIncomePeriods,
  type NetPeriodReport,
} from '../../api/netIncome';
import { errorMessage, today } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import { C, FilterBar, NetReportTable, PrintHeader, PrintStyles } from './NetPeriodPanels';

/** أسماء الأشهر بالاستعمال التونسي، مطابقة لما يستعمله CollectionService. */
const MONTH_NAMES_AR = [
  'جانفي',
  'فيفري',
  'مارس',
  'أفريل',
  'ماي',
  'جوان',
  'جويلية',
  'أوت',
  'سبتمبر',
  'أكتوبر',
  'نوفمبر',
  'ديسمبر',
];

function formatMonth(period: string): string {
  const [year, month] = period.split('-');
  const index = Number(month) - 1;
  return MONTH_NAMES_AR[index] ? `${MONTH_NAMES_AR[index]} ${year}` : period;
}

type YearOption = { id: number | string; name: string };

/**
 * الدخل الصافي الشهري — بنفس مرشِّحات الصفحة القديمة: السنة الدراسية ثم الشهر.
 *
 * يُطلب من الخادم طلب واحد لكلّ الأشهر، ثم يُنتقى الشهر محليّاً. لو جلبنا كلّ شهر
 * بطلب مستقلّ لأمكن أن يُجمّع المستخدم ذهنيّاً لقطتين مأخوذتين في لحظتين مختلفتين.
 *
 * الترشيح بالسنة الدراسية يتمّ على academic_year_id المخزَّن في سطر الدفتر، لا على
 * السنة التقويمية، لأنّ السنة الدراسية تمتدّ على سنتين تقويميتين.
 */
export function NetRevenueMonthlyPage() {
  const [years, setYears] = useState<YearOption[]>([]);
  const [yearId, setYearId] = useState('');
  const [month, setMonth] = useState(today().slice(0, 7));
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
        /* قائمة السنوات مساعِدة لا أكثر: فشلها لا يمنع عرض الكشف لكلّ السنوات. */
      });
    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchNetIncomePeriods(
      { granularity: 'month', academic_year_id: yearId || undefined },
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

  const row = (report?.rows ?? []).find((item) => item.period === month) ?? null;

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
        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>
            الشهر
          </label>
          <input
            type="month"
            value={month}
            onChange={(e) => setMonth(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
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

      {!loading && !error && (
        <div id="net-print-area">
          <PrintHeader date={formatMonth(month)} />

          {row ? (
            <NetReportTable
              caption={`الدخل الصافي لشهر ${formatMonth(row.period)}`}
              income={row.income}
              expenses={row.expenses}
              netIncome={row.net_income}
              withdrawals={row.withdrawals}
              balance={row.balance}
            />
          ) : (
            <div
              className="bg-white rounded-2xl p-8 text-center text-sm"
              style={{ border: `1px solid ${C.line}`, color: C.muted }}
            >
              لا توجد حركات مسجّلة في {formatMonth(month)}.
            </div>
          )}

          {(report?.rows ?? []).length > 0 && (
            <table className="w-full bg-white text-sm mt-4" style={{ border: `1px solid ${C.line}` }}>
              <thead>
                <tr style={{ backgroundColor: C.sage }}>
                  <th className="text-right px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                    الشهر
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
                  <tr
                    key={item.period}
                    onClick={() => setMonth(item.period)}
                    className="cursor-pointer"
                    style={{ backgroundColor: item.period === month ? '#F2F6EE' : undefined }}
                  >
                    <td className="px-4 py-2" style={{ border: `1px solid ${C.line}`, color: C.ink }}>
                      {formatMonth(item.period)}
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
