import type { NetPeriodRow } from '../../api/netIncome';
import { money } from '../../lib/format';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
};

/**
 * مكوّنات مشتركة بين الشهري والسنوي.
 *
 * وجودها في ملف واحد يضمن أن الصفحتين تعرضان الرقم بنفس الطريقة
 * وبنفس ترتيب البنود، وهو شرط لإمكان المقارنة بينهما بصرياً.
 */

export function NetFiguresPanel({ row, title }: { row: NetPeriodRow; title: string }) {
  return (
    <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(18rem, 1fr))' }}>
      <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
        <div className="px-4 py-3" style={{ backgroundColor: C.sage }}>
          <p className="text-sm font-semibold" style={{ color: C.ink }}>المداخيل — {title}</p>
        </div>
        <table className="w-full text-sm">
          <tbody>
            {row.income.lines.map((line) => (
              <tr key={line.category} style={{ borderTop: `1px solid ${C.line}` }}>
                <td className="px-4 py-2" style={{ color: C.ink }}>{line.label}</td>
                <td className="px-4 py-2 text-left" style={{ color: line.total > 0 ? C.forest : C.muted }}>
                  {money(line.total)}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr style={{ backgroundColor: '#F7F9F4', borderTop: `1px solid ${C.line}` }}>
              <td className="px-4 py-3 font-bold" style={{ color: C.ink }}>مجموع المداخيل</td>
              <td className="px-4 py-3 text-left font-bold" style={{ color: C.forest }}>{money(row.income.total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
        <div className="px-4 py-3" style={{ backgroundColor: C.sage }}>
          <p className="text-sm font-semibold" style={{ color: C.ink }}>المصاريف — {title}</p>
        </div>
        <table className="w-full text-sm">
          <tbody>
            {row.expenses.lines.map((line) => (
              <tr key={line.category} style={{ borderTop: `1px solid ${C.line}` }}>
                <td className="px-4 py-2" style={{ color: C.ink }}>{line.label}</td>
                <td className="px-4 py-2 text-left" style={{ color: line.total > 0 ? C.error : C.muted }}>
                  {money(line.total)}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr style={{ backgroundColor: '#F7F9F4', borderTop: `1px solid ${C.line}` }}>
              <td className="px-4 py-3 font-bold" style={{ color: C.ink }}>مجموع المصاريف</td>
              <td className="px-4 py-3 text-left font-bold" style={{ color: C.error }}>{money(row.expenses.total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}

export function NetTotalsCards({ row }: { row: NetPeriodRow }) {
  return (
    <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))' }}>
      <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
        <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المداخيل</p>
        <p className="text-xl font-bold" style={{ color: C.forest }}>{money(row.income.total)}</p>
      </div>
      <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
        <p className="text-sm mb-1" style={{ color: C.muted }}>مجموع المصاريف</p>
        <p className="text-xl font-bold" style={{ color: C.error }}>{money(row.expenses.total)}</p>
      </div>
      <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
        <p className="text-sm mb-1" style={{ color: C.muted }}>الدخل الصافي</p>
        <p className="text-xl font-bold" style={{ color: row.net_income < 0 ? C.error : C.forest }}>
          {money(row.net_income)}
        </p>
      </div>
      <div className="bg-white rounded-2xl p-4" style={{ border: `1px solid ${C.line}` }}>
        <p className="text-sm mb-1" style={{ color: C.muted }}>الرصيد بعد السحوبات</p>
        <p className="text-xl font-bold" style={{ color: C.ink }}>{money(row.balance)}</p>
        <p className="text-xs mt-1" style={{ color: C.muted }}>السحوبات: {money(row.withdrawals)}</p>
      </div>
    </div>
  );
}

export function NetPeriodsTable({
  rows,
  selected,
  onSelect,
  periodLabel,
  formatPeriod,
}: {
  rows: NetPeriodRow[];
  selected: string;
  onSelect: (period: string) => void;
  periodLabel: string;
  formatPeriod: (period: string) => string;
}) {
  return (
    <div className="bg-white rounded-2xl overflow-hidden" style={{ border: `1px solid ${C.line}` }}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead style={{ backgroundColor: C.sage }}>
            <tr>
              <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>{periodLabel}</th>
              <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المداخيل</th>
              <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>المصاريف</th>
              <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>الدخل الصافي</th>
              <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>السحوبات</th>
              <th className="text-right px-4 py-3 font-semibold" style={{ color: C.ink }}>الرصيد</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center" style={{ color: C.muted }}>
                  لا توجد حركات مسجّلة في هذا النطاق.
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr
                key={row.period}
                onClick={() => onSelect(row.period)}
                className="cursor-pointer hover:bg-[#F7F9F4]"
                style={{
                  borderTop: `1px solid ${C.line}`,
                  backgroundColor: row.period === selected ? '#F2F6EE' : undefined,
                }}
              >
                <td className="px-4 py-3 font-semibold" style={{ color: C.ink }}>{formatPeriod(row.period)}</td>
                <td className="px-4 py-3" style={{ color: C.forest }}>{money(row.income.total)}</td>
                <td className="px-4 py-3" style={{ color: C.error }}>{money(row.expenses.total)}</td>
                <td className="px-4 py-3 font-bold" style={{ color: row.net_income < 0 ? C.error : C.forest }}>
                  {money(row.net_income)}
                </td>
                <td className="px-4 py-3" style={{ color: C.muted }}>{money(row.withdrawals)}</td>
                <td className="px-4 py-3" style={{ color: C.ink }}>{money(row.balance)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
