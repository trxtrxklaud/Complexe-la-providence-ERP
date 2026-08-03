import { useEffect, useState } from 'react';
import { Users } from 'lucide-react';
import {
  getEmployees, createEmployee, deleteEmployee,
  getSalaries, createSalary, cancelSalary,
  getAdvances, createAdvance, settleAdvance, cancelAdvance,
  getRepayments, cancelRepayment,
  type Employee, type Salary, type EmployeeAdvance, type AdvanceRepayment,
} from '../../api/employees';
import { CancelReasonModal } from '../../components/CancelReasonModal';
import {
  C, fetchYears, CANCEL_TITLES, CANCEL_DESCRIPTIONS,
  type Tab, type AcademicYearOption, type CancelTarget,
} from './shared';
import { SalariesTab } from './SalariesTab';
import { AdvancesTab } from './AdvancesTab';
import { StaffTab } from './StaffTab';
import { EmployeeFormModal, type EmployeeFormValues } from './EmployeeFormModal';
import { AdvanceFormModal, type AdvanceFormValues } from './AdvanceFormModal';
import { SettleAdvanceModal, type SettleFormValues } from './SettleAdvanceModal';
import { SalaryFormModal, type SalaryFormValues } from './SalaryFormModal';

const TABS: Array<{ key: Tab; label: string }> = [
  { key: 'salaries', label: 'الرواتب' },
  { key: 'advances', label: 'التسبقات والسلف' },
  { key: 'staff', label: 'قائمة الإطارات' },
];

/**
 * منسّق شاشة الإطارات.
 *
 * لا يرسم هذا الملف جدولاً ولا نموذجاً: مهمته جلب البيانات، وحفظ حالة
 * المرشّحات، وتمرير الأوامر إلى مكوّنات العرض. النماذج تحمل حالتها بنفسها.
 */
