import React, { useEffect, useRef, useState } from 'react';
import {
  getOutstandingAdvances, getOutstandingLoans, getEmployeeMonthlySummary,
  type Employee, type EmployeeAdvance, type LoanDeductionInput,
  type HoursMonthlySummary,
} from '../../api/employees';
import { TreasuryBalanceHint } from '../../components/TreasuryBalanceHint';
import { C, money, today, remainingOf, type AcademicYearOption } from './shared';

export interface SalaryFormValues {
  employee_id: number;
  academic_year_id: number;
  gross_amount: number;
  advance_ids: number[];
  loan_deductions: LoanDeductionInput[];
  period_from: string;
  period_to: string;
  paid_at?: string;
  method: string;
}

interface Props {
  employees: Employee[];
  years: AcademicYearOption[];
  defaultYearId: string;
  saving: boolean;
  onClose: () => void;
  onError: (message: string) => void;
  onSubmit: (values: SalaryFormValues) => void;
}

const FIELD = 'w-full border rounded-xl px-3 py-2';

/**
 * نافذة خلاص الراتب.
 *
 * دَينان مختلفان يُخصمان هنا، وخلطهما خطأ محاسبي:
 *
 * - التسبقة: تُخصم كاملة في شهرها، فلا يُختار لها مبلغ — مربّع اختيار فحسب.
 * - السلفة: دَين يُردّ على أقساط، فلكلّ واحدة مبلغ يقرّره القابض هذا الشهر.
 *
 * الواجهة تعرض الحساب للقارئ فحسب؛ الخادم وحده مرجع الصافي، وهو يرفض
 * القسط المتجاوز للمتبقّي وقسطَين من سلفة واحدة في راتب واحد.
 */
