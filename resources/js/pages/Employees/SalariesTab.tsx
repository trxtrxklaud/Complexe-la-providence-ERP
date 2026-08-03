import { Plus, Ban, Loader2 } from 'lucide-react';
import type { Employee, Salary } from '../../api/employees';
import { C, money, shortDate, employeeName, type AcademicYearOption } from './shared';

interface Props {
  years: AcademicYearOption[];
  employees: Employee[];
  yearId: string;
  onYearChange: (value: string) => void;
  empFilter: string;
  onEmpFilterChange: (value: string) => void;
  salaries: Salary[];
  loading: boolean;
  onNewSalary: () => void;
  onCancelSalary: (salary: Salary) => void;
}

/**
 * جدول الرواتب المخلّصة مع مرشّحَي السنة والإطار.
 *
 * الراتب لا يُحذف أبداً: زرّ الإلغاء وحده متاح، والسطر الملغى يبقى
 * ظاهراً باهتاً لأنّ أثره في الدفتر موثّق.
 */
export function SalariesTab({
  years, employees, yearId, onYearChange, empFilter, onEmpFilterChange,
  salaries, loading, onNewSalary, onCancelSalary,
}: Props) {
  return (
    <>
      <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3" style={{ borderColor: C.line }}>
        <div>
          <label className="text-xs" style={{ color: C.muted }}>السنة الدراسية</label>
          <select value={yearId} onChange={(e) => onYearChange(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
            <option value="">كل السنوات</option>
            {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
          </select>
        </div>
        <div>
          <label className="text-xs" style={{ color: C.muted }}>الإطار</label>
          <select value={empFilter} onChange={(e) => onEmpFilterChange(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
            <option value="">كل الإطارات</option>
            {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
          </select>
        </div>
        <div className="flex items-end">
          <button
            onClick={onNewSalary}
            className="w-full py-2.5 rounded-xl text-white text-sm font-bold flex items-center justify-center gap-2"
            style={{ background: C.forest }}
          >
            <Plus size={16} /> خلاص راتب
          </button>
        </div>
      </div>

      <div className="bg-white rounded-2xl border overflow-x-auto" style={{ borderColor: C.line }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ color: C.muted, textAlign: 'right' }}>
              <th className="p-3">#</th>
              <th>السنة</th>
              <th>اسم الإطار</th>
              <th>الخام</th>
              <th>الخصومات</th>
              <th>المدفوع</th>
              <th>من</th>
              <th>إلى</th>
              <th>تاريخ الدفع</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={10} className="p-6 text-center"><Loader2 className="inline animate-spin" /></td></tr>
            ) : salaries.length === 0 ? (
              <tr><td colSpan={10} className="p-6 text-center" style={{ color: C.muted }}>لا توجد رواتب</td></tr>
            ) : salaries.map((s) => (
              <tr key={s.id} className="border-t" style={{ borderColor: C.line, opacity: s.cancelled_at ? 0.55 : 1 }}>
                <td className="p-3">{s.id}</td>
                <td>{s.academic_year?.name || s.academic_year_id}</td>
                <td>{employeeName(s.employee, s.employee_id)}</td>
                <td>{s.gross_amount != null ? money(s.gross_amount) : '—'}</td>
                <td style={{ color: Number(s.advance_deduction ?? 0) > 0 ? C.danger : C.muted }}>
                  {money(s.advance_deduction)}
                </td>
                <td style={{ fontWeight: 700, color: C.forest }}>{money(s.amount)}</td>
                <td>{shortDate(s.period_from)}</td>
                <td>{shortDate(s.period_to)}</td>
                <td>{shortDate(s.paid_at)}</td>
                <td>
                  {s.cancelled_at ? (
                    <span className="text-xs" style={{ color: C.danger }}>ملغى</span>
                  ) : (
                    <button onClick={() => onCancelSalary(s)} title="إلغاء موثّق"
                      className="p-2 rounded-lg" style={{ color: C.dangerBtn }}>
                      <Ban size={16} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