export function EmployeesPage() {
  const [tab, setTab] = useState<Tab>('salaries');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [years, setYears] = useState<AcademicYearOption[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [salaries, setSalaries] = useState<Salary[]>([]);
  const [yearId, setYearId] = useState('');
  const [empFilter, setEmpFilter] = useState('');

  const [advList, setAdvList] = useState<EmployeeAdvance[]>([]);
  const [advLoading, setAdvLoading] = useState(false);

  const [settleTarget, setSettleTarget] = useState<EmployeeAdvance | null>(null);

  const [openRepayFor, setOpenRepayFor] = useState<number | null>(null);
  const [repayments, setRepayments] = useState<AdvanceRepayment[]>([]);
  const [repayLoading, setRepayLoading] = useState(false);

  const [showEmpForm, setShowEmpForm] = useState(false);
  const [showAdvForm, setShowAdvForm] = useState(false);
  const [showSalForm, setShowSalForm] = useState(false);

  // الإلغاء الثلاثي مجموع في حالة واحدة لتخدمه نافذة واحدة.
  const [cancelTarget, setCancelTarget] = useState<CancelTarget | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);

  async function loadBase() {
    setLoading(true);
    setError('');
    try {
      const [yearRows, employeeRows] = await Promise.all([fetchYears(), getEmployees()]);
      setYears(yearRows);
      setEmployees(employeeRows);

      const active = yearRows.find((y) => y.is_active) || yearRows[0];
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
      setAdvList(await getAdvances({
        employee_id: empFilter ? Number(empFilter) : undefined,
      }));
    } catch (err: any) {
      setError(err.message || 'فشل تحميل التسبقات');
    } finally {
      setAdvLoading(false);
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

  useEffect(() => { loadBase(); }, []);
  useEffect(() => { if (tab === 'salaries') loadSalaries(); }, [tab, yearId, empFilter]);
  useEffect(() => { if (tab === 'advances') loadAdvances(); }, [tab, empFilter]);

  async function onCreateEmployee(values: EmployeeFormValues) {
    setSaving(true); setError('');
    try {
      await createEmployee({
        first_name: values.first_name,
        last_name: values.last_name,
        phone: values.phone || null,
        job_title: values.job_title || null,
        default_salary: values.default_salary ? Number(values.default_salary) : null,
        is_active: true,
      });
      setShowEmpForm(false);
      await loadBase();
      setTab('staff');
    } catch (err: any) {
      setError(err.message || 'فشل الحفظ');
    } finally {
      setSaving(false);
    }
  }

  async function onDeleteEmployee(employee: Employee) {
    if (!confirm('حذف الإطار؟')) return;

    setError('');
    try {
      await deleteEmployee(employee.id);
      await loadBase();
    } catch (err: any) {
      setError(err.message || 'فشل الحذف');
    }
  }

  async function onCreateAdvance(values: AdvanceFormValues) {
    setSaving(true); setError('');
    try {
      await createAdvance({
        employee_id: Number(values.employee_id),
        academic_year_id: yearId ? Number(yearId) : undefined,
        type: values.type,
        amount: Number(values.amount),
        advance_date: values.advance_date,
        method: values.method,
        reason: values.reason || undefined,
      });
      setShowAdvForm(false);
      await loadAdvances();
    } catch (err: any) {
      setError(err.message || 'فشل حفظ التسبقة');
    } finally {
      setSaving(false);
    }
  }

  async function onSubmitSettle(values: SettleFormValues) {
    if (!settleTarget) return;

    setSaving(true); setError('');
    try {
      await settleAdvance(settleTarget.id, {
        amount: values.amount,
        method: values.method,
        repaid_at: values.repaid_at || undefined,
        notes: values.notes || undefined,
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

  async function onToggleRepayments(advance: EmployeeAdvance) {
    if (openRepayFor === advance.id) {
      setOpenRepayFor(null);
      setRepayments([]);
      return;
    }

    setOpenRepayFor(advance.id);
    setRepayments([]);
    await loadRepayments(advance.id);
  }

  async function onCreateSalary(values: SalaryFormValues) {
    setSaving(true); setError('');
    try {
      await createSalary(values);
      setShowSalForm(false);
      await loadSalaries();
    } catch (err: any) {
      setError(err.message || 'فشل حفظ الراتب');
    } finally {
      setSaving(false);
    }
  }

  /**
   * تنفيذ الإلغاء بعد كتابة السبب.
   *
   * النافذة تفرض ثلاثة أحرف كما يفرضها الخادم، فلا حاجة لتحقّق ثانٍ هنا.
   * تبقى النافذة مفتوحة عند الفشل حتّى لا يضيع ما كتبه القابض.
   */
  async function confirmCancel(reason: string) {
    if (!cancelTarget) return;

    setCancelBusy(true);
    setError('');

    try {
      if (cancelTarget.kind === 'salary') {
        await cancelSalary(cancelTarget.id, reason);
        await loadSalaries();
        // إلغاء الراتب يردّ التسبقات وأقساط السلف إلى الذمّة، فيجب تحيين القائمة.
        if (tab === 'advances') await loadAdvances();
      } else if (cancelTarget.kind === 'advance') {
        await cancelAdvance(cancelTarget.id, reason);
        await loadAdvances();
      } else {
        await cancelRepayment(cancelTarget.id, reason);
        await loadRepayments(cancelTarget.advanceId);
        await loadAdvances();
      }

      setCancelTarget(null);
    } catch (err: any) {
      setError(err.message || 'فشل الإلغاء');
    } finally {
      setCancelBusy(false);
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
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className="px-3 py-2 rounded-xl text-sm font-semibold"
              style={{
                background: tab === t.key ? C.forest : '#fff',
                color: tab === t.key ? '#fff' : C.ink,
                border: `1px solid ${C.line}`,
              }}
            >{t.label}</button>
          ))}
        </div>
      </div>

      {error && (
        <div className="rounded-xl px-3 py-2 text-sm" style={{ background: '#FEE2E2', color: '#991B1B' }}>{error}</div>
      )}

      {tab === 'salaries' && (
        <SalariesTab
          years={years}
          employees={employees}
          yearId={yearId}
          onYearChange={setYearId}
          empFilter={empFilter}
          onEmpFilterChange={setEmpFilter}
          salaries={salaries}
          loading={loading}
          onNewSalary={() => setShowSalForm(true)}
          onCancelSalary={(salary) => setCancelTarget({ kind: 'salary', id: salary.id })}
        />
      )}

      {tab === 'advances' && (
        <AdvancesTab
          employees={employees}
          empFilter={empFilter}
          onEmpFilterChange={setEmpFilter}
          advances={advList}
          loading={advLoading}
          openRepayFor={openRepayFor}
          repayments={repayments}
          repayLoading={repayLoading}
          onNewAdvance={() => setShowAdvForm(true)}
          onSettle={(advance) => { setError(''); setSettleTarget(advance); }}
          onToggleRepayments={onToggleRepayments}
          onCancelAdvance={(advance) => setCancelTarget({ kind: 'advance', id: advance.id })}
          onCancelRepayment={(repayment) => setCancelTarget({
            kind: 'repayment',
            id: repayment.id,
            advanceId: repayment.employee_advance_id,
          })}
        />
      )}

      {tab === 'staff' && (
        <StaffTab
          employees={employees}
          onNewEmployee={() => setShowEmpForm(true)}
          onDeleteEmployee={onDeleteEmployee}
        />
      )}

      {showEmpForm && (
        <EmployeeFormModal
          saving={saving}
          onClose={() => setShowEmpForm(false)}
          onSubmit={onCreateEmployee}
        />
      )}

      {showAdvForm && (
        <AdvanceFormModal
          employees={employees}
          saving={saving}
          onClose={() => setShowAdvForm(false)}
          onSubmit={onCreateAdvance}
        />
      )}

      {settleTarget && (
        <SettleAdvanceModal
          advance={settleTarget}
          saving={saving}
          onClose={() => setSettleTarget(null)}
          onError={setError}
          onSubmit={onSubmitSettle}
        />
      )}

      {showSalForm && (
        <SalaryFormModal
          employees={employees}
          years={years}
          defaultYearId={yearId}
          saving={saving}
          onClose={() => setShowSalForm(false)}
          onError={setError}
          onSubmit={onCreateSalary}
        />
      )}

      {cancelTarget && (
        <CancelReasonModal
          title={CANCEL_TITLES[cancelTarget.kind]}
          description={CANCEL_DESCRIPTIONS[cancelTarget.kind]}
          busy={cancelBusy}
          onConfirm={confirmCancel}
          onClose={() => setCancelTarget(null)}
        />
      )}
    </div>
  );
}
