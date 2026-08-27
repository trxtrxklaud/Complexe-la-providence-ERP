import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Ban, CheckCircle2, Printer, Search, Wallet } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import { CancelReasonModal } from '../../components/CancelReasonModal';
import {
  DEBT_STATUS_LABELS,
  DEBT_TYPE_LABELS,
  cancelOldDebtPayment,
  collectOldDebt,
  fetchManualDebts,
  fetchOldDebtPayments,
  fetchOldDebtStatement,
  type ManualDebt,
  type OldDebtPaymentRow,
} from '../../api/manualDebts';
import { errorMessage, money, personName } from '../../lib/format';
import { ListSkeleton } from '../../components/DataSkeleton';

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

function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { label: string; color: string; bg: string }> = {
    pending: { label: DEBT_STATUS_LABELS.pending, color: '#B45309', bg: '#FEF3C7' },
    partial: { label: DEBT_STATUS_LABELS.partial, color: '#1D4ED8', bg: '#DBEAFE' },
    paid: { label: DEBT_STATUS_LABELS.paid, color: '#15803D', bg: '#DCFCE7' },
    cancelled: { label: DEBT_STATUS_LABELS.cancelled, color: '#6B7280', bg: '#F3F4F6' },
  };
  const s = map[status] ?? { label: status, color: C.muted, bg: '#F3F4F6' };
  return (
    <span className="inline-block text-xs font-semibold px-3 py-1 rounded-full" style={{ color: s.color, backgroundColor: s.bg }}>
      {s.label}
    </span>
  );
}

