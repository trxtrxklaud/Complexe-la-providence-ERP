import React, { useEffect, useState } from 'react';
import { Users, Plus, Trash2, Ban, Banknote, Loader2, History } from 'lucide-react';
import {
  getEmployees, createEmployee, deleteEmployee,
  getSalaries, createSalary, cancelSalary,
  getAdvances, createAdvance, settleAdvance, cancelAdvance, getOutstandingAdvances,
  getRepayments, cancelRepayment, REPAYMENT_METHOD_LABELS,
  type Employee, type Salary, type EmployeeAdvance,
  type AdvanceRepayment, type RepaymentMethod,
} from '../../api/employees';
import { getToken } from '../../api/http';
import { TreasuryBalanceHint } from '../../components/TreasuryBalanceHint';


const C = {
  forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C',
  muted: '#7C8677', line: '#EDF1E8', bg: '#F4F6F1',
};

type Tab = 'salaries' | 'advances' | 'staff';

const TYPE_LABELS: Record<string, string> = {
  advance: 'تسبقة',
  loan: 'سلفة',
};

/** المتبقّي من تسبقة أو سلفة: المبلغ ناقص ما خُلّص منه. */
function remainingOf(advance: EmployeeAdvance): number {
  return Number(advance.amount ?? 0) - Number(advance.settled_amount ?? 0);
}

