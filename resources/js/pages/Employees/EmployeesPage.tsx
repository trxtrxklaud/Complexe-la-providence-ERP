import React, { useEffect, useState } from 'react';
import { Users, Plus, Trash2, Loader2 } from 'lucide-react';
import {
  getEmployees, createEmployee, deleteEmployee,
  getSalaries, createSalary, deleteSalary,
  type Employee, type Salary,
} from '../../api/employees';


const C = {
  forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C',
  muted: '#7C8677', line: '#EDF1E8', bg: '#F4F6F1',
};

type Tab = 'salaries' | 'staff';

async function fetchYears() {
  const token = localStorage.getItem('token');
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

  const [empForm, setEmpForm] = useState({
    first_name: '', last_name: '', phone: '', job_title: '', default_salary: '',
  });
  const [salForm, setSalForm] = useState({
    employee_id: '', academic_year_id: '', amount: '',
    period_from: '', period_to: '', paid_at: new Date().toISOString().slice(0, 10),
    method: 'cash',
  });

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

  useEffect(() => { loadBase(); }, []);
  useEffect(() => { if (tab === 'salaries') loadSalaries(); }, [tab, yearId, empFilter]);

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

  async function onCreateSalary(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true); setError('');
    try {
      await createSalary({
        employee_id: Number(salForm.employee_id),
        academic_year_id: Number(salForm.academic_year_id || yearId),
        amount: Number(salForm.amount),
        period_from: salForm.period_from,
        period_to: salForm.period_to,
        paid_at: salForm.paid_at || undefined,
        method: salForm.method,
      });
      setShowSalForm(false);
      setSalForm({
        employee_id: '', academic_year_id: yearId, amount: '',
        period_from: '', period_to: '', paid_at: new Date().toISOString().slice(0, 10), method: 'cash',
      });
      await loadSalaries();
    } catch (err: any) {
      setError(err.message || 'فشل حفظ الراتب');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="p-4 md:p-6 space-y-4" dir="rtl" style={{ background: C.bg, minHeight: '100%' }}>
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2">
          <Users size={22} style={{ color: C.forest }} />
          <h1 className="text-xl font-bold" style={{ color: C.ink }}>الإطارات</h1>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => setTab('salaries')}
            className="px-3 py-2 rounded-xl text-sm font-semibold"
            style={{ background: tab === 'salaries' ? C.forest : '#fff', color: tab === 'salaries' ? '#fff' : C.ink, border: `1px solid ${C.line}` }}
          >الرواتب</button>
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
                <Plus size={16} /> إضافة راتب
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
                  <th>المبلغ</th>
                  <th>من</th>
                  <th>إلى</th>
                  <th>تاريخ الدفع</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={8} className="p-6 text-center"><Loader2 className="inline animate-spin" /></td></tr>
                ) : salaries.length === 0 ? (
                  <tr><td colSpan={8} className="p-6 text-center" style={{ color: C.muted }}>لا توجد رواتب</td></tr>
                ) : salaries.map((s) => (
                  <tr key={s.id} className="border-t" style={{ borderColor: C.line }}>
                    <td className="p-3">{s.id}</td>
                    <td>{s.academic_year?.name || s.academic_year_id}</td>
                    <td>{s.employee ? `${s.employee.first_name} ${s.employee.last_name}` : s.employee_id}</td>
                    <td style={{ fontWeight: 700, color: C.forest }}>{Number(s.amount).toFixed(2)}</td>
                    <td>{String(s.period_from).slice(0, 10)}</td>
                    <td>{String(s.period_to).slice(0, 10)}</td>
                    <td>{s.paid_at ? String(s.paid_at).slice(0, 10) : '—'}</td>
                    <td>
                      <button onClick={async () => { if (confirm('حذف؟')) { await deleteSalary(s.id); loadSalaries(); } }}
                        className="p-2 rounded-lg" style={{ color: '#DC2626' }}>
                        <Trash2 size={16} />
                      </button>
                    </td>
                  </tr>
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

      {showSalForm && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <form onSubmit={onCreateSalary} className="bg-white rounded-2xl p-5 w-full max-w-md space-y-3">
            <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة راتب</h3>
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
            <input required type="number" step="0.01" placeholder="المبلغ" className="w-full border rounded-xl px-3 py-2" style={{ borderColor: C.line }}
              value={salForm.amount} onChange={(e) => setSalForm({ ...salForm, amount: e.target.value })} />
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
              <button type="submit" disabled={saving} className="px-4 py-2 rounded-xl text-white font-bold" style={{ background: C.forest }}>
                {saving ? 'جاري...' : 'حفظ'}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
