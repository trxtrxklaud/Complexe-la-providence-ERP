import { useEffect, useState } from 'react';
import { AlertCircle, CalendarRange } from 'lucide-react';
import { fetchYearRevenue, type YearRevenueReport } from '../../api/reports';
import { errorMessage, money } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';

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
 * المداخيل حسب السنة الدراسية — مداخيل ومصاريف ودخل صافٍ ورصيد لكل سنة.
 */
export function RevenueByYearPage() {
  const [data, setData] = useState<YearRevenueReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    fetchYearRevenue(controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) setData(result);
      })
      .catch((e) => {
        if (!controller.signal.aborted) setError(errorMessage(e));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, []);

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <div className="flex items-center gap-3 mb-4">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
          <CalendarRange size={20} style={{ color: C.forest }} />
        </div>
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>المداخيل حسب السنة</h2>
          <p className="text-sm" style={{ color: C.muted }}>مقارنة السنوات الدراسية من الدفتر المركزي</p>
        </div>
      </div>

      {error && (
        <div className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && <PageDataSkeleton cards={4} rows={4} />}

      {!loading && data && (
        <>
          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(10rem, 1fr))' }}>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المداخيل</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.income)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المصاريف</p>
              <p className="text-xl font-bold" style={{ color: C.error }}>{money(data.summary.expenses)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>الدخل الصافي</p>
              <p className="text-xl font-bold" style={{ color: C.ink }}>{money(data.summary.net_income)}</p>
            </div>
            <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
              <p className="text-sm mb-1" style={{ color: C.muted }}>الرصيد</p>
              <p className="text-xl font-bold" style={{ color: C.forest }}>{money(data.summary.balance)}</p>
            </div>
          </div>

          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>السنة الدراسية</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المداخيل</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المصاريف</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>الدخل الصافي</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>السحوبات</th>
                    <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>الرصيد</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                        لا توجد حركات مسجّلة بعد.
                      </td>
                    </tr>
                  )}
                  {data.rows.map((row) => (
                    <tr key={row.academic_year_id ?? 'none'} style={{ borderTop: `1px solid ${C.line}` }}>
                      <td className="px-4 py-3 whitespace-nowrap" style={{ color: C.ink }}>{row.academic_year}</td>
                      <td className="px-4 py-3" style={{ color: C.forest }}>{money(row.income)}</td>
                      <td className="px-4 py-3" style={{ color: C.error }}>{money(row.expenses)}</td>
                      <td className="px-4 py-3 font-semibold" style={{ color: C.ink }}>{money(row.net_income)}</td>
                      <td className="px-4 py-3" style={{ color: C.muted }}>{money(row.withdrawals)}</td>
                      <td className="px-4 py-3 font-bold" style={{ color: C.forest }}>{money(row.balance)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
