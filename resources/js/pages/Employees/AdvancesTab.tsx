import React from 'react';
import { Plus, Ban, Banknote, Loader2, History } from 'lucide-react';
import {
  REPAYMENT_METHOD_LABELS,
  type Employee, type EmployeeAdvance, type AdvanceRepayment,
} from '../../api/employees';
import { C, TYPE_LABELS, money, shortDate, employeeName, remainingOf } from './shared';

interface Props {
  employees: Employee[];
  empFilter: string;
  onEmpFilterChange: (value: string) => void;
  advances: EmployeeAdvance[];
  loading: boolean;
  openRepayFor: number | null;
  repayments: AdvanceRepayment[];
  repayLoading: boolean;
  onNewAdvance: () => void;
  onSettle: (advance: EmployeeAdvance) => void;
  onToggleRepayments: (advance: EmployeeAdvance) => void;
  onCancelAdvance: (advance: EmployeeAdvance) => void;
  onCancelRepayment: (repayment: AdvanceRepayment) => void;
}

/**
 * جدول التسبقات والسلف، وتحت كلّ سلفة سجلّ ردّياتها قابل للطيّ.
 *
 * الفرق بينهما مالي لا شكلي: التسبقة تُخصم كاملة من راتب شهرها فلا زرّ
 * خلاص لها هنا، والسلفة دَين يُردّ على أقساط نقداً أو خصماً من الراتب.
 */
export function AdvancesTab({
  employees, empFilter, onEmpFilterChange, advances, loading,
  openRepayFor, repayments, repayLoading,
  onNewAdvance, onSettle, onToggleRepayments, onCancelAdvance, onCancelRepayment,
}: Props) {
  return (
    <>
      <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3" style={{ borderColor: C.line }}>
        <div className="md:col-span-2">
          <label className="text-xs" style={{ color: C.muted }}>الإطار</label>
          <select value={empFilter} onChange={(e) => onEmpFilterChange(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
            <option value="">كل الإطارات</option>
            {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
          </select>
        </div>
        <div className="flex items-end">
          <button
            onClick={onNewAdvance}
            className="w-full py-2.5 rounded-xl text-white text-sm font-bold flex items-center justify-center gap-2"
            style={{ background: C.forest }}
          >
            <Plus size={16} /> منح تسبقة أو سلفة
          </button>
        </div>
      </div>

      <div className="rounded-xl px-3 py-2 text-xs" style={{ background: C.sage, color: C.ink }}>
        التسبقة تُخصم كاملة عند خلاص راتب الشهر نفسه، ولا تُخلّص من هنا. أمّا السلفة فتُردّ على مهل بزرّ «خلاص»، نقداً أو خصماً من الراتب عند تسجيله.
      </div>

      <div className="bg-white rounded-2xl border overflow-x-auto" style={{ borderColor: C.line }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ color: C.muted, textAlign: 'right' }}>
              <th className="p-3">#</th>
              <th>الإطار</th>
              <th>النوع</th>
              <th>التاريخ</th>
              <th>المبلغ</th>
              <th>المخلّص</th>
              <th>المتبقّي</th>
              <th>السبب</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={9} className="p-6 text-center"><Loader2 className="inline animate-spin" /></td></tr>
            ) : advances.length === 0 ? (
              <tr><td colSpan={9} className="p-6 text-center" style={{ color: C.muted }}>لا توجد تسبقات أو سلف</td></tr>
            ) : advances.map((a) => (
              <React.Fragment key={a.id}>
                <tr className="border-t" style={{ borderColor: C.line, opacity: a.cancelled_at ? 0.55 : 1 }}>
                  <td className="p-3">{a.id}</td>
                  <td>{employeeName(a.employee, a.employee_id)}</td>
                  <td>{TYPE_LABELS[a.type] || a.type}</td>
                  <td>{shortDate(a.advance_date)}</td>
                  <td style={{ fontWeight: 700 }}>{money(a.amount)}</td>
                  <td>{money(a.settled_amount)}</td>
                  <td style={{ color: remainingOf(a) > 0 ? C.danger : C.forest }}>{money(remainingOf(a))}</td>
                  <td style={{ color: C.muted }}>{a.reason || '—'}</td>
                  <td>
                    {a.cancelled_at ? (
                      <span className="text-xs" style={{ color: C.danger }}>ملغاة</span>
                    ) : a.settled_by_salary_id ? (
                      <span className="text-xs" style={{ color: C.muted }}>خُصمت من الراتب #{a.settled_by_salary_id}</span>
                    ) : (
                      <div className="flex gap-1">
                        {a.type === 'loan' && remainingOf(a) > 0 && (
                          <button onClick={() => onSettle(a)} title="خلاص"
                            className="p-2 rounded-lg" style={{ color: C.forest }}>
                            <Banknote size={16} />
                          </button>
                        )}
                        {a.type === 'loan' && (
                          <button onClick={() => onToggleRepayments(a)} title="سجلّ الردّيات"
                            className="p-2 rounded-lg" style={{ color: openRepayFor === a.id ? C.forest : C.muted }}>
                            <History size={16} />
                          </button>
                        )}
                        <button onClick={() => onCancelAdvance(a)} title="إلغاء موثّق"
                          className="p-2 rounded-lg" style={{ color: C.dangerBtn }}>
                          <Ban size={16} />
                        </button>
                      </div>
                    )}
                  </td>
                </tr>

                {openRepayFor === a.id && (
                  <tr style={{ background: C.bg }}>
                    <td colSpan={9} className="p-3">
                      {repayLoading ? (
                        <p className="text-xs" style={{ color: C.muted }}>جارٍ تحميل الردّيات…</p>
                      ) : repayments.length === 0 ? (
                        <p className="text-xs" style={{ color: C.muted }}>لا توجد ردّيات مسجّلة لهذه السلفة.</p>
                      ) : (
                        <table className="w-full text-xs">
                          <thead>
                            <tr style={{ color: C.muted, textAlign: 'right' }}>
                              <th className="p-2">#</th>
                              <th>التاريخ</th>
                              <th>المبلغ</th>
                              <th>الطريقة</th>
                              <th>الخزينة</th>
                              <th>ملاحظة</th>
                              <th></th>
                            </tr>
                          </thead>
                          <tbody>
                            {repayments.map((r) => (
                              <tr key={r.id} className="border-t" style={{ borderColor: C.line, opacity: r.cancelled_at ? 0.5 : 1 }}>
                                <td className="p-2">{r.id}</td>
                                <td>{shortDate(r.repaid_at)}</td>
                                <td style={{ fontWeight: 700 }}>{money(r.amount)}</td>
                                <td>{REPAYMENT_METHOD_LABELS[r.method] || r.method}</td>
                                <td style={{ color: C.muted }}>
                                  {r.method === 'cash' ? 'دخل الدفتر' : 'لا يمرّ بالصندوق'}
                                </td>
                                <td style={{ color: C.muted }}>{r.cancelled_at ? r.cancellation_reason : (r.notes || '—')}</td>
                                <td>
                                  {r.cancelled_at ? (
                                    <span style={{ color: C.danger }}>ملغى</span>
                                  ) : r.salary_id ? (
                                    <span style={{ color: C.muted }}>ضمن الراتب #{r.salary_id}</span>
                                  ) : (
                                    <button onClick={() => onCancelRepayment(r)} title="إلغاء الردّ"
                                      className="p-1 rounded-lg" style={{ color: C.dangerBtn }}>
                                      <Ban size={14} />
                                    </button>
                                  )}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