async function fetchYears() {
  const token = getToken();
  const res = await fetch('/api/academic-years', {
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!res.ok) throw new Error('فشل جلب السنوات');
  return res.json();
}


export function EmployeesPage() {
  const [tab, setTab] = useState<Tab>('salaries');
  const [years, setYears] = useState<any[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [salaries, setSalaries] = useState<Salary[]>([]);
  const [yearId, setYearId] = useState('');
  const [empFilter, setEmpFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [showEmpForm, setShowEmpForm] = useState(false);
  const [showSalForm, setShowSalForm] = useState(false);

  // قائمة التسبقات والسلف (تبويب مستقلّ)
  const [advList, setAdvList] = useState<EmployeeAdvance[]>([]);
  const [advLoading, setAdvLoading] = useState(false);
  const [showAdvForm, setShowAdvForm] = useState(false);
  const [advForm, setAdvForm] = useState({
    employee_id: '', type: 'advance' as 'advance' | 'loan', amount: '',
    advance_date: new Date().toISOString().slice(0, 10), reason: '', method: 'cash',
  });

  // خلاص سلفة: المبلغ وتاريخ الردّ وطريقته.
  const [settleTarget, setSettleTarget] = useState<EmployeeAdvance | null>(null);
  const [settleForm, setSettleForm] = useState({
    amount: '', method: 'cash' as RepaymentMethod,
    repaid_at: new Date().toISOString().slice(0, 10), notes: '',
  });

  // سجلّ الردّيات المفتوح تحت سطر السلفة.
  const [openRepayFor, setOpenRepayFor] = useState<number | null>(null);
  const [repayments, setRepayments] = useState<AdvanceRepayment[]>([]);
  const [repayLoading, setRepayLoading] = useState(false);

  // التسبقات القائمة للإطار المختار داخل نموذج الراتب.
  const [advances, setAdvances] = useState<EmployeeAdvance[]>([]);
  const [advancesLoading, setAdvancesLoading] = useState(false);
  const [selectedAdvances, setSelectedAdvances] = useState<number[]>([]);

  const [empForm, setEmpForm] = useState({
    first_name: '', last_name: '', phone: '', job_title: '', default_salary: '',
  });
  const [salForm, setSalForm] = useState({
    employee_id: '', academic_year_id: '', gross_amount: '',
    period_from: '', period_to: '', paid_at: new Date().toISOString().slice(0, 10),
    method: 'cash',
  });

  const deduction = advances
    .filter((a) => selectedAdvances.includes(a.id))
    .reduce((sum, a) => sum + remainingOf(a), 0);
  const grossValue = Number(salForm.gross_amount || 0);
  const netValue = grossValue - deduction;

  async function loadBase() {
    setLoading(true);
    setError('');
    try {
      const [y, e] = await Promise.all([fetchYears(), getEmployees()]);
      setYears(y);
      setEmployees(e);
      const active = y.find((x: any) => x.is_active) || y[0];
      if (active && !yearId) setYearId(String(active.id));
    } catch (err: any) {
      setError(err.message || 'فشل التحميل');
    } finally {
      setLoading(false);
    }
  }

  async function loadSalaries() {
    try {
      const res = await getSalaries({
        academic_year_id: yearId ? Number(yearId) : undefined,
        employee_id: empFilter ? Number(empFilter) : undefined,
      });
      setSalaries(res.data || []);
    } catch (err: any) {
      setError(err.message || 'فشل تحميل الرواتب');
    }
  }

  async function loadAdvances() {
    setAdvLoading(true);
    try {
      const rows = await getAdvances({
        employee_id: empFilter ? Number(empFilter) : undefined,
      });
      setAdvList(rows);
    } catch (err: any) {
      setError(err.message || 'فشل تحميل التسبقات');
    } finally {
      setAdvLoading(false);
    }
  }

  useEffect(() => { loadBase(); }, []);
  useEffect(() => { if (tab === 'salaries') loadSalaries(); }, [tab, yearId, empFilter]);
  useEffect(() => { if (tab === 'advances') loadAdvances(); }, [tab, empFilter]);

  // عند تغيير الإطار تُجلَب تسبقاته القائمة، وتُمسح الاختيارات السابقة
  // حتّى لا يُخصم من إطار دَين إطار آخر.
  useEffect(() => {
    setSelectedAdvances([]);
    setAdvances([]);

    if (!showSalForm || !salForm.employee_id) return;

    let cancelled = false;
    setAdvancesLoading(true);

    getOutstandingAdvances(Number(salForm.employee_id))
      .then((rows) => {
        if (cancelled) return;
        setAdvances(rows);
        // التسبقة تُخصم كاملة في شهرها، فتُختار كلّها مبدئيّاً.
        setSelectedAdvances(rows.map((r) => r.id));
      })
      .catch(() => {
        if (!cancelled) setAdvances([]);
      })
      .finally(() => {
        if (!cancelled) setAdvancesLoading(false);
      });

    return () => { cancelled = true; };
  }, [salForm.employee_id, showSalForm]);

  function toggleAdvance(id: number) {
    setSelectedAdvances((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    );
  }

  async function onCreateEmployee(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true); setError('');
    try {
      await createEmployee({
        first_name: empForm.first_name,
        last_name: empForm.last_name,
        phone: empForm.phone || null,
        job_title: empForm.job_title || null,
        default_salary: empForm.default_salary ? Number(empForm.default_salary) : null,
        is_active: true,
      });
      setShowEmpForm(false);
      setEmpForm({ first_name: '', last_name: '', phone: '', job_title: '', default_salary: '' });
      await loadBase();
      setTab('staff');
    } catch (err: any) {
      setError(err.message || 'فشل الحفظ');
    } finally {
      setSaving(false);
    }
  }

  async function onCreateAdvance(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true); setError('');
    try {
      await createAdvance({
        employee_id: Number(advForm.employee_id),
        academic_year_id: yearId ? Number(yearId) : undefined,
        type: advForm.type,
        amount: Number(advForm.amount),
        advance_date: advForm.advance_date,
        method: advForm.method,
        reason: advForm.reason || undefined,
      });
      setShowAdvForm(false);
      setAdvForm({
        employee_id: '', type: 'advance', amount: '',
        advance_date: new Date().toISOString().slice(0, 10), reason: '', method: 'cash',
      });
      await loadAdvances();
    } catch (err: any) {
      setError(err.message || 'فشل حفظ التسبقة');
    } finally {
      setSaving(false);
    }
  }

  /** فتح نافذة الخلاص مع اقتراح المتبقّي كاملاً. */
  function openSettle(advance: EmployeeAdvance) {
    setError('');
    setSettleTarget(advance);
    setSettleForm({
      amount: remainingOf(advance).toFixed(2),
      method: 'cash',
      repaid_at: new Date().toISOString().slice(0, 10),
      notes: '',
    });
  }

  async function onSubmitSettle(e: React.FormEvent) {
    e.preventDefault();
    if (!settleTarget) return;

    const amount = Number(settleForm.amount);
    const remaining = remainingOf(settleTarget);

    if (!amount || amount <= 0) {
      setError('مبلغ الخلاص غير صالح');
      return;
    }

    // حاجز أمامي لراحة القابض فحسب؛ الخادم يرفضه أيضاً مع قفل صفّ.
    if (amount > remaining + 0.001) {
      setError(`المبلغ يتجاوز المتبقّي (${remaining.toFixed(2)})`);
      return;
    }

    setSaving(true); setError('');
    try {
      await settleAdvance(settleTarget.id, {
        amount,
        method: settleForm.method,
        repaid_at: settleForm.repaid_at || undefined,
        notes: settleForm.notes || undefined,
      });
      setSettleTarget(null);
      await loadAdvances();
      if (openRepayFor) await loadRepayments(openRepayFor);
    } catch (err: any) {
      setError(err.message || 'فشل خلاص السلفة');
    } finally {
      setSaving(false);
    }
  }

  async function loadRepayments(advanceId: number) {
    setRepayLoading(true);
    try {
      setRepayments(await getRepayments(advanceId));
    } catch (err: any) {
      setError(err.message || 'فشل تحميل الردّيات');
    } finally {
      setRepayLoading(false);
    }
  }

  async function toggleRepayments(advance: EmployeeAdvance) {
    if (openRepayFor === advance.id) {
      setOpenRepayFor(null);
      setRepayments([]);
      return;
    }

    setOpenRepayFor(advance.id);
    setRepayments([]);
    await loadRepayments(advance.id);
  }

  async function onCancelRepayment(repayment: AdvanceRepayment) {
    const reason = window.prompt('سبب إلغاء الردّ (إجباري):');
    if (reason === null) return;

    if (reason.trim().length < 3) {
      setError('سبب الإلغاء مطلوب (ثلاثة أحرف على الأقلّ)');
      return;
    }

    setError('');
    try {
      await cancelRepayment(repayment.id, reason.trim());
      await loadRepayments(repayment.employee_advance_id);
      await loadAdvances();
    } catch (err: any) {
      setError(err.message || 'فشل إلغاء الردّ');
    }
  }

  async function onCancelAdvance(advance: EmployeeAdvance) {
    const reason = window.prompt('سبب الإلغاء (إجباري):');
    if (reason === null) return;

    if (reason.trim().length < 3) {
      setError('سبب الإلغاء مطلوب (ثلاثة أحرف على الأقلّ)');
      return;
    }

    setError('');
    try {
      await cancelAdvance(advance.id, reason.trim());
      await loadAdvances();
    } catch (err: any) {
      setError(err.message || 'فشل الإلغاء');
    }
  }

  async function onCreateSalary(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true); setError('');
    try {
      await createSalary({
        employee_id: Number(salForm.employee_id),
        academic_year_id: Number(salForm.academic_year_id || yearId),
        gross_amount: Number(salForm.gross_amount),
        advance_ids: selectedAdvances,
        period_from: salForm.period_from,
        period_to: salForm.period_to,
        paid_at: salForm.paid_at || undefined,
        method: salForm.method,
      });
      setShowSalForm(false);
      setSelectedAdvances([]);
      setAdvances([]);
      setSalForm({
        employee_id: '', academic_year_id: yearId, gross_amount: '',
        period_from: '', period_to: '', paid_at: new Date().toISOString().slice(0, 10), method: 'cash',
      });
      await loadSalaries();
    } catch (err: any) {
      setError(err.message || 'فشل حفظ الراتب');
    } finally {
      setSaving(false);
    }
  }

  async function onCancelSalary(salary: Salary) {
    const reason = window.prompt('سبب إلغاء الراتب (إجباري):');
    if (reason === null) return;

    if (reason.trim().length < 3) {
      setError('سبب الإلغاء مطلوب (ثلاثة أحرف على الأقلّ)');
      return;
    }

    setError('');
    try {
      await cancelSalary(salary.id, reason.trim());
      await loadSalaries();
    } catch (err: any) {
      setError(err.message || 'فشل إلغاء الراتب');
    }
  }

  return (
    <div className="p-4 md:p-6 space-y-4" dir="rtl" style={{ background: C.bg, minHeight: '100%' }}>
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2">
          <Users size={22} style={{ color: C.forest }} />
          <h1 className="text-xl font-bold" style={{ color: C.ink }}>الإطارات</h1>
        </div>
        <div className="flex gap-2 flex-wrap">
          <button
            onClick={() => setTab('salaries')}
            className="px-3 py-2 rounded-xl text-sm font-semibold"
            style={{ background: tab === 'salaries' ? C.forest : '#fff', color: tab === 'salaries' ? '#fff' : C.ink, border: `1px solid ${C.line}` }}
          >الرواتب</button>
          <button
            onClick={() => setTab('advances')}
            className="px-3 py-2 rounded-xl text-sm font-semibold"
            style={{ background: tab === 'advances' ? C.forest : '#fff', color: tab === 'advances' ? '#fff' : C.ink, border: `1px solid ${C.line}` }}
          >التسبقات والسلف</button>
          <button
            onClick={() => setTab('staff')}
            className="px-3 py-2 rounded-xl text-sm font-semibold"
            style={{ background: tab === 'staff' ? C.forest : '#fff', color: tab === 'staff' ? '#fff' : C.ink, border: `1px solid ${C.line}` }}
          >قائمة الإطارات</button>
        </div>
      </div>

      {error && (
        <div className="rounded-xl px-3 py-2 text-sm" style={{ background: '#FEE2E2', color: '#991B1B' }}>{error}</div>
      )}

      {tab === 'salaries' && (
        <>
          <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3" style={{ borderColor: C.line }}>
            <div>
              <label className="text-xs" style={{ color: C.muted }}>السنة الدراسية</label>
              <select value={yearId} onChange={(e) => setYearId(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
                <option value="">كل السنوات</option>
                {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs" style={{ color: C.muted }}>الإطار</label>
              <select value={empFilter} onChange={(e) => setEmpFilter(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
                <option value="">كل الإطارات</option>
                {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
              </select>
            </div>
            <div className="flex items-end">
              <button
                onClick={() => { setSalForm((s) => ({ ...s, academic_year_id: yearId })); setShowSalForm(true); }}
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
                  <th>خصم التسبقة</th>
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
                    <td>{s.employee ? `${s.employee.first_name} ${s.employee.last_name}` : s.employee_id}</td>
                    <td>{s.gross_amount != null ? Number(s.gross_amount).toFixed(2) : '—'}</td>
                    <td style={{ color: Number(s.advance_deduction ?? 0) > 0 ? '#A03434' : C.muted }}>
                      {Number(s.advance_deduction ?? 0).toFixed(2)}
                    </td>
                    <td style={{ fontWeight: 700, color: C.forest }}>{Number(s.amount).toFixed(2)}</td>
                    <td>{String(s.period_from).slice(0, 10)}</td>
                    <td>{String(s.period_to).slice(0, 10)}</td>
                    <td>{s.paid_at ? String(s.paid_at).slice(0, 10) : '—'}</td>
                    <td>
                      {s.cancelled_at ? (
                        <span className="text-xs" style={{ color: '#A03434' }}>ملغى</span>
                      ) : (
                        <button onClick={() => onCancelSalary(s)} title="إلغاء موثّق"
                          className="p-2 rounded-lg" style={{ color: '#DC2626' }}>
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
      )}

      {tab === 'advances' && (
        <>
          <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3" style={{ borderColor: C.line }}>
            <div className="md:col-span-2">
              <label className="text-xs" style={{ color: C.muted }}>الإطار</label>
              <select value={empFilter} onChange={(e) => setEmpFilter(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
                <option value="">كل الإطارات</option>
                {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
              </select>
            </div>
            <div className="flex items-end">
              <button
                onClick={() => setShowAdvForm(true)}
                className="w-full py-2.5 rounded-xl text-white text-sm font-bold flex items-center justify-center gap-2"
                style={{ background: C.forest }}
              >
                <Plus size={16} /> منح تسبقة أو سلفة
              </button>
            </div>
          </div>

          <div className="rounded-xl px-3 py-2 text-xs" style={{ background: C.sage, color: C.ink }}>
            التسبقة تُخصم كاملة عند خلاص راتب الشهر نفسه، ولا تُخلّص من هنا. أمّا السلفة فتُردّ على مهل بزرّ «خلاص»، نقداً أو خصماً من الراتب.
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
                {advLoading ? (
                  <tr><td colSpan={9} className="p-6 text-center"><Loader2 className="inline animate-spin" /></td></tr>
                ) : advList.length === 0 ? (
                  <tr><td colSpan={9} className="p-6 text-center" style={{ color: C.muted }}>لا توجد تسبقات أو سلف</td></tr>
                ) : advList.map((a) => (
                  <React.Fragment key={a.id}>
                    <tr className="border-t" style={{ borderColor: C.line, opacity: a.cancelled_at ? 0.55 : 1 }}>
                      <td className="p-3">{a.id}</td>
                      <td>{a.employee ? `${a.employee.first_name} ${a.employee.last_name}` : a.employee_id}</td>
                      <td>{TYPE_LABELS[a.type] || a.type}</td>
                      <td>{String(a.advance_date).slice(0, 10)}</td>
                      <td style={{ fontWeight: 700 }}>{Number(a.amount).toFixed(2)}</td>
                      <td>{Number(a.settled_amount ?? 0).toFixed(2)}</td>
                      <td style={{ color: remainingOf(a) > 0 ? '#A03434' : C.forest }}>{remainingOf(a).toFixed(2)}</td>
                      <td style={{ color: C.muted }}>{a.reason || '—'}</td>
                      <td>
                        {a.cancelled_at ? (
                          <span className="text-xs" style={{ color: '#A03434' }}>ملغاة</span>
                        ) : a.settled_by_salary_id ? (
                          <span className="text-xs" style={{ color: C.muted }}>خُصمت من الراتب #{a.settled_by_salary_id}</span>
                        ) : (
                          <div className="flex gap-1">
                            {a.type === 'loan' && remainingOf(a) > 0 && (
                              <button onClick={() => openSettle(a)} title="خلاص"
                                className="p-2 rounded-lg" style={{ color: C.forest }}>
                                <Banknote size={16} />
                              </button>
                            )}
                            {a.type === 'loan' && (
                              <button onClick={() => toggleRepayments(a)} title="سجلّ الردّيات"
                                className="p-2 rounded-lg" style={{ color: openRepayFor === a.id ? C.forest : C.muted }}>
                                <History size={16} />
                              </button>
                            )}
                            <button onClick={() => onCancelAdvance(a)} title="إلغاء موثّق"
                              className="p-2 rounded-lg" style={{ color: '#DC2626' }}>
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
                                    <td>{String(r.repaid_at).slice(0, 10)}</td>
                                    <td style={{ fontWeight: 700 }}>{Number(r.amount).toFixed(2)}</td>
                                    <td>{REPAYMENT_METHOD_LABELS[r.method] || r.method}</td>
                                    <td style={{ color: C.muted }}>
                                      {r.method === 'cash' ? 'دخل الدفتر' : 'لا يمرّ بالصندوق'}
                                    </td>
                                    <td style={{ color: C.muted }}>{r.cancelled_at ? r.cancellation_reason : (r.notes || '—')}</td>
                                    <td>
                                      {r.cancelled_at ? (
                                        <span style={{ color: '#A03434' }}>ملغى</span>
                                      ) : r.salary_id ? (
                                        <span style={{ color: C.muted }}>ضمن الراتب #{r.salary_id}</span>
                                      ) : (
                                        <button onClick={() => onCancelRepayment(r)} title="إلغاء الردّ"
                                          className="p-1 rounded-lg" style={{ color: '#DC2626' }}>
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
      )}

      {tab === 'staff' && (
        <>
          <div className="flex justify-end">
            <button onClick={() => setShowEmpForm(true)} className="px-4 py-2.5 rounded-xl text-white text-sm font-bold flex items-center gap-2" style={{ background: C.forest }}>
              <Plus size={16} /> إضافة إطار
            </button>
          </div>
          <div className="bg-white rounded-2xl border overflow-x-auto" style={{ borderColor: C.line }}>
            <table className="w-full text-sm">
              <thead>
                <tr style={{ color: C.muted, textAlign: 'right' }}>
                  <th className="p-3">#</th>
                  <th>الاسم</th>
                  <th>اللقب</th>
                  <th>الهاتف</th>
                  <th>الوظيفة</th>
                  <th>الراتب الافتراضي</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {employees.map((e) => (
                  <tr key={e.id} className="border-t" style={{ borderColor: C.line }}>
                    <td className="p-3">{e.id}</td>
                    <td>{e.first_name}</td>
                    <td>{e.last_name}</td>
                    <td>{e.phone || '—'}</td>
                    <td>{e.job_title || '—'}</td>
                    <td>{e.default_salary != null ? Number(e.default_salary).toFixed(2) : '—'}</td>
                    <td>
                      <button onClick={async () => { if (confirm('حذف الإطار؟')) { await deleteEmployee(e.id); loadBase(); } }}
                        className="p-2" style={{ color: '#DC2626' }}><Trash2 size={16} /></button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {showEmpForm && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <form onSubmit={onCreateEmployee} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3">
            <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة إطار</h3>
            <input required placeholder="الاسم" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={empForm.first_name} onChange={(e) => setEmpForm({ ...empForm, first_name: e.target.value })} />
            <input required placeholder="اللقب" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={empForm.last_name} onChange={(e) => setEmpForm({ ...empForm, last_name: e.target.value })} />
            <input placeholder="الهاتف" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={empForm.phone} onChange={(e) => setEmpForm({ ...empForm, phone: e.target.value })} />
            <input placeholder="الوظيفة" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={empForm.job_title} onChange={(e) => setEmpForm({ ...empForm, job_title: e.target.value })} />
            <input type="number" step="0.01" placeholder="الراتب الافتراضي" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={empForm.default_salary} onChange={(e) => setEmpForm({ ...empForm, default_salary: e.target.value })} />
            <div className="flex gap-2 justify-end">
              <button type="button" onClick={() => setShowEmpForm(false)} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
              <button type="submit" disabled={saving} className="px-4 py-2 rounded-xl text-white font-bold" style={{ background: C.forest }}>
                {saving ? 'جاري...' : 'حفظ'}
              </button>
            </div>
          </form>
        </div>
      )}

      {showAdvForm && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <form onSubmit={onCreateAdvance} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto">
            <h3 className="font-bold text-lg" style={{ color: C.ink }}>منح تسبقة أو سلفة</h3>

            <select required className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={advForm.employee_id} onChange={(e) => setAdvForm({ ...advForm, employee_id: e.target.value })}>
              <option value="">اختر الإطار</option>
              {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
            </select>

            <div>
              <label className="text-xs" style={{ color: C.muted }}>النوع</label>
              <select className="w-full mt-1 border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={advForm.type} onChange={(e) => setAdvForm({ ...advForm, type: e.target.value as 'advance' | 'loan' })}>
                <option value="advance">تسبقة — تُخصم من راتب الشهر</option>
                <option value="loan">سلفة — تُردّ على مهل</option>
              </select>
            </div>

            <input required type="number" step="0.01" placeholder="المبلغ" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={advForm.amount} onChange={(e) => setAdvForm({ ...advForm, amount: e.target.value })} />

            <input required type="date" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={advForm.advance_date} onChange={(e) => setAdvForm({ ...advForm, advance_date: e.target.value })} />

            <input placeholder="السبب (اختياري)" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={advForm.reason} onChange={(e) => setAdvForm({ ...advForm, reason: e.target.value })} />

            <TreasuryBalanceHint amount={Number(advForm.amount || 0)} refreshKey={showAdvForm} />

            <p className="text-xs" style={{ color: C.muted }}>
              هذا المبلغ يخرج من الخزينة يوم منحه ويُسجّل في الدفتر النقدي.
            </p>

            <div className="flex gap-2 justify-end">
              <button type="button" onClick={() => setShowAdvForm(false)} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
              <button type="submit" disabled={saving} className="px-4 py-2 rounded-xl text-white font-bold" style={{ background: C.forest }}>
                {saving ? 'جاري...' : 'حفظ'}
              </button>
            </div>
          </form>
        </div>
      )}

      {settleTarget && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <form onSubmit={onSubmitSettle} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto">
            <h3 className="font-bold text-lg" style={{ color: C.ink }}>خلاص سلفة</h3>

            <div className="rounded-xl p-3 text-sm space-y-1" style={{ background: C.sage, color: C.ink }}>
              <div className="flex justify-between">
                <span>الإطار</span>
                <strong>
                  {settleTarget.employee
                    ? `${settleTarget.employee.first_name} ${settleTarget.employee.last_name}`
                    : settleTarget.employee_id}
                </strong>
              </div>
              <div className="flex justify-between">
                <span>أصل السلفة</span>
                <strong>{Number(settleTarget.amount).toFixed(2)}</strong>
              </div>
              <div className="flex justify-between">
                <span>المتبقّي</span>
                <strong style={{ color: '#A03434' }}>{remainingOf(settleTarget).toFixed(2)}</strong>
              </div>
            </div>

            <div>
              <label className="text-xs" style={{ color: C.muted }}>المبلغ المردود</label>
              <input required type="number" step="0.01" min="0.01" className="w-full mt-1 border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={settleForm.amount} onChange={(e) => setSettleForm({ ...settleForm, amount: e.target.value })} />
            </div>

            <div>
              <label className="text-xs" style={{ color: C.muted }}>طريقة الردّ</label>
              <select className="w-full mt-1 border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={settleForm.method}
                onChange={(e) => setSettleForm({ ...settleForm, method: e.target.value as RepaymentMethod })}>
                <option value="cash">نقداً — يدخل الخزينة</option>
                <option value="salary_deduction">خصم من الراتب — لا يمرّ بالصندوق</option>
              </select>
            </div>

            <div>
              <label className="text-xs" style={{ color: C.muted }}>تاريخ الردّ</label>
              <input required type="date" className="w-full mt-1 border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={settleForm.repaid_at} onChange={(e) => setSettleForm({ ...settleForm, repaid_at: e.target.value })} />
            </div>

            <input placeholder="ملاحظة (اختيارية)" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={settleForm.notes} onChange={(e) => setSettleForm({ ...settleForm, notes: e.target.value })} />

            <p className="text-xs" style={{ color: C.muted }}>
              {settleForm.method === 'cash'
                ? 'المبلغ يدخل الدرج فعلاً، ويُسجّل مدخولاً في بند «خلاص سلفة» بتاريخ الردّ.'
                : 'لا مال يدخل الخزينة: الإطار سيقبض راتباً أقلّ، فيُنقَص الدَين فحسب دون سطر في الدفتر.'}
            </p>

            <div className="flex gap-2 justify-end">
              <button type="button" onClick={() => setSettleTarget(null)} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
              <button type="submit" disabled={saving} className="px-4 py-2 rounded-xl text-white font-bold disabled:opacity-50" style={{ background: C.forest }}>
                {saving ? 'جاري...' : 'تسجيل الردّ'}
              </button>
            </div>
          </form>
        </div>
      )}

      {showSalForm && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <form onSubmit={onCreateSalary} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3 max-h-[90vh] overflow-y-auto">
            <h3 className="font-bold text-lg" style={{ color: C.ink }}>خلاص راتب</h3>
            <select required className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={salForm.employee_id} onChange={(e) => setSalForm({ ...salForm, employee_id: e.target.value })}>
              <option value="">اختر الإطار</option>
              {employees.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}
            </select>
            <select required className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={salForm.academic_year_id || yearId} onChange={(e) => setSalForm({ ...salForm, academic_year_id: e.target.value })}>
              <option value="">السنة</option>
              {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
            </select>

            <div>
              <label className="text-xs" style={{ color: C.muted }}>الراتب الخام</label>
              <input required type="number" step="0.01" placeholder="مثال: 500" className="w-full mt-1 border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={salForm.gross_amount} onChange={(e) => setSalForm({ ...salForm, gross_amount: e.target.value })} />
            </div>

            {salForm.employee_id && (
              <div className="rounded-xl p-3" style={{ background: C.sage }}>
                <p className="text-xs font-bold mb-2" style={{ color: C.ink }}>التسبقات القائمة</p>

                {advancesLoading ? (
                  <div className="animate-pulse space-y-2" role="status" aria-label="تحميل التسبقات">
                    <div className="h-3 w-3/4 rounded bg-white/70" />
                    <div className="h-3 w-1/2 rounded bg-white/70" />
                  </div>
                ) : advances.length === 0 ? (
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
                        <strong>{remainingOf(a).toFixed(2)}</strong>
                      </label>
                    ))}
                  </div>
                )}
              </div>
            )}

            <div className="rounded-xl border p-3 text-sm space-y-1" style={{ borderColor: C.line }}>
              <div className="flex justify-between">
                <span style={{ color: C.muted }}>الخام</span>
                <span>{grossValue.toFixed(2)}</span>
              </div>
              <div className="flex justify-between">
                <span style={{ color: C.muted }}>خصم التسبقات</span>
                <span style={{ color: '#A03434' }}>{deduction.toFixed(2)}</span>
              </div>
              <div className="flex justify-between font-bold">
                <span style={{ color: C.ink }}>المدفوع نقداً</span>
                <span style={{ color: netValue < 0 ? '#A03434' : C.forest }}>{netValue.toFixed(2)}</span>
              </div>
              {netValue < 0 && (
                <p className="text-xs" style={{ color: '#A03434' }}>
                  التسبقات تتجاوز الراتب الخام.
                </p>
              )}
            </div>

            {/* التحذير يقارن الرصيد بالمدفوع نقداً لا بالخام، لأنّ التسبقة خرجت من الخزينة سابقاً. */}
            <TreasuryBalanceHint amount={netValue > 0 ? netValue : 0} refreshKey={showSalForm} />

            <div className="grid grid-cols-2 gap-2">
              <input required type="date" className="border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={salForm.period_from} onChange={(e) => setSalForm({ ...salForm, period_from: e.target.value })} />
              <input required type="date" className="border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
                value={salForm.period_to} onChange={(e) => setSalForm({ ...salForm, period_to: e.target.value })} />
            </div>
            <input type="date" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={salForm.paid_at} onChange={(e) => setSalForm({ ...salForm, paid_at: e.target.value })} />
            <div className="flex gap-2 justify-end">
              <button type="button" onClick={() => setShowSalForm(false)} className="px-4 py-2 rounded-xl border" style={{ borderColor: C.line }}>إلغاء</button>
              <button type="submit" disabled={saving || netValue < 0} className="px-4 py-2 rounded-xl text-white font-bold disabled:opacity-50" style={{ background: C.forest }}>
                {saving ? 'جاري...' : 'حفظ'}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
