import { useEffect, useMemo, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import {
  fetchAcademicYears,
  fetchNetIncomePeriods,
  type AcademicYearOption,
  type NetPeriodReport,
} from '../../api/netIncome';
import { errorMessage } from '../../lib/format';
import { NetFiguresPanel, NetPeriodsTable, NetTotalsCards } from './NetPeriodPanels';

const C = {
  forest: '#3B4A36',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/**
 * الدخل الصافي السنوي.
 *
 * تمييز مقصود: الأسطر مجمّعة حسب السنة التقويمية للحركة النقدية، بينما
 * منتقي «السنة الدراسية» يرشّح حسب academic_year_id المخزّن في السطر.
 * السنة الدراسية تعبر سنتين تقويميتين، فخلطهما في عمود واحد ينتج رقماً لا يفهمه أحد.
 */
export function NetRevenueYearlyPage() {
  const [years, setYears] = useState<AcademicYearOption[]>([]);
  const [yearId, setYearId] = useState('');
  const [selected, setSelected] = useState('');
  const [data, setData] = useState<NetPeriodReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    fetchAcademicYears(controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) setYears(result);
      })
      .catch(() => {
        // لا يحجب الكشف.
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchNetIncomePeriods(
      {
        granularity: 'year',
        academic_year_id: yearId ? Number(yearId) : undefined,
      },
      controller.signal,
    )
      .then((result) => {
        if (controller.signal.aborted) return;
        setData(result);
        setSelected(result.rows.length > 0 ? result.rows[result.rows.length - 1].period : '');
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
  }, [yearId]);

  const selectedRow = useMemo(
    () => data?.rows.find((row) => row.period === selected) ?? null,
    [data, selected],
  );

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <div className="bg-white rounded-2xl p-4 mb-4 flex flex-wrap items-end gap-4" style={{ border: `1px solid ${C.line}` }}>
        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>السنة الدراسية</label>
          <select
            value={yearId}
            onChange={(e) => setYearId(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line