export function SalaryFormModal({
  employees, years, defaultYearId, saving, onClose, onError, onSubmit,
}: Props) {
  const [form, setForm] = useState({
    employee_id: '', academic_year_id: defaultYearId, gross_amount: '',
    period_from: '', period_to: '', paid_at: today(), method: 'cash',
  });

  const [advances, setAdvances] = useState<EmployeeAdvance[]>([]);
  const [selectedAdvances, setSelectedAdvances] = useState<number[]>([]);

  const [loans, setLoans] = useState<EmployeeAdvance[]>([]);
  const [selectedLoans, setSelectedLoans] = useState<number[]>([]);
  const [loanAmounts, setLoanAmounts] = useState<Record<number, string>>({});

  const [debtsLoading, setDebtsLoading] = useState(false);

  // ملخص الحصص للمعلم الساعي: يجلب تلقائياً عند اختيار إطار ساعي
  // أو تغيير شهر الفترة، ويملأ الراتب الخام بالراتب المحتسب — قابل للتعديل
  // يدوياً قبل الحفظ، والنظام لا يخلّص شيئاً تلقائياً.
  const [hourlySummary, setHourlySummary] = useState<HoursMonthlySummary | null>(null);
  const [summaryLoading, setSummaryLoading] = useState(false);
  const lastAutoKey = useRef<string>('');

  const selectedEmployee = employees.find((e) => e.id === Number(form.employee_id));
  const isHourly = selectedEmployee?.salary_type === 'hourly';
  const periodMonth = (form.period_from || today()).slice(0, 7);

  useEffect(() => {
    setHourlySummary(null);

    if (!isHourly) {
      lastAutoKey.current = '';
      return;
    }

    let cancelled = false;
    setSummaryLoading(true);

    const key = `${form.employee_id}:${periodMonth}`;
    const autoFill = lastAutoKey.current !== key;

    getEmployeeMonthlySummary(Number(form.employee_id), periodMonth)
      .then((summary) => {
        if (cancelled) return;
        setHourlySummary(summary);
        // لا يُعاد ملء الحقل بعد أول ملء لنفس الشهر — المسؤول قد عدّله يدوياً.
        if (autoFill) {
          lastAutoKey.current = key;
          setForm((f) => ({ ...f, gross_amount: String(summary.total_salary) }));
        }
      })
      .catch(() => {
        if (!cancelled) setHourlySummary(null);
      })
      .finally(() => {
        if (!cancelled) setSummaryLoading(false);
      });

    return () => { cancelled = true; };
  }, [form.employee_id, periodMonth, isHourly]);

  // عند تغيير الإطار تُجلَب ديونه وحده، وتُمسح الاختيارات السابقة
  // حتّى لا يُخصم من إطار دَين إطار آخر.
  useEffect(() => {
    setAdvances([]);
    setSelectedAdvances([]);
    setLoans([]);
    setSelectedLoans([]);
    setLoanAmounts({});

    if (!form.employee_id) return;

    let cancelled = false;
    setDebtsLoading(true);

    const employeeId = Number(form.employee_id);

    Promise.all([getOutstandingAdvances(employeeId), getOutstandingLoans(employeeId)])
      .then(([advanceRows, loanRows]) => {
        if (cancelled) return;

        setAdvances(advanceRows);
        // التسبقة تُخصم كاملة في شهرها، فتُختار كلّها مبدئيّاً.
        setSelectedAdvances(advanceRows.map((r) => r.id));

        setLoans(loanRows);
        // السلفة لا تُختار تلقائيّاً: قسط هذا الشهر قرار بشري لا افتراض.
        setLoanAmounts(
          Object.fromEntries(loanRows.map((r) => [r.id, remainingOf(r).toFixed(2)])),
        );
      })
      .catch(() => {
        if (cancelled) return;
        setAdvances([]);
        setLoans([]);
      })
      .finally(() => {
        if (!cancelled) setDebtsLoading(false);
      });

    return () => { cancelled = true; };
  }, [form.employee_id]);

  function toggleAdvance(id: number) {
    setSelectedAdvances((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    );
  }

  function toggleLoan(id: number) {
    setSelectedLoans((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    );
  }

  const advanceDeduction = advances
    .filter((a) => selectedAdvances.includes(a.id))
    .reduce((sum, a) => sum + remainingOf(a), 0);

  const loanDeduction = loans
    .filter((l) => selectedLoans.includes(l.id))
    .reduce((sum, l) => sum + Number(loanAmounts[l.id] || 0), 0);

  const deduction = advanceDeduction + loanDeduction;
  const grossValue = Number(form.gross_amount || 0);
  const netValue = grossValue - deduction;

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    const installments: LoanDeductionInput[] = [];

    for (const loan of loans) {
      if (!selectedLoans.includes(loan.id)) continue;

      const amount = Number(loanAmounts[loan.id] || 0);
      const remaining = remainingOf(loan);

      if (!amount || amount <= 0) {
        onError(`قسط السلفة رقم ${loan.id} غير صالح`);
        return;
      }

      if (amount > remaining + 0.001) {
        onError(`قسط السلفة رقم ${loan.id} يتجاوز المتبقّي منها (${money(remaining)})`);
        return;
      }

      installments.push({ id: loan.id, amount });
    }

    onSubmit({
      employee_id: Number(form.employee_id),
      academic_year_id: Number(form.academic_year_id || defaultYearId),
      gross_amount: grossValue,
      advance_ids: selectedAdvances,
      loan_deductions: installments,
      period_from: form.period_from,
      period_to: form.period_to,
      paid_at: form.paid_at || undefined,
      method: form.method,
    });
  }

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <form onSubmit={handleSubmit} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto">
        <h3 className="font-bold text-lg" style={{ color: C.ink }}>خلاص راتب</h3>

        <select required className={FIELD} style={{ borderColor: C.line }}
          value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })}>
          <option value="">اختر الإطار</option>
          {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
        </select>

        <select required className={FIELD} style={{ borderColor: C.line }}
          value={form.academic_year_id} onChange={(e) => setForm({ ...form, academic_year_id: e.target.value })}>
          <option value="">السنة</option>
          {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
        </select>

        <div>
          <label className="text-xs" style={{ color: C.muted }}>الراتب الخام</label>

          {isHourly && (
            <div className="mt-1 rounded-xl border px-3 py-2 text-xs flex flex-wrap items-center justify-between gap-2"
              style={{ borderColor: C.line, background: C.sage }}>
              {summaryLoading ? (
                <span className="animate-pulse" style={{ color: C.muted }}>جارٍ حساب ساعات الشهر...</span>
              ) : hourlySummary ? (
                <>
                  <span style={{ color: C.ink }}>
                    إجمالي الساعات: <strong>{hourlySummary.total_hours}</strong> | الراتب المحتسب: <strong>{money(hourlySummary.total_salary)} د.ت</strong>
                  </span>
                  <span style={{ color: C.muted }}>
                    ({hourlySummary.work_days} عمل / {hourlySummary.absence_days} غياب) — اقتراح قابل للتعديل يدوياً
                  </span>
                </>
              ) : (
                <span style={{ color: C.muted }}>لا ساعات مسجلة لهذا الشهر — يُترك الراتب فارغاً.</span>
              )}
            </div>
          )}

          <input required type="number" step="0.01" placeholder="مثال: 500" className={`mt-1 ${FIELD}`} style={{ borderColor: C.line }}
            value={form.gross_amount} onChange={(e) => setForm({ ...form, gross_amount: e.target.value })} />
        </div>

        {form.employee_id && (
          <div className="rounded-xl p-3 space-y-3" style={{ background: C.sage }}>
            {debtsLoading ? (
              <div className="animate-pulse space-y-2" role="status" aria-label="تحميل الديون">
                <div className="h-3 w-3/4 rounded bg-white/70" />
                <div className="h-3 w-1/2 rounded bg-white/70" />
              </div>
            ) : (
              <>
                <div>
                  <p className="text-xs font-bold mb-2" style={{ color: C.ink }}>التسبقات القائمة</p>

                  {advances.length === 0 ? (
                    <p className="text-xs" style={{ color: C.muted }}>لا توجد تسبقات قائمة لهذا الإطار.</p>
                  ) : (
                    <div className="space-y-1">
                      {advances.map((a) => (
                        <label key={a.id} className="flex items-center justify-between gap-2 text-xs">
                          <span className="flex items-center gap-2">
                            <input
                              type="checkbox"
                              checked={selectedAdvances.includes(a.id)}
                              onChange={() => toggleAdvance(a.id)}
                            />
                            <span>{String(a.advance_date).slice(0, 10)}{a.reason ? ` — ${a.reason}` : ''}</span>
                          </span>
                          <strong>{money(remainingOf(a))}</strong>
                        </label>
                      ))}
                    </div>
                  )}
                </div>

                <div className="pt-2" style={{ borderTop: `1px solid ${C.line}` }}>
                  <p className="text-xs font-bold mb-2" style={{ color: C.ink }}>أقساط السلف</p>

                  {loans.length === 0 ? (
                    <p className="text-xs" style={{ color: C.muted }}>لا توجد سلف قائمة لهذا الإطار.</p>
                  ) : (
                    <div className="space-y-2">
                      {loans.map((l) => {
                        const remaining = remainingOf(l);
                        const checked = selectedLoans.includes(l.id);

                        return (
                          <div key={l.id} className="space-y-1">
                            <label className="flex items-center justify-between gap-2 text-xs">
                              <span className="flex items-center gap-2">
                                <input type="checkbox" checked={checked} onChange={() => toggleLoan(l.id)} />
                                <span>سلفة #{l.id} — {String(l.advance_date).slice(0, 10)}</span>
                              </span>
                              <span style={{ color: C.muted }}>متبقٍّ {money(remaining)}</span>
                            </label>

                            {checked && (
                              <input
                                type="number" step="0.01" min="0.01" max={remaining}
                                className="w-full border rounded-xl px-3 py-1.5 text-xs"
                                style={{ borderColor: C.line }}
                                placeholder="قسط هذا الشهر"
                                value={loanAmounts[l.id] ?? ''}
                                onChange={(e) => setLoanAmounts({ ...loanAmounts, [l.id]: e.target.value })}
                              />
                            )}
                          </div>
                        );
                      })}
                    </div>
                  )}

                  <p className="text-xs mt-2" style={{ color: C.muted }}>
                    قسط السلفة لا يمرّ بالصندوق: الإطار يقبض أقلّ، فيُنقَص دَينه بقدره.
                  </p>
                </div>
              </>
            )}
          </div>
        )}

        <div className="rounded-xl border p-3 text-sm space-y-1" style={{ borderColor: C.line }}>
          <div className="flex justify-between">
            <span style={{ color: C.muted }}>الخام</span>
            <span>{money(grossValue)}</span>
          </div>
          <div className="flex justify-between">
            <span style={{ color: C.muted }}>خصم التسبقات</span>
            <span style={{ color: C.danger }}>{money(advanceDeduction)}</span>
          </div>
          <div className="flex justify-between">
            <span style={{ color: C.muted }}>خصم أقساط السلف</span>
            <span style={{ color: C.danger }}>{money(loanDeduction)}</span>
          </div>
          <div className="flex justify-between font-bold">
            <span style={{ color: C.ink }}>المدفوع نقداً</span>
            <span style={{ color: netValue < 0 ? C.danger : C.forest }}>{money(netValue)}</span>
          </div>
          {netValue < 0 && (
            <p className="text-xs" style={{ color: C.danger }}>
              الخصومات تتجاوز الراتب الخام.
            </p>
          )}
        </div>

        {/* التحذير يقارن الرصيد بالمدفوع نقداً لا بالخام، لأنّ الخصومات لا تخرج اليوم. */}
        <TreasuryBalanceHint amount={netValue > 0 ? netValue : 0} refreshKey={true} />

        <div className="grid grid-cols-2 gap-2">
          <input required type="date" className="border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
            value={form.period_from} onChange={(e) => setForm({ ...form, period_from: e.target.value })} />
          <input required type="date" className="border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
            value={form.period_to} onChange={(e) => setForm({ ...form, period_to: e.target.value })} />
        </div>

        <input type="date" className={FIELD} style={{ borderColor: C.line }}
          value={form.paid_at} onChange={(e) => setForm({ ...form, paid_at: e.target.value })} />

        <div className="flex gap-2 justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
          <button type="submit" disabled={saving || netValue < 0} className="px-4 py-2 rounded-xl text-white font-bold disabled:opacity-50" style={{ background: C.forest }}>
            {saving ? 'جارٍ...' : 'حفظ'}
          </button>
        </div>
      </form>
    </div>
  );
}
