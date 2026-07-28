import { useEffect, useState } from 'react';
import { ArrowDownCircle, Save, Ban } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import { CancelReasonModal } from '../../components/CancelReasonModal';
import {
  fetchWithdrawals,
  createWithdrawal,
  cancelWithdrawal,
  fetchTreasuryBalance,
  type TreasuryWithdrawal,
  type TreasurySummary,
} from '../../api/treasury';
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

export function TreasuryWithdrawalsPage() {
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [items, setItems] = useState<TreasuryWithdrawal[]>([]);
  const [summary, setSummary] = useState<TreasurySummary | null>(null);

  const [amount, setAmount] = useState('');
  const [withdrawnAt, setWithdrawnAt] = useState(today());
  const [type, setType] = useState('');
  const [note, setNote] = useState('');
  const [yearId, setYearId] = useState<number | ''>('');

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [cancelTarget, setCancelTarget] = useState<TreasuryWithdrawal | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 4000);
  };

  const reload = async () => {
    setLoading(true);
    try {
      const [page, balance] = await Promise.all([fetchWithdrawals({ per_page: 20 }), fetchTreasuryBalance()]);
      setItems(page.data);
      setSummary(balance);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    (async () => {
      try {
        const yrs = await fetchYears();
        setYears(yrs);
        const active = yrs.find((y) => y.is_active) ?? yrs[0];
        if (active) setYearId(active.id);
      } catch (err) {
        setError(errorMessage(err));
      }
      await reload();
    })();
  }, []);

  const submit = async () => {
    const value = Number(amount);

    if (!Number.isFinite(value) || value <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }

    setSaving(true);
    setError('');
    try {
      await createWithdrawal({
        amount: value,
        withdrawn_at: withdrawnAt,
        type: type.trim() || null,
        note: note.trim() || null,
        academic_year_id: yearId === '' ? null : yearId,
      });
      setAmount('');
      setType('');
      setNote('');
      setWithdrawnAt(today());
      flash('تمّ تسجيل السحب وخصمه من رصيد الخزينة');
      await reload();
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
      await cancelWithdrawal(cancelTarget.id, reason);
      setCancelTarget(null);
      flash('تمّ إلغاء السحب وإرجاع المبلغ إلى الرصيد');
      await reload();
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
        title="السحوبات"
        subtitle="سحب نقدي من الخزينة — لا يُحتسب مصروفاً ولا يؤثّر على الدخل الصافي"
        icon={ArrowDownCircle}
      >
        <div>
          {error ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>
          ) : null}
          {notice ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div>
          ) : null}

          {/* الرصيد الحالي */}
          {summary ? (
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
              {[
                { label: 'مجموع المداخيل', value: summary.income, color: C.deep },
                { label: 'مجموع المصاريف', value: summary.expenses, color: C.error },
                { label: 'مجموع السحوبات', value: summary.withdrawals, color: C.error },
                { label: 'الرصيد النهائي', value: summary.balance, color: C.forest },
              ].map((card) => (
                <div key={card.label} className="bg-white rounded-2xl p-4" style={{ border: '1px solid ' + C.line }}>
                  <p className="text-xs mb-1" style={{ color: C.muted }}>{card.label}</p>
                  <p className="text-lg font-bold" style={{ color: card.color, direction: 'ltr', textAlign: 'right' }}>{money(card.value)}</p>
                </div>
              ))}
            </div>
          ) : null}

          {/* نموذج السحب */}
          <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المبلغ *</label>
                <input value={amount} onChange={(e) => setAmount(e.target.value)} type="number" step="0.01" min="0" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} placeholder="0.00" />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>التاريخ *</label>
                <input value={withdrawnAt} onChange={(e) => setWithdrawnAt(e.target.value)} type="date" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>نوع السحب</label>
                <input value={type} onChange={(e) => setType(e.target.value)} className={fieldCls} style={fieldStyle} placeholder="مثال: إيداع بنكي" />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية</label>
                <select value={yearId} onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')} className={fieldCls} style={fieldStyle}>
                  <option value="">دون سنة</option>
                  {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
                </select>
              </div>
              <div className="sm:col-span-2 lg:col-span-4">
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظة</label>
                <input value={note} onChange={(e) => setNote(e.target.value)} className={fieldCls} style={fieldStyle} />
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
                <span>{saving ? 'جارٍ التسجيل…' : 'تسجيل السحب'}</span>
              </button>
              <p className="text-xs" style={{ color: C.muted }}>السحب يُخصم من الرصيد بعد الدخل الصافي، مطابقاً للتقرير القديم.</p>
            </div>
          </div>

          {/* قائمة السحوبات */}
          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
            <div className="px-5 py-4" style={{ backgroundColor: C.sage }}>
              <h3 className="font-bold" style={{ color: C.deep }}>السحوبات المسجّلة</h3>
            </div>

            {loading ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>جارٍ التحميل…</p>
            ) : items.length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا سحوبات مسجّلة بعد.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                      <th className="text-right px-3 py-3 font-medium">التاريخ</th>
                      <th className="text-right px-3 py-3 font-medium">المبلغ</th>
                      <th className="text-right px-3 py-3 font-medium">النوع</th>
                      <th className="text-right px-3 py-3 font-medium">ملاحظة</th>
                      <th className="text-right px-3 py-3 font-medium">المستخدم</th>
                      <th className="px-3 py-3" style={{ width: '6rem' }} />
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((item) => {
                      const cancelled = Boolean(item.cancelled_at);

                      return (
                        <tr key={item.id} style={{ borderBottom: '1px solid ' + C.line, opacity: cancelled ? 0.55 : 1 }}>
                          <td className="px-3 py-2.5 text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{item.withdrawn_at}</td>
                          <td className="px-3 py-2.5 font-medium" style={{ color: C.ink, direction: 'ltr', textAlign: 'right', textDecoration: cancelled ? 'line-through' : 'none' }}>{money(item.amount)}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>{item.type || '—'}</td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>
                            {item.note || '—'}
                            {cancelled && item.cancellation_reason ? (
                              <span className="block text-xs mt-0.5" style={{ color: C.error }}>ملغى: {item.cancellation_reason}</span>
                            ) : null}
                          </td>
                          <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{personName(item.created_by)}</td>
                          <td className="px-3 py-2.5">
                            {cancelled ? (
                              <span className="text-xs" style={{ color: C.error }}>ملغى</span>
                            ) : (
                              <button type="button" onClick={() => setCancelTarget(item)} title="إلغاء" className="p-1.5 rounded-lg bg-gray-50">
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
          title="إلغاء سحب"
          description={'سيُلغى السحب بمبلغ ' + money(cancelTarget.amount) + ' ويُرجع المبلغ إلى رصيد الخزينة.'}
          busy={cancelBusy}
          onConfirm={(reason) => void confirmCancel(reason)}
          onClose={() => setCancelTarget(null)}
        />
      ) : null}
    </div>
  );
}

export default TreasuryWithdrawalsPage;
