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

const MONTH_NAMES_AR: Record<string, string> = {
  '01': 'جانفي',
  '02': 'فيفري',
  '03': 'مارس',
  '04': 'أفريل',
  '05': 'ماي',
  '06': 'جوان',
  '07': 'جويلية',
  '08': 'أوت',
  '09': 'سبتمبر',
  '10': 'أكتوبر',
  '11': 'نوفمبر',
  '12': 'ديسمبر',
};

function formatMonth(period: string): string {
  const [year, month] = period.split('-');
  return `${MONTH_NAMES_AR[month] ?? month} ${year}`;
}

/**
 * الدخل الصافي الشهري.
 *
 * الخادم يُرجع كل أشهر النطاق دفعة واحدة، واختيار الشهر مجرد انتقاء من نتيجة جاهزة.
 * لو أرسلتُ طلباً جديداً عند كل تغيير شهر لأمكن أن يقرأ المستخدِم شهرين من لحظتين
 * مختلفتين ثم يجمعهما ذهنياً فيخرج بمجموع لا يطابق الخزينة.
 */
export function NetRevenueMonthlyPage() {
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
        // غياب قائمة السنوات لا يجوز أن يمنع عرض الكشف نفسه.
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchNetIncomePeriods(
      {
        granularity: 'month',
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
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          >
            <option value="">كل السنوات</option>
            {years.map((year) => (
              <option key={year.id} value={year.id}>{year.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm mb-1" style={{ color: C.muted }}>الشهر</label>
          <select
            value={selected}
            onChange={(e) => setSelected(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm"
            style={{ border: `1px solid ${C.line}`, color: C.ink }}
          >
            {(data?.rows ?? []).length === 0 && <option value="">—</option>}
            {(data?.rows ?? []).map((row) => (
              <option key={row.period} value={row.period}>{formatMonth(row.period)}</option>
            ))}
          </select>
        </div>

        <p className="text-xs flex-1 min-w-[14rem]" style={{ color: C.muted }}>
          الأرقام من الدفتر النقدي المركزي: الاستخلاص والمصاريف والأجور والسلف والسحوبات.
        </p>
      </div>

      {error && (
        <div className="rounded-2xl p-4 mb-4 flex items-start gap-2 text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && <p className="text-sm py-6 text-center" style={{ color: C.muted }}>جارٍ التحميل…</p>}

      {!loading && data && (
        <>
          {selectedRow && (
            <>
              <NetTotalsCards row={selectedRow} />
              <NetFiguresPanel row={selectedRow} title={formatMonth(selectedRow.period)} />
            </>
          )}

          <h3 className="text-sm font-bold mb-2" style={{ color: C.ink }}>كل الأشهر</h3>
          <NetPeriodsTable
            rows={data.rows}
            selected={selected}
            onSelect={setSelected}
            periodLabel="الشهر"
            formatPeriod={formatMonth}
          />
        </>
      )}
    </div>
  );
}
