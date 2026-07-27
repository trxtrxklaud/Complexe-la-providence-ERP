import { useEffect, useState } from 'react';
import { PlusCircle, Save, Ban } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import { CancelReasonModal } from '../../components/CancelReasonModal';
import {
  fetchExpenseCategories,
  fetchExpenses,
  createExpense,
  cancelExpense,
  type Expense,
  type ExpenseCategory,
} from '../../api/expenses';
import { fetchYears, type AcademicYear } from '../../api/roster';
import { errorMessage, money, personName, today } from '../../lib/format';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

const METHODS: Array<{ value: string; label: string }> = [
  { value: 'cash', label: 'نقداً' },
  { value: 'bank_transfer', label: 'تحويل بنكي' },
  { value: 'check', label: 'شيك' },
  { value: 'card', label: 'بطاقة' },
];

const METHOD_LABELS: Record<string, string> = {
  cash: 'نقداً',
  bank_transfer: 'تحويل بنكي',
  check: 'شيك',
  card: 'بطاقة',
};

export function ExpenseCreatePage() {
  const [categories, setCategories] = useState<ExpenseCategory[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);

  const [label, setLabel] = useState('');
  const [amount, setAmount] = useState('');
  const [expenseDate, setExpenseDate] = useState(today());
  const [categoryId, setCategoryId] = useState<number | ''>('');
  const [yearId, setYearId] = useState<number | ''>('');
  const [method, setMethod] = useState('cash');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');

  const [recent, setRecent] = useState<Expense[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [cancelTarget, setCancelTarget] = useState<Expense | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 4000);
  };

  const loadRecent = async () => {
    setLoading(true);
    try {
      const page = await fetchExpenses({ per_page: 15 });
      setRecent(page.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    (async () => {
      try {
        const [cats, yrs] = await Promise.all([fetchExpenseCategories(true), fetchYears()]);
        setCategories(cats);
        setYears(yrs);
        const active = yrs.find((y) => y.is_active) ?? yrs[0];
        if (active) setYearId(active.id);
      } catch (err) {
        setError(errorMessage(err));
      }
      await loadRecent();
    })();
  }, []);

  const resetForm = () => {
    setLabel('');
    setAmount('');
    setReference('');
    setNotes('');
    setExpenseDate(today());
  };

  const submit = async () => {
    const value = Number(amount);

    if (label.trim().length === 0) {
      setError('بيان المصروف إجباري');
      return;
    }
    if (!Number.isFinite(value) || value <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }

    setSaving(true);
    setError('');
    try {
      await createExpense({
        label: label.trim(),
        amount: value,
        expense_date: expenseDate,
        expense_category_id: categoryId === '' ? null : categoryId,
        academic_year_id: yearId === '' ? null : yearId,
        method: method || null,
        reference: reference.trim() || null,
        notes: notes.trim() || null,
      });
      resetForm();
      flash('تمّ تسجيل المصروف وإسقاطه في دفتر الخزينة');
      await loadRecent();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const confirmCancel = async (reason: string) => {
    if (!cancelTarget) return;
    setCancelBusy(true);
    setError('');
    try {
      await cancelExpense(cancelTarget.id, reason);
      setCancelTarget(null);
      flash('تمّ إلغاء المصروف وسحب أثره من الخزينة');
      await loadRecent();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setCancelBusy(false);
    }
  };

  const fieldStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const fieldCls = 'w-full px-3 py-2.5 rounded-xl text-sm';

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <PageShell
        title="إنشاء مصروف"
        subtitle="تسجيل مصروف جديد (الصنف، المبلغ، التاريخ، طريقة الدفع)"
        icon={PlusCircle}
      >
        <div>
          {error ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>
          ) : null}
          {notice ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div>
          ) : null}

          {/* نموذج التسجيل */}
          <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
              <div className="lg:col-span-2">
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>بيان المصروف *</label>
                <input value={label} onChange={(e) => setLabel(e.target.value)} className={fieldCls} style={fieldStyle} placeholder="مثال: مواد تنظيف" />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المبلغ *</label>
                <input value={amount} onChange={(e) => setAmount(e.target.value)} type="number" step="0.01" min="0" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} placeholder="0.00" />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>التاريخ *</label>
                <input value={expenseDate} onChange={(e) => setExpenseDate(e.target.value)} type="date" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الصنف</label>
                <select value={categoryId} onChange={(e) => setCategoryId(e.target.value ? Number(e.target.value) : '')} className={fieldCls} style={fieldStyle}>
                  <option value="">دون صنف</option>
                  {categories.map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>طريقة الدفع</label>
                <select value={method} onChange={(e) => setMethod(e.target.value)} className={fieldCls} style={fieldStyle}>
                  {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية</label>
                <select value={yearId} onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')} className={fieldCls} style={fieldStyle}>
                  <option value="">دون سنة</option>
                  {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المرجع</label>
                <input value={reference} onChange={(e) => setReference(e.target.value)} className={fieldCls} style={fieldStyle} placeholder="رقم الفاتورة أو الشيك" />
              </div>
              <div className="lg:col-span-2">
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظات</label>
                <input value={notes} onChange={(e) => setNotes(e.target.value)} className={fieldCls} style={fieldStyle} />
              </div>
            </div>

            <div className="flex items-center gap-3 mt-5">
              <button
                type="button"
                onClick={() => void submit()}
                disabled={saving}
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                <Save size={18} />
                <span>{saving ? 'جارٍ التسجيل…' : 'تسجيل المصروف'}</span>
              </button>
              <p className="text-xs" style={{ color: C.muted }}>كل مصروف يُسجَّل تلقائياً في دفتر الخزينة المركزي.</p>
            </div>
          </div>

          {/* آخر المصاريف */}
          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
            <div className="px-5 py-4" style={{ backgroundColor: C.sage }}>
              <h3 className="font-bold" style={{ color: C.deep }}>آخر المصاريف المسجّلة</h3>
              <p className="text-xs mt-0.5" style={{ color: C.muted }}>المصاريف تُلغى بسبب موثّق ولا تُحذف</p>
            </div>

            {loading ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>جارٍ التحميل…</p>
            ) : recent.length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا مصاريف مسجّلة بعد.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                      <th className="text-right px-3 py-3 font-medium">التاريخ</th>
                      <th className="text-right px-3 py-3 font-medium">البيان</th>
                      <th className="text-right px-3 py-3 font-medium">الصنف</th>
                      <th className="text-right px-3 py-3 font-medium">المبلغ</th>
                      <th className="text-right px-3 py-3 font-medium">الطريقة</th>
                      <th className="text-right px-3 py-3 font-medium">المستخدم</th>
                      <th className="px-3 py-3" style={{ width: '6rem' }} />
                    </tr>
                  </thead>
                  <tbody>
                    {recent.map((item) => {
                      const cancelled = Boolean(item.cancelled_at);

                      return (
                        <tr key={item.id} style={{ borderBottom: '1px solid ' + C.line, opacity: cancelled ? 0.55 : 1 }}>
                          <td className="px-3 py-2.5 text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{item.expense_date}</td>
                          <td className="px-3 py-2.5" style={{ color: C.ink, textDecoration: cancelled ? 'line-through' : 'none' }}>
                            {item.label}
                            {cancelled && item.cancellation_reason ? (
                              <span className="block text-xs mt-0.5" style={{ color: C.error, textDecoration: 'none' }}>ملغى: {item.cancellation_reason}</span>
                            ) : null}
                          </td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>{item.category?.name ?? '—'}</td>
                          <td className="px-3 py-2.5 font-medium" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{money(item.amount)}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>{item.method ? (METHOD_LABELS[item.method] ?? item.method) : '—'}</td>
                          <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{personName(item.created_by)}</td>
                          <td className="px-3 py-2.5">
                            {cancelled ? (
                              <span className="text-xs" style={{ color: C.error }}>ملغى</span>
                            ) : (
                              <button
                                type="button"
                                onClick={() => setCancelTarget(item)}
                                title="إلغاء"
                                className="p-1.5 rounded-lg bg-gray-50"
                              >
                                <Ban size={14} color={C.error} />
                              </button>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      </PageShell>

      {cancelTarget ? (
        <CancelReasonModal
          title="إلغاء مصروف"
          description={'سيُلغى المصروف «' + cancelTarget.label + '» بمبلغ ' + money(cancelTarget.amount) + ' ويُسحب أثره من دفتر الخزينة.'}
          busy={cancelBusy}
          onConfirm={(reason) => void confirmCancel(reason)}
          onClose={() => setCancelTarget(null)}
        />
      ) : null}
    </div>
  );
}

export default ExpenseCreatePage;
