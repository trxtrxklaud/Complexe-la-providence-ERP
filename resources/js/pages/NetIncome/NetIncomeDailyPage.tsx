import { useEffect, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import { fetchNetIncome, type NetIncomeReport } from '../../api/reports';
import { errorMessage, today } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import {
  C,
  FilterBar,
  NetReportTable,
  PrintHeader,
  PrintStyles,
  type DetailLine,
} from './NetPeriodPanels';

/**
 * الدخل الصافي اليومي — كشف يومي بتراكمي من بداية السجل حتّى التاريخ المختار
 * (تاريخ + خانة «عرض التفاصيل» + طباعة، ثم جدول بعمودين).
 *
 * الكشف التراكمي موضوع أسفل كشف اليوم لأنّ الإدارة تحتاج رقم اليوم ورصيد
 * الصندوق المتراكم في نفس النظرة، وفصلهما في صفحتين يُخفي الأثر الفوري لحركة اليوم.
 *
 * العمودان قراءتان لنفس دالة الخادم: الأولى ليوم واحد والثانية من بداية
 * السجلّ إلى ذلك اليوم، فيستحيل أن يختلف منطق اليومي عن منطق التراكمي.
 */
export function NetIncomeDailyPage() {
  const [date, setDate] = useState(today());
  const [showDetails, setShowDetails] = useState(true);
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

  const details = (data?.details ?? []) as DetailLine[];
  const incomeDetails = details.filter((line) => line.direction === 'in');
  const expenseDetails = details.filter((line) => line.direction === 'out');

  return (
    <div className="px-6 pb-10" dir="rtl">
      <PrintStyles />

      <FilterBar>
        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>
            التاريخ
          </label>
          <input
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          />
        </div>
        <label className="flex items-center gap-2 text-sm pb-2" style={{ color: C.ink }}>
          <input
            type="checkbox"
            checked={showDetails}
            onChange={(e) => setShowDetails(e.target.checked)}
          />
          عرض التفاصيل
        </label>
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

      {!loading && data && (
        <div id="net-print-area">
          <PrintHeader date={data.date} />

          <NetReportTable
            caption={`تقرير يوم ${data.date}`}
            income={data.day.income}
            expenses={data.day.expenses}
            netIncome={data.day.net_income}
            withdrawals={data.day.withdrawals}
            balance={data.day.balance}
            incomeDetails={incomeDetails}
            expenseDetails={expenseDetails}
            showDetails={showDetails}
          />

          <NetReportTable
            caption={`الكشف التراكمي من بداية السجلّ إلى ${data.date}`}
            income={data.cumulative.income}
            expenses={data.cumulative.expenses}
            netIncome={data.cumulative.net_income}
            withdrawals={data.cumulative.withdrawals}
            balance={data.cumulative.balance}
          />
        </div>
      )}
    </div>
  );
}