export function OldDebtCollectPage() {
  const [params] = useSearchParams();

  const [allDebts, setAllDebts] = useState<ManualDebt[]>([]);
  const [loadingAll, setLoadingAll] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [filter, setFilter] = useState('');

  const [selectedDebtId, setSelectedDebtId] = useState<number | null>(null);
  const [payments, setPayments] = useState<OldDebtPaymentRow[]>([]);
  const [paymentsLoading, setPaymentsLoading] = useState(false);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('cash');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [cancelTarget, setCancelTarget] = useState<OldDebtPaymentRow | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 4000);
  };

  const loadAll = useCallback(async () => {
    setLoadingAll(true);
    setLoadError('');
    try {
      const page = await fetchManualDebts({ exclude_cancelled: true, per_page: 200 });
      setAllDebts(page.data);
    } catch (err) {
      setLoadError(errorMessage(err));
    } finally {
      setLoadingAll(false);
    }
  }, []);

  const loadPayments = useCallback(async (debtId: number) => {
    setPaymentsLoading(true);
    try {
      const res = await fetchOldDebtPayments(debtId);
      setPayments(res.payments ?? []);
    } catch {
      setPayments([]);
    } finally {
      setPaymentsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadAll();
    const sid = Number(params.get('student_id') || 0);
    if (sid) setFilter('');
  }, [loadAll, params]);

  useEffect(() => {
    if (selectedDebtId) void loadPayments(selectedDebtId);
    else setPayments([]);
  }, [selectedDebtId, loadPayments]);

  const selectedDebt = allDebts.find((d) => d.id === selectedDebtId) ?? null;
  const fullyPaid = selectedDebt ? Number(selectedDebt.outstanding_amount) <= 0 : false;

  const q = filter.trim().toLowerCase();
  const visible = q
    ? allDebts.filter((d) => {
        const name = personName(d.student).toLowerCase();
        const code = (d.student?.student_code ?? '').toLowerCase();
        const desc = (d.description ?? '').toLowerCase();
        return name.includes(q) || code.includes(q) || desc.includes(q);
      })
    : allDebts;

  const submitCollect = async () => {
    if (!selectedDebt) return;
    const v = Number(amount);
    if (!Number.isFinite(v) || v <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    if (v > Number(selectedDebt.outstanding_amount)) {
      setError(`المبلغ يتجاوز المتبقي (${money(selectedDebt.outstanding_amount)} د.ت)`);
      return;
    }
    setSaving(true);
    setError('');
    try {
      const res = await collectOldDebt(selectedDebt.id, { amount: v, method, notes: notes.trim() || null });
      flash(res.message || 'تم تحصيل الدين القديم بنجاح');
      setAmount('');
      setNotes('');
      await loadAll();
      await loadPayments(selectedDebt.id);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const confirmCancel = async (reason: string) => {
    if (!cancelTarget?.payment_id) return;
    setCancelBusy(true);
    setError('');
    try {
      await cancelOldDebtPayment(cancelTarget.payment_id, reason);
      setCancelTarget(null);
      flash('تم إلغاء الدفعة وإعادة الرصيد إلى الدين');
      await loadAll();
      if (selectedDebtId) await loadPayments(selectedDebtId);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setCancelBusy(false);
    }
  };

  const printStatement = async (debtId: number) => {
    try {
      const st = await fetchOldDebtStatement(debtId);
      const w = window.open('', '_blank', 'width=1000,height=800');
      if (!w) return;
      const esc = (v: unknown) => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
      const rowsHtml = st.payments
        .map(
          (p) =>
            `<tr><td>${esc(p.payment_date ?? '—')}</td><td>${esc(p.method ?? '—')}</td><td class="amount">${money(p.amount)} د.ت</td><td>${esc(
              p.status === 'cancelled' ? 'ملغاة' : 'سارية'
            )}</td></tr>`
        )
        .join('');
      w.document.write(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
        <title>كشف استخلاص متخلد قديم</title>
        <style>body{font-family:Tahoma,Arial;font-size:13px;color:#1F261C}h1{font-size:20px;color:#2E3B2A;margin:0 0 4px}
        .sub{color:#7C8677;font-size:12px;margin:0 0 14px}
        table{width:100%;border-collapse:collapse;margin-top:12px}
        th,td{border:1px solid #DDE4D8;padding:6px 10px;text-align:right}
        th{background:#F6F8F4}.amount{text-align:left;white-space:nowrap}
        .totals{margin-top:14px;background:#F6F8F4;padding:10px 14px;border:1px solid #DDE4D8;display:flex;justify-content:space-between}
        </style></head><body>
        <h1>كشف استخلاص متخلد قديم</h1>
        <p class="sub">مدرسة العناية — تاريخ الطباعة: ${new Date().toLocaleDateString('ar-TN')}</p>
        <p><b>الاسم:</b> ${esc(st.debt.student_name)} — <b>القسم:</b> ${esc(st.debt.section ?? '—')} — <b>المستوى:</b> ${esc(st.debt.level ?? '—')}</p>
        <p><b>الدين الأصلي</b> (${esc(st.debt.original_year_label)}) — ${esc(st.debt.description)}:
           <span class="amount">${money(st.debt.original_amount)} د.ت</span></p>
        <table><thead><tr><th>التاريخ</th><th>الطريقة</th><th>المبلغ</th><th>الحالة</th></tr></thead>
        <tbody>${rowsHtml || '<tr><td colspan="4">لا دفعات بعد</td></tr>'}</tbody></table>
        <div class="totals"><span>الإجمالي المدفوع: <b>${money(st.totals.paid_active)} د.ت</b></span>
        <span>الرصيد المتبقي: <b>${money(st.debt.outstanding_amount)} د.ت</b></span></div>
        </body></html>`);
      w.document.close();
      w.focus();
      w.print();
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  const fieldStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const fieldCls = 'w-full px-3 py-2.5 rounded-xl text-sm';

  const totals = {
    count: visible.length,
    outstanding: visible.reduce((s, d) => s + Number(d.outstanding_amount ?? 0), 0),
  };

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <PageShell title="استخلاص الديون القديمة" subtitle="تحصيل متخلّدات سنوات سابقة مسجَّلة يدوياً — دفعة جزئية أو كاملة، مع كشف قابل للطباعة" icon={Wallet}>
        <div>
          {error ? <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div> : null}
          {notice ? <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div> : null}

          {/* ===== البحث/الفلتر + الإجماليات ===== */}
          <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
            <div className="flex flex-wrap items-end justify-between gap-4">
              <div className="flex-1 min-w-[260px]">
                <label className="block text-sm font-semibold mb-2" style={{ color: C.deep }}>كل الديون القديمة — اختر دَيناً لتحصيله</label>
                <div className="relative">
                  <Search size={18} className="absolute right-3 top-1/2 -translate-y-1/2" style={{ color: C.muted }} />
                  <input
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    className={fieldCls + ' pr-10'}
                    style={{ ...fieldStyle, fontSize: '15px' }}
                    placeholder="فلترة بالاسم أو رقم التلميذ…"
                  />
                </div>
              </div>
              <div className="flex gap-3 text-center">
                <div className="rounded-2xl px-5 py-3" style={{ backgroundColor: C.sage }}>
                  <p className="text-[11px]" style={{ color: C.muted }}>عدد الديون</p>
                  <p className="text-xl font-extrabold" style={{ color: C.deep }}>{totals.count}</p>
                </div>
                <div className="rounded-2xl px-5 py-3" style={{ backgroundColor: '#FFFBEB' }}>
                  <p className="text-[11px]" style={{ color: C.muted }}>إجمالي المتبقّي (د.ت)</p>
                  <p className="text-xl font-extrabold" style={{ color: '#B45309', direction: 'ltr' }}>{money(totals.outstanding)}</p>
                </div>
              </div>
            </div>
          </div>

          {/* ===== قائمة كل الديون ===== */}
          {loadingAll ? (
            <ListSkeleton rows={6} />
          ) : loadError ? (
            <div className="rounded-2xl px-6 py-10 text-center text-sm mb-6" style={{ backgroundColor: C.errorBg, color: C.error }}>
              {loadError}
            </div>
          ) : visible.length === 0 ? (
            <div className="bg-white rounded-2xl px-6 py-12 text-center mb-6" style={{ border: '1px solid ' + C.line }}>
              <CheckCircle2 size={44} className="mx-auto mb-3" style={{ color: '#15803D' }} />
              <p className="font-bold mb-1" style={{ color: C.ink }}>
                {q ? 'لا نتائج مطابقة للفلتر' : 'لا ديون قديمة مسجّلة حالياً'}
              </p>
              <p className="text-sm" style={{ color: C.muted }}>
                {q ? 'جرّب تعديل الفلتر.' : 'تظهر هنا فور إدخالها من تبويب الإدخال الجماعي أو الفردي.'}
              </p>
            </div>
          ) : (
            <div className="bg-white rounded-2xl overflow-hidden mb-6" style={{ border: '1px solid ' + C.line }}>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ backgroundColor: C.sage, color: C.deep }}>
                      <th className="text-right px-4 py-3 font-semibold">التلميذ</th>
                      <th className="text-right px-4 py-3 font-semibold">النوع</th>
                      <th className="text-right px-4 py-3 font-semibold">الوصف</th>
                      <th className="text-right px-4 py-3 font-semibold">السنة الأصلية</th>
                      <th className="text-right px-4 py-3 font-semibold">الأصلي</th>
                      <th className="text-right px-4 py-3 font-semibold">المحصّل</th>
                      <th className="text-right px-4 py-3 font-semibold">المتبقّي</th>
                      <th className="text-right px-4 py-3 font-semibold">الحالة</th>
                      <th className="px-4 py-3" style={{ width: '11rem' }} />
                    </tr>
                  </thead>
                  <tbody>
                    {visible.map((d) => {
                      const isSel = d.id === selectedDebtId;
                      return (
                        <tr
                          key={d.id}
                          style={{
                            borderBottom: '1px solid ' + C.line,
                            backgroundColor: isSel ? '#F8FAF7' : 'transparent',
                          }}
                        >
                          <td className="px-4 py-3 font-semibold" style={{ color: C.ink }}>{personName(d.student)}</td>
                          <td className="px-4 py-3" style={{ color: C.muted }}>{DEBT_TYPE_LABELS[d.debt_type] ?? d.debt_type}</td>
                          <td className="px-4 py-3" style={{ color: C.ink }}>{d.description}</td>
                          <td className="px-4 py-3 text-xs" style={{ color: C.muted }}>{d.original_year_label}</td>
                          <td className="px-4 py-3" style={{ direction: 'ltr', textAlign: 'right' }}>{money(d.original_amount)}</td>
                          <td className="px-4 py-3" style={{ color: '#15803D', direction: 'ltr', textAlign: 'right' }}>{money(d.collected_amount)}</td>
                          <td className="px-4 py-3 font-bold" style={{ color: '#B45309', direction: 'ltr', textAlign: 'right' }}>{money(d.outstanding_amount)}</td>
                          <td className="px-4 py-3"><StatusBadge status={d.status} /></td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-1.5">
                              <button
                                type="button"
                                onClick={() => setSelectedDebtId(isSel ? null : d.id)}
                                className="px-3 py-1.5 rounded-lg text-xs font-semibold text-white"
                                style={{ backgroundColor: isSel ? C.muted : C.forest }}
                              >
                                {isSel ? 'محدَّد ✓' : 'تحصيل'}
                              </button>
                              <button type="button" onClick={() => void printStatement(d.id)} title="طباعة الكشف" className="p-1.5 rounded-lg bg-gray-50 hover:bg-gray-100">
                                <Printer size={14} color={C.deep} />
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* ===== نموذج التحصيل للدين المحدَّد ===== */}
          {selectedDebt ? (
            Number(selectedDebt.outstanding_amount) <= 0 ? (
              <div className="rounded-2xl p-8 mb-6 text-center" style={{ border: '2px solid #15803D', backgroundColor: '#F0FDF4' }}>
                <CheckCircle2 size={44} className="mx-auto mb-2" style={{ color: '#15803D' }} />
                <p className="text-xl font-bold" style={{ color: '#15803D' }}>
                  مسدَّد بالكامل — {personName(selectedDebt.student)}
                </p>
                <p className="text-sm mt-1" style={{ color: C.muted }}>لا يمكن تحصيل مبلغ إضافي على هذا الدَّين.</p>
              </div>
            ) : (
              <div className="bg-white rounded-2xl p-6 mb-6" style={{ border: '2px solid ' + C.forest }}>
                <h3 className="font-bold text-lg mb-4" style={{ color: C.deep }}>
                  تحصيل دفعة — {personName(selectedDebt.student)}
                </h3>

                <div className="grid grid-cols-3 gap-3 mb-5">
                  <div className="rounded-2xl p-4 text-center" style={{ backgroundColor: C.sage }}>
                    <p className="text-xs mb-1" style={{ color: C.muted }}>الدين الأصلي</p>
                    <p className="text-xl font-extrabold" style={{ color: C.ink, direction: 'ltr' }}>{money(selectedDebt.original_amount)}</p>
                    <p className="text-[11px]" style={{ color: C.muted }}>د.ت</p>
                  </div>
                  <div className="rounded-2xl p-4 text-center" style={{ backgroundColor: '#F0FDF4' }}>
                    <p className="text-xs mb-1" style={{ color: C.muted }}>المدفوع سابقاً</p>
                    <p className="text-xl font-extrabold" style={{ color: '#15803D', direction: 'ltr' }}>{money(selectedDebt.collected_amount)}</p>
                    <p className="text-[11px]" style={{ color: C.muted }}>د.ت</p>
                  </div>
                  <div className="rounded-2xl p-4 text-center" style={{ backgroundColor: '#FFFBEB' }}>
                    <p className="text-xs mb-1" style={{ color: C.muted }}>المتبقّي</p>
                    <p className="text-xl font-extrabold" style={{ color: '#B45309', direction: 'ltr' }}>{money(selectedDebt.outstanding_amount)}</p>
                    <p className="text-[11px]" style={{ color: C.muted }}>د.ت</p>
                  </div>
                </div>

                <div className="grid sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>مبلغ التحصيل (د.ت) *</label>
                    <input
                      value={amount}
                      onChange={(e) => setAmount(e.target.value)}
                      type="number"
                      min="0"
                      max={Number(selectedDebt.outstanding_amount)}
                      step="0.01"
                      className={fieldCls}
                      style={{ ...fieldStyle, direction: 'ltr', fontWeight: 600 }}
                      placeholder="0.00"
                    />
                    <p className="text-[11px] mt-1" style={{ color: C.muted }}>
                      الحد الأقصى: {money(selectedDebt.outstanding_amount)} د.ت
                    </p>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>طريقة الدفع</label>
                    <select value={method} onChange={(e) => setMethod(e.target.value)} className={fieldCls} style={fieldStyle}>
                      <option value="cash">نقداً</option>
                      <option value="bank_transfer">تحويل بنكي</option>
                      <option value="check">صك</option>
                      <option value="card">بطاقة</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظات</label>
                    <input value={notes} onChange={(e) => setNotes(e.target.value)} className={fieldCls} style={fieldStyle} />
                  </div>
                </div>

                <div className="flex gap-3 mt-5 flex-wrap items-center">
                  <button
                    type="button"
                    onClick={() => void submitCollect()}
                    disabled={saving}
                    className="inline-flex items-center gap-2 px-8 py-3 rounded-xl text-white text-base font-bold disabled:opacity-50 shadow-lg"
                    style={{ backgroundColor: C.forest }}
                  >
                    <Wallet size={20} />
                    <span>{saving ? 'جارٍ التحصيل…' : 'تحصيل الدفعة'}</span>
                  </button>
                  <button type="button" onClick={() => void printStatement(selectedDebt.id)} className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-medium" style={{ backgroundColor: C.deep }}>
                    <Printer size={18} />
                    <span>طباعة الكشف</span>
                  </button>
                  <span className="text-xs" style={{ color: C.muted }}>
                    يُسجَّل التحصيل في متخلّدات سنوات سابقة — لا يُضاف لأقساط السنة الحالية.
                  </span>
                </div>
              </div>
            )
          ) : null}

          {/* ===== سجل دفعات الدين المحدَّد ===== */}
          {selectedDebt ? (
            <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
              <div className="px-5 py-4" style={{ backgroundColor: C.sage }}>
                <h3 className="font-bold" style={{ color: C.deep }}>سجل الدفعات — {personName(selectedDebt.student)}</h3>
              </div>
              {paymentsLoading ? (
                <ListSkeleton rows={3} />
              ) : payments.length === 0 ? (
                <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا دفعات مسجّلة على هذا الدَّين بعد.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                        <th className="text-right px-3 py-3 font-medium">التاريخ</th>
                        <th className="text-right px-3 py-3 font-medium">المبلغ</th>
                        <th className="text-right px-3 py-3 font-medium">الطريقة</th>
                        <th className="text-right px-3 py-3 font-medium">الحالة</th>
                        <th className="no-print px-3 py-3" style={{ width: '5rem' }} />
                      </tr>
                    </thead>
                    <tbody>
                      {payments.map((p) => (
                        <tr key={p.allocation_id} style={{ borderBottom: '1px solid ' + C.line, opacity: p.status === 'cancelled' ? 0.55 : 1 }}>
                          <td className="px-3 py-2.5 text-xs" style={{ direction: 'ltr', textAlign: 'right' }}>{p.payment_date ?? '—'}</td>
                          <td className="px-3 py-2.5 font-medium" style={{ direction: 'ltr', textAlign: 'right', textDecoration: p.status === 'cancelled' ? 'line-through' : 'none' }}>
                            {money(p.amount)} <span className="text-[11px]" style={{ color: C.muted }}>د.ت</span>
                          </td>
                          <td className="px-3 py-2.5" style={{ color: C.muted }}>
                            {p.method === 'cash' ? 'نقداً' : p.method === 'bank_transfer' ? 'تحويل بنكي' : p.method === 'check' ? 'صك' : p.method === 'card' ? 'بطاقة' : p.method ?? '—'}
                          </td>
                          <td className="px-3 py-2.5" style={{ color: p.status === 'cancelled' ? C.error : '#15803D' }}>
                            {p.status === 'cancelled' ? `ملغاة — ${p.cancellation_reason ?? ''}` : 'سارية'}
                          </td>
                          <td className="no-print px-3 py-2.5">
                            {p.status === 'active' ? (
                              <button type="button" onClick={() => setCancelTarget(p)} title="إلغاء الدفعة" className="p-1.5 rounded-lg bg-gray-50 hover:bg-gray-100">
                                <Ban size={14} color={C.error} />
                              </button>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          ) : null}
        </div>
      </PageShell>

      {cancelTarget ? (
        <CancelReasonModal
          title="إلغاء دفعة دين قديم"
          description={`سيُلغى وصل بمبلغ ${money(cancelTarget.amount)} د.ت ويُعاد الرصيد إلى الدين. السجل يبقى محفوظاً.`}
          busy={cancelBusy}
          onConfirm={(reason) => void confirmCancel(reason)}
          onClose={() => setCancelTarget(null)}
        />
      ) : null}
    </div>
  );
}

export default OldDebtCollectPage;
