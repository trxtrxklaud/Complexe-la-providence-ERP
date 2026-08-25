import { useEffect, useState } from 'react';
import { CalendarRange, Printer, Loader2, AlertCircle } from 'lucide-react';
import {
  fetchTreasuryDaybook,
  type DaybookReport,
  type DaybookDay,
  type DaybookDetail,
  type DaybookLine,
} from '../../api/treasury';
import { errorMessage, money, today } from '../../lib/format';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  bg: '#F4F6F1',
  error: '#A03434',
  errorBg: '#FDECEC',
};

const SCHOOL_AR = 'مركب العناية للتعليم الخاص';
const CITY_AR = 'سيدي بوزيد';

type Mode = 'day' | 'month' | 'months' | 'range';

const MODE_LABELS: Record<Mode, string> = {
  day: 'يوم واحد',
  month: 'شهر',
  months: 'أشهر',
  range: 'مدى حرّ',
};

/** أوّل وآخر يوم في شهر بصيغة YYYY-MM — مع مراعاة السنة الكبيسة. */
function monthBounds(month: string): { from: string; to: string } {
  const [year, mon] = month.split('-').map(Number);
  const lastDay = new Date(year, mon, 0).getDate();
  return { from: month + '-01', to: month + '-' + String(lastDay).padStart(2, '0') };
}

function currentMonth(): string {
  return today().slice(0, 7);
}

/** مبلغ ملوّن: السالب أحمر دائماً حتى لا يمرّ رصيد سالب دون أن يُرى. */
function Amount({ value, bold }: { value: number; bold?: boolean }) {
  const negative = value < 0;
  return (
    <span
      style={{ color: negative ? C.error : C.ink, fontWeight: bold ? 700 : 500 }}
      dir="ltr"
      className="inline-block"
    >
      {money(value)} د
    </span>
  );
}

function LineList({ lines }: { lines: DaybookLine[] }) {
  return (
    <ul className="space-y-1 text-sm">
      {lines.map((l) => (
        <li key={l.category} className="flex items-center justify-between gap-3">
          <span style={{ color: l.total > 0 ? C.ink : C.muted }}>{l.label}</span>
          <span style={{ color: l.total > 0 ? C.ink : C.muted }} dir="ltr">
            {money(l.total)} د
          </span>
        </li>
      ))}
    </ul>
  );
}

function DetailList({ title, items }: { title: string; items: DaybookDetail[] }) {
  if (items.length === 0) return null;

  return (
    <div className="mt-3 pt-3" style={{ borderTop: `1px dashed ${C.line}` }}>
      <div className="text-xs mb-1" style={{ color: C.muted }}>{title}</div>
      <ul className="space-y-1 text-xs">
        {items.map((d) => (
          <li key={d.id} className="flex items-start justify-between gap-3">
            <span style={{ color: C.ink }}>{d.description || d.label}</span>
            <span dir="ltr" style={{ color: C.muted }}>{money(d.amount)} د</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function TotalRow({
  label,
  value,
  strong,
  danger,
}: {
  label: string;
  value: number;
  strong?: boolean;
  danger?: boolean;
}) {
  return (
    <div
      className="flex items-center justify-between px-4 py-2 text-sm"
      style={{
        borderTop: `1px solid ${C.line}`,
        backgroundColor: danger && value < 0 ? C.errorBg : undefined,
      }}
    >
      <span style={{ color: strong ? C.ink : C.muted, fontWeight: strong ? 700 : 500 }}>{label}</span>
      <Amount value={value} bold={strong} />
    </div>
  );
}

/** بطاقة يوم واحد — مداخيل ومصاريف وصافٍ وتراكمي، بترتيب قياسي ثابت. */
function DayCard({
  day,
  showCumulative,
  isPrintTarget,
  onPrintDay,
}: {
  key?: string | number;
  day: DaybookDay;
  showCumulative: boolean;
  isPrintTarget: boolean;
  onPrintDay: (date: string) => void;
}) {
  return (
    <div
      className={'rounded-2xl overflow-hidden mb-4 daybook-card' + (isPrintTarget ? ' print-target' : '')}
      style={{ backgroundColor: '#FFFFFF', border: `1px solid ${C.line}` }}
    >
      <div
        className="px-4 py-2 text-sm font-bold flex items-center justify-between gap-3"
        style={{ backgroundColor: C.sage, color: C.deep }}
      >
        <span>
          تقرير يوم {day.date}
          {!day.has_activity && (
            <span className="font-normal mr-2" style={{ color: C.muted }}>— لا حركة</span>
          )}
        </span>
        <button
          onClick={() => onPrintDay(day.date)}
          className="no-print flex items-center gap-1 text-xs font-bold rounded-lg px-2 py-1"
          style={{ backgroundColor: '#FFFFFF', color: C.forest, border: `1px solid ${C.line}` }}
          title="طباعة هذا اليوم وحده"
        >
          <Printer size={13} />
          طباعة اليوم
        </button>
      </div>

      <div className="grid md:grid-cols-2">
        <div className="p-4" style={{ borderLeft: `1px solid ${C.line}` }}>
          <div className="text-sm font-bold mb-2" style={{ color: C.forest }}>المداخيل اليومية</div>
          <LineList lines={day.income.lines} />
          {day.details && <DetailList title="تفاصيل" items={day.details.income} />}
        </div>

        <div className="p-4">
          <div className="text-sm font-bold mb-2" style={{ color: C.error }}>المصاريف اليومية</div>
          <LineList lines={day.expenses.lines} />
          {day.details && <DetailList title="تفاصيل" items={day.details.expenses} />}
        </div>
      </div>

      <div className="grid md:grid-cols-2" style={{ backgroundColor: C.bg }}>
        <div
          className="flex items-center justify-between px-4 py-2 text-sm"
          style={{ borderTop: `1px solid ${C.line}`, borderLeft: `1px solid ${C.line}` }}
        >
          <span style={{ color: C.muted }}>مجموع المداخيل</span>
          <Amount value={day.income.total} bold />
        </div>
        <div className="flex items-center justify-between px-4 py-2 text-sm" style={{ borderTop: `1px solid ${C.line}` }}>
          <span style={{ color: C.muted }}>مجموع المصاريف</span>
          <Amount value={day.expenses.total} bold />
        </div>
      </div>

      <TotalRow label="الدخل الصافي" value={day.net_income} strong danger />
      <TotalRow label="السحوبات" value={day.withdrawals} />
      {day.details && day.details.withdrawals.length > 0 && (
        <div className="px-4 pb-2">
          <DetailList title="تفاصيل السحوبات" items={day.details.withdrawals} />
        </div>
      )}
      <TotalRow label="الرصيد النهائي اليومي" value={day.balance} strong danger />

      {showCumulative && (
        <div style={{ backgroundColor: C.bg }}>
          <TotalRow label="الدخل الصافي التراكمي" value={day.cumulative.net_income} />
          <TotalRow label="السحوبات التراكمية" value={day.cumulative.withdrawals} />
          <TotalRow label="الرصيد التراكمي بعد السحب" value={day.cumulative.balance} strong danger />
        </div>
      )}

      {/* الرصيد التراكمي المطبوع دائماً داخل التقرير */}
      <div className="print-only treasury-print-summary px-4 py-2.5 text-sm font-bold border-t border-slate-900 bg-slate-50 text-slate-900">
        <div className="flex items-center justify-between">
          <span>الرصيد التراكمي النهائي حتى هذا اليوم:</span>
          <span dir="ltr">{money(day.cumulative?.balance ?? 0)} د.ت</span>
        </div>
      </div>
    </div>
  );
}

/**
 * كشف الخزينة اليومي.
 *
 * أربعة أنماط للفترة: يوم واحد، شهر، أشهر متتالية، أو مدى حرّ. وكل بطاقة
 * تُطبع وحدها عند الحاجة — لأن من يريد ورقة يوم أمس لا يجوز أن يُجبر على
 * طباعة أربعين صفحة ليأخذها.
 */
export function TreasuryDaybookPage() {
  const [mode, setMode] = useState<Mode>('month');

  const [dayValue, setDayValue] = useState<string>(today());
  const [monthValue, setMonthValue] = useState<string>(currentMonth());
  const [monthFrom, setMonthFrom] = useState<string>(currentMonth());
  const [monthTo, setMonthTo] = useState<string>(currentMonth());
  const [dateFrom, setDateFrom] = useState<string>(today().slice(0, 8) + '01');
  const [dateTo, setDateTo] = useState<string>(today());
  const [toNow, setToNow] = useState(true);

  const [withDetails, setWithDetails] = useState(true);
  const [showCumulative, setShowCumulative] = useState(true);
  const [hideEmpty, setHideEmpty] = useState(false);

  const [report, setReport] = useState<DaybookReport | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** اليوم المطلوب طباعته وحده؛ null يعني طباعة الكشف كله. */
  const [printDay, setPrintDay] = useState<string | null>(null);

  function resolveRange(): { from: string; to: string } {
    if (mode === 'day') return { from: dayValue, to: dayValue };
    if (mode === 'month') return monthBounds(monthValue);
    if (mode === 'months') {
      const a = monthBounds(monthFrom);
      const b = monthBounds(monthTo);
      return a.from <= b.from ? { from: a.from, to: b.to } : { from: b.from, to: a.to };
    }
    return { from: dateFrom, to: toNow ? today() : dateTo };
  }

  async function load() {
    const { from, to } = resolveRange();

    setLoading(true);
    setError(null);
    try {
      const data = await fetchTreasuryDaybook({ date: from, date_to: to, details: withDetails });
      setReport(data);
    } catch (e) {
      setError(errorMessage(e));
      setReport(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
    // تحميل أول مرّة فقط؛ بعدها بزرّ «عرض» حتى لا يُستدعى الخادم مع كل ضغطة تاريخ.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // الطباعة تجري بعد إعادة الرسم، وإلّا طُبِعت الصفحة قبل عزل اليوم المقصود.
  useEffect(() => {
    if (!printDay) return;

    const clear = () => setPrintDay(null);
    window.addEventListener('afterprint', clear, { once: true });

    const timer = window.setTimeout(() => {
      window.print();
      // شبكة أمان: بعض متصفّحات الهاتف لا تطلق afterprint.
      window.setTimeout(clear, 3000);
    }, 60);

    return () => {
      window.clearTimeout(timer);
      window.removeEventListener('afterprint', clear);
    };
  }, [printDay]);

  const days = report
    ? (hideEmpty ? report.days.filter((d) => d.has_activity) : report.days)
    : [];

  return (
    <div
      dir="rtl"
      className={'px-6 pb-10 max-w-6xl mx-auto' + (printDay ? ' printing-single' : '')}
    >
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap');
        @media print {
          @page { size: A4 portrait; margin: 8mm; }
          body * { visibility: hidden !important; }
          .daybook-card, .daybook-card * { visibility: visible !important; }
          .daybook-card {
            break-inside: avoid !important;
            page-break-inside: avoid !important;
            border: 1px solid #2a9d8f !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            font-family: 'Cairo', sans-serif !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            background: #fff !important;
          }
          .daybook-card.print-target {
            position: fixed !important;
            top: 0 !important; left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            padding: 8mm !important;
            box-sizing: border-box !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            border: 1px solid #2a9d8f !important;
          }
          .daybook-card.print-target * { font-weight: 600 !important; }
          .printing-single .daybook-card:not(.print-target) { display: none !important; }
          .no-print, header, nav, aside, button, form, input, select { display: none !important; visibility: hidden !important; }
          th, td { font-size: 13px !important; font-weight: 600 !important; padding: 8px 10px !important; border-color: #ccc !important; }
          .treasury-print-summary { font-size: 14px !important; font-weight: 700 !important; border-color: #2a9d8f !important; background: #E0F0EE !important; }
          * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
      `}</style>

      {/* شريط الفلترة */}
      <div
        className="no-print rounded-2xl p-4 mb-5"
        style={{ backgroundColor: '#FFFFFF', border: `1px solid ${C.line}` }}
      >
        <div className="flex flex-wrap items-center gap-2 mb-4">
          {(Object.keys(MODE_LABELS) as Mode[]).map((m) => (
            <button
              key={m}
              onClick={() => setMode(m)}
              className="rounded-xl px-3 py-1.5 text-sm font-bold"
              style={
                mode === m
                  ? { backgroundColor: C.forest, color: '#FFFFFF' }
                  : { backgroundColor: C.bg, color: C.muted, border: `1px solid ${C.line}` }
              }
            >
              {MODE_LABELS[m]}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-end gap-4">
          {mode === 'day' && (
            <div>
              <label className="block text-xs mb-1" style={{ color: C.muted }}>اليوم</label>
              <input
                type="date"
                value={dayValue}
                onChange={(e) => setDayValue(e.target.value)}
                className="rounded-xl px-3 py-2 text-sm"
                style={{ border: `1px solid ${C.line}`, color: C.ink }}
              />
            </div>
          )}

          {mode === 'month' && (
            <div>
              <label className="block text-xs mb-1" style={{ color: C.muted }}>الشهر</label>
              <input
                type="month"
                value={monthValue}
                onChange={(e) => setMonthValue(e.target.value)}
                className="rounded-xl px-3 py-2 text-sm"
                style={{ border: `1px solid ${C.line}`, color: C.ink }}
              />
            </div>
          )}

          {mode === 'months' && (
            <>
              <div>
                <label className="block text-xs mb-1" style={{ color: C.muted }}>من شهر</label>
                <input
                  type="month"
                  value={monthFrom}
                  onChange={(e) => setMonthFrom(e.target.value)}
                  className="rounded-xl px-3 py-2 text-sm"
                  style={{ border: `1px solid ${C.line}`, color: C.ink }}
                />
              </div>
              <div>
                <label className="block text-xs mb-1" style={{ color: C.muted }}>إلى شهر</label>
                <input
                  type="month"
                  value={monthTo}
                  onChange={(e) => setMonthTo(e.target.value)}
                  className="rounded-xl px-3 py-2 text-sm"
                  style={{ border: `1px solid ${C.line}`, color: C.ink }}
                />
              </div>
            </>
          )}

          {mode === 'range' && (
            <>
              <div>
                <label className="block text-xs mb-1" style={{ color: C.muted }}>من تاريخ</label>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={(e) => setDateFrom(e.target.value)}
                  className="rounded-xl px-3 py-2 text-sm"
                  style={{ border: `1px solid ${C.line}`, color: C.ink }}
                />
              </div>
              <div>
                <label className="block text-xs mb-1" style={{ color: C.muted }}>إلى تاريخ</label>
                <input
                  type="date"
                  value={toNow ? today() : dateTo}
                  disabled={toNow}
                  onChange={(e) => setDateTo(e.target.value)}
                  className="rounded-xl px-3 py-2 text-sm disabled:opacity-50"
                  style={{ border: `1px solid ${C.line}`, color: C.ink }}
                />
              </div>
              <label className="flex items-center gap-2 text-sm" style={{ color: C.ink }}>
                <input type="checkbox" checked={toNow} onChange={(e) => setToNow(e.target.checked)} />
                إلى اليوم
              </label>
            </>
          )}

          <label className="flex items-center gap-2 text-sm" style={{ color: C.ink }}>
            <input type="checkbox" checked={showCumulative} onChange={(e) => setShowCumulative(e.target.checked)} />
            تراكمي
          </label>

          <label className="flex items-center gap-2 text-sm" style={{ color: C.ink }}>
            <input type="checkbox" checked={withDetails} onChange={(e) => setWithDetails(e.target.checked)} />
            التفاصيل
          </label>

          <label className="flex items-center gap-2 text-sm" style={{ color: C.ink }}>
            <input type="checkbox" checked={hideEmpty} onChange={(e) => setHideEmpty(e.target.checked)} />
            إخفاء الأيام بلا حركة
          </label>

          <button
            onClick={() => void load()}
            disabled={loading}
            className="rounded-xl px-4 py-2 text-sm font-bold text-white flex items-center gap-2 disabled:opacity-60"
            style={{ backgroundColor: C.forest }}
          >
            {loading ? <Loader2 size={16} className="animate-spin" /> : <CalendarRange size={16} />}
            عرض
          </button>

          <button
            onClick={() => {
              setPrintDay(null);
              window.print();
            }}
            disabled={loading || !report || days.length === 0}
            className="rounded-xl px-4 py-2 text-sm font-bold flex items-center gap-2 disabled:opacity-50"
            style={{ border: `1px solid ${C.line}`, color: C.forest }}
          >
            <Printer size={16} />
            طباعة الكشف كله
          </button>
        </div>
      </div>

      {error && (
        <div
          className="rounded-2xl p-4 mb-5 text-sm flex items-start gap-2"
          style={{ backgroundColor: C.errorBg, color: C.error, border: `1px solid ${C.error}` }}
        >
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {report && (
        <>
          {/* ترويسة الطباعة */}
          <div className="text-center mb-4">
            <div className="font-bold" style={{ color: C.ink }}>{SCHOOL_AR}</div>
            <div className="text-xs" style={{ color: C.muted }}>{CITY_AR}</div>
            <h2 className="text-base font-bold mt-2 range-only" style={{ color: C.deep }}>
              تقرير الخزينة: من {report.date_from} إلى {report.date_to}
            </h2>
          </div>

          {report.details_truncated && (
            <div
              className="rounded-2xl p-3 mb-4 text-sm flex items-start gap-2"
              style={{ backgroundColor: C.errorBg, color: C.error, border: `1px solid ${C.error}` }}
            >
              <AlertCircle size={18} />
              <span>
                المجاميع كاملة وصحيحة، لكنّ أسطر التفصيل تجاوزت {report.details_limit} سطراً فلم تُعرض كلّها.
                اختر مدى أقصر لمطالعة التفاصيل كاملة.
              </span>
            </div>
          )}

          {/* الملخّص العام */}
          <div
            className="rounded-2xl p-4 mb-5 grid grid-cols-2 md:grid-cols-5 gap-4 range-only"
            style={{ backgroundColor: '#FFFFFF', border: `1px solid ${C.line}` }}
          >
            <div>
              <div className="text-xs" style={{ color: C.muted }}>رصيد ما قبل الفترة</div>
              <div className="text-lg"><Amount value={report.opening.balance} bold /></div>
            </div>
            <div>
              <div className="text-xs" style={{ color: C.muted }}>مداخيل الفترة</div>
              <div className="text-lg"><Amount value={report.summary.income.total} bold /></div>
            </div>
            <div>
              <div className="text-xs" style={{ color: C.muted }}>مصاريف الفترة</div>
              <div className="text-lg"><Amount value={report.summary.expenses.total} bold /></div>
            </div>
            <div>
              <div className="text-xs" style={{ color: C.muted }}>سحوبات الفترة</div>
              <div className="text-lg"><Amount value={report.summary.withdrawals} bold /></div>
            </div>
            <div>
              <div className="text-xs" style={{ color: C.muted }}>الرصيد الحالي</div>
              <div className="text-lg"><Amount value={report.closing.balance} bold /></div>
            </div>
          </div>

          {days.length === 0 ? (
            <div
              className="rounded-2xl p-6 text-center text-sm"
              style={{ backgroundColor: '#FFFFFF', border: `1px solid ${C.line}`, color: C.muted }}
            >
              لا توجد أيام لعرضها في هذا المدى.
            </div>
          ) : (
            days.map((d) => (
              <DayCard
                key={d.date}
                day={d}
                showCumulative={showCumulative}
                isPrintTarget={printDay === d.date}
                onPrintDay={setPrintDay}
              />
            ))
          )}

          <div className="text-xs text-center mt-4 range-only" style={{ color: C.muted }}>
            {report.days_count} يوماً في المدى — كل المبالغ مقروءة من الدفتر النقدي المركزي مع استثناء الحركات الملغاة.
          </div>
        </>
      )}

      {loading && !report && (
        <div className="flex items-center justify-center gap-2 py-10 text-sm" style={{ color: C.muted }}>
          <Loader2 size={18} className="animate-spin" />
          جارٍ تحميل الكشف…
        </div>
      )}
    </div>
  );
}

export default TreasuryDaybookPage;
