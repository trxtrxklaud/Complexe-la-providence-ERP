import React, { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { CreditCard, Loader2, AlertCircle } from 'lucide-react';
import { getFeeTypes } from '../../api/feeTypes';
import {
  collectPayment,
  getEnrollmentLedger,
  getCollectionYears,
  getSectionsByYear,
  getStudentsBySection,
  paymentsApi,
} from '../../api/payments';
import { ReceiptModal } from './ReceiptModal';
import { getToken } from '../../api/http';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

const METHOD_LABELS: Record<string, string> = {
  cash: 'نقداً',
  bank_transfer: 'تحويل بنكي',
  check: 'شيك',
  card: 'بطاقة',
};

const MONTH_AR: Record<string, string> = {
  '01': 'جانفي', '02': 'فيفري', '03': 'مارس', '04': 'أفريل',
  '05': 'ماي', '06': 'جوان', '07': 'جويلية', '08': 'أوت',
  '09': 'سبتمبر', '10': 'أكتوبر', '11': 'نوفمبر', '12': 'ديسمبر',
};

function monthLabel(m: string) {
  const p = m.split('-');
  return (MONTH_AR[p[1]] || p[1]) + ' ' + p[0];
}


function userCode(u?: any) {
  if (!u) return '—';
  if (u.code) return String(u.code);
  const a = (u.first_name || '').trim();
  const b = (u.last_name || '').trim();
  if (a && b) return (a[0] + '.' + b[0]).toUpperCase();
  return String(u.username || '—').slice(0, 2).toUpperCase();
}

export function CollectionPage() {
  const { user } = useAuth();
  const [years, setYears] = useState<any[]>([]);
  const [sections, setSections] = useState<any[]>([]);
  const [students, setStudents] = useState<any[]>([]);
  const [feeTypes, setFeeTypes] = useState<any[]>([]);

  const [yearId, setYearId] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [picked, setPicked] = useState<any>(null);

  const [yearMonths, setYearMonths] = useState<string[]>([]);
  const [paidMonths, setPaidMonths] = useState<string[]>([]);
  const [selectedMonths, setSelectedMonths] = useState<string[]>([]);

  const [feeAmounts, setFeeAmounts] = useState<Record<number, string>>({});
  const [selectedFees, setSelectedFees] = useState<Record<number, boolean>>({});
  const [monthlyPrice, setMonthlyPrice] = useState('0');
  const [discount, setDiscount] = useState('0');
  const [method, setMethod] = useState<'cash' | 'bank_transfer' | 'check' | 'card'>('cash');
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [receipt, setReceipt] = useState<any>(null);
  const [ledgerRows, setLedgerRows] = useState<any[]>([]);

  useEffect(() => {
    Promise.all([getCollectionYears(), getFeeTypes()])
      .then(([y, f]) => {
        setYears(Array.isArray(y) ? y : []);
        const fees = Array.isArray(f) ? f : [];
        setFeeTypes(fees.filter((x: any) => x.is_active !== false));
        const am: Record<number, string> = {};
        fees.forEach((x: any) => { am[x.id] = String(x.price ?? 0); });
        setFeeAmounts(am);
      })
      .catch((e) => setError(e.message || 'فشل التحميل'));
  }, []);

  async function onYearChange(id: string) {
    setYearId(id);
    setSectionId('');
    setSections([]);
    setStudents([]);
    setPicked(null);
    setSelectedMonths([]);
    if (!id) return;
    try {
      const s = await getSectionsByYear(Number(id));
      setSections(Array.isArray(s) ? s : []);
    } catch (e: any) {
      setError(e.message || 'فشل جلب الأقسام');
    }
  }

  async function onSectionChange(id: string) {
    setSectionId(id);
    setStudents([]);
    setPicked(null);
    setSelectedMonths([]);
    if (!id || !yearId) return;
    try {
      const list = await getStudentsBySection(Number(id), Number(yearId));
      setStudents(Array.isArray(list) ? list : []);
    } catch (e: any) {
      setError(e.message || 'فشل جلب التلاميذ');
    }
  }

  async function onStudentChange(enrollmentId: string) {
    setSelectedMonths([]);
    setReceipt(null);
    const row = students.find((x) => String(x.enrollment_id) === enrollmentId);
    setPicked(row || null);
    if (!row) return;
    setLoading(true);
    setError('');
    try {
      const ledger: any = await getEnrollmentLedger(row.enrollment_id);
      setYearMonths(ledger.year_months || []);
      setPaidMonths(ledger.paid_months || []);
      const rows: any[] = [];
      const bag = ledger.ledger || {};
      Object.keys(bag).sort().forEach((month) => {
        (bag[month] || []).forEach((x: any) => {
          rows.push({ month, ...x });
        });
      });
      // unique by payment_id
      const seen = new Set();
      const uniq = [];
      for (const r of rows) {
        if (seen.has(r.payment_id)) continue;
        seen.add(r.payment_id);
        uniq.push(r);
      }
      setLedgerRows(uniq.sort((a,b)=>String(b.payment_date).localeCompare(String(a.payment_date))));
    } catch (e: any) {
      setError(e.message || 'فشل جلب الأشهر');
    } finally {
      setLoading(false);
    }
  }

  function toggleMonth(m: string) {
    if (paidMonths.includes(m)) return;
    const firstUnpaid = yearMonths.find((x) => !paidMonths.includes(x));
    if (!firstUnpaid) return;
    setSelectedMonths((prev) => {
      const exists = prev.includes(m);
      let next = exists ? prev.filter((x) => x !== m) : [...prev, m];
      next = next.filter((x) => !paidMonths.includes(x)).sort();
      const startIdx = yearMonths.indexOf(firstUnpaid);
      const idxs = next.map((x) => yearMonths.indexOf(x)).filter((i) => i >= 0).sort((a, b) => a - b);
      if (!idxs.length) return [];
      if (idxs[0] !== startIdx) {
        setError('يجب البدء من أول شهر غير مدفوع: ' + monthLabel(firstUnpaid));
        return prev;
      }
      const consecutive = [idxs[0]];
      for (let i = 1; i < idxs.length; i++) {
        if (idxs[i] === consecutive[consecutive.length - 1] + 1) consecutive.push(idxs[i]);
        else break;
      }
      setError('');
      return consecutive.map((i) => yearMonths[i]);
    });
  }

  const productsTotal = useMemo(() => {
    return Object.entries(selectedFees).reduce((sum, [id, on]) => {
      if (!on) return sum;
      return sum + (parseFloat(feeAmounts[Number(id)] || '0') || 0);
    }, 0);
  }, [selectedFees, feeAmounts]);

  const monthsTotal = selectedMonths.length * (parseFloat(monthlyPrice || '0') || 0);
  const itemsTotal = productsTotal + monthsTotal;
  const total = Math.max(0, itemsTotal - (parseFloat(discount || '0') || 0));

  async function handleSave() {
    if (!picked) return;
    if (!selectedMonths.length) { setError('اختر شهراً'); return; }
    const items = Object.entries(selectedFees)
      .filter(([, on]) => on)
      .map(([id]) => ({ fee_type_id: Number(id), amount: parseFloat(feeAmounts[Number(id)] || '0') }))
      .filter((x) => x.amount > 0);
    const mp = parseFloat(monthlyPrice || '0') || 0;
    if (mp > 0) {
      const tuition = feeTypes.find((f: any) => f.name_ar === 'القسط الشهري') || feeTypes[0];
      if (!tuition) { setError('لا يوجد نوع معلوم للقسط الشهري'); return; }
      items.unshift({ fee_type_id: Number(tuition.id), amount: mp * selectedMonths.length });
    }
    if (!items.length) { setError('أدخل معلوم الشهر أو اختر معلوماً'); return; }

    setSaving(true);
    setError('');
    try {
      const res: any = await collectPayment({
        student_id: picked.student.id,
        enrollment_id: picked.enrollment_id,
        months: selectedMonths,
        payment_date: paymentDate,
        method,
        reference: reference || null,
        notes: notes || null,
        discount: parseFloat(discount || '0') || 0,
        items,
      } as any);
      const raw = res.receipt || res;
      const student = raw.student || {};
      const guardian = raw.guardian || raw.primary_guardian || {};
      const section = raw.section || {};
      const level = raw.level || {};
      setReceipt({
        ...raw,
        student_name: raw.student_name
          || [student.first_name, student.last_name].filter(Boolean).join(' ')
          || [picked?.student?.first_name, picked?.student?.last_name].filter(Boolean).join(' ')
          || '—',
        section_name: raw.section_name
          || [level.name, section.name].filter(Boolean).join(' - ')
          || (document.querySelector('select') ? '' : '')
          || (sections.find((s: any) => String(s.id) === String(sectionId))
            ? ((sections.find((s: any) => String(s.id) === String(sectionId))?.level?.name || '') + ' ' + (sections.find((s: any) => String(s.id) === String(sectionId))?.name || '')).trim()
            : '—'),
        guardian_name: raw.guardian_name
          || [guardian.first_name, guardian.last_name].filter(Boolean).join(' ')
          || [picked?.guardian?.first_name, picked?.guardian?.last_name].filter(Boolean).join(' ')
          || '—',
        months_label: raw.months_label || raw.months || selectedMonths,
        items: (raw.items || raw.allocations || []).map((it: any) => ({
          ...it,
          description: it.description || it.name || it.fee_type_name || it.name_ar
            || it.student_fee?.description || it.feeType?.name_ar || 'بند',
          amount: it.amount ?? it.amount_allocated ?? 0,
        })),
        total: raw.total ?? raw.amount,
        amount: raw.amount ?? raw.total,
        discount: raw.discount ?? 0,
        method_label: raw.method_label || raw.method,
        payment_id: raw.payment_id || raw.id,
        payment_date: raw.payment_date || raw.paid_at,
        user_name: [user?.first_name, user?.last_name].filter(Boolean).join(' ') || '—',
      });

      const ledger: any = await getEnrollmentLedger(picked.enrollment_id);
      setYearMonths(ledger.year_months || []);
      setPaidMonths(ledger.paid_months || []);
      const rows: any[] = [];
      const bag = ledger.ledger || {};
      Object.keys(bag).sort().forEach((month) => {
        (bag[month] || []).forEach((x: any) => rows.push({ month, ...x }));
      });
      const seen = new Set();
      const uniq: any[] = [];
      for (const r of rows) {
        if (seen.has(r.payment_id)) continue;
        seen.add(r.payment_id);
        uniq.push(r);
      }
      setLedgerRows(uniq.sort((a,b)=>String(b.payment_date).localeCompare(String(a.payment_date))));
      setSelectedMonths([]);
      setSelectedFees({});
      setDiscount('0');
    } catch (e: any) {
      setError(e.message || 'فشل الحفظ');
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!receipt?.payment_id) return;
    if (!confirm('حذف كلي لهذه الدفعة من النظام؟')) return;
    try {
      await paymentsApi.destroy(receipt.payment_id);
      setReceipt(null);
      if (picked) {
        const ledger: any = await getEnrollmentLedger(picked.enrollment_id);
        setYearMonths(ledger.year_months || []);
        setPaidMonths(ledger.paid_months || []);
      }
    } catch (e: any) {
      setError(e.message || 'فشل الحذف');
    }
  }

  return (

<div className="p-6 md:p-8" dir="rtl" style={{ background: '#F4F6F1', minHeight: '100vh' }}>
      <style>{`
@media print {
  html, body { height: 100% !important; overflow: hidden !important; }
  body * { visibility: hidden !important; }
  #receipt-print, #receipt-print * { visibility: visible !important; }
  #receipt-print {
    position: fixed !important;
    inset: 0 !important;
    width: 210mm !important;
    height: 297mm !important;
    margin: 0 !important;
    padding: 8mm !important;
    background: #fff !important;
    overflow: hidden !important;
    box-shadow: none !important;
    border-radius: 0 !important;
  }
  .no-print, .no-print * { display: none !important; visibility: hidden !important; }
  @page { size: A4 portrait; margin: 0; }
}
`}</style>
    
      <div className="max-w-4xl mx-auto space-y-4">
        <div className="flex items-center gap-3 mb-2">
          <div className="w-12 h-12 rounded-2xl flex items-center justify-center" style={{ background: C.sage, color: C.forest }}>
            <CreditCard size={22} />
          </div>
          <div>
            <h1 className="text-2xl font-bold" style={{ color: C.ink }}>استخلاص الرسوم</h1>
            <p className="text-sm" style={{ color: C.muted }}>سنة ← قسم ← تلميذ ← أشهر ← معاليم ← تخفيض ← حفظ</p>
          </div>
        </div>

        {error && (
          <div className="p-3 rounded-xl text-sm flex gap-2 items-center" style={{ background: '#FEE2E2', color: '#B91C1C' }}>
            <AlertCircle size={16} /> {error}
          </div>
        )}

        <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3" style={{ borderColor: C.line }}>
          <div>
            <label className="text-sm font-semibold" style={{ color: C.ink }}>السنة الدراسية</label>
            <select value={yearId} onChange={(e) => onYearChange(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
              <option value="">اختر السنة</option>
              {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-sm font-semibold" style={{ color: C.ink }}>القسم</label>
            <select value={sectionId} onChange={(e) => onSectionChange(e.target.value)} disabled={!yearId} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
              <option value="">اختر القسم</option>
              {sections.map((s) => (
                <option key={s.id} value={s.id}>{(s.level?.name ? s.level.name + ' - ' : '') + s.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-sm font-semibold" style={{ color: C.ink }}>التلميذ</label>
            <select value={picked?.enrollment_id || ''} onChange={(e) => onStudentChange(e.target.value)} disabled={!sectionId} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
              <option value="">اختر التلميذ</option>
              {students.map((row) => (
                <option key={row.enrollment_id} value={row.enrollment_id}>
                  {row.student.first_name} {row.student.last_name} ({row.student.student_code})
                </option>
              ))}
            </select>
          </div>
        </div>

        {picked && (
          <>
            <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
              <label className="text-sm font-semibold" style={{ color: C.ink }}>معلوم الشهر (د.ت)</label>
              <input
                type="number"
                min="0"
                step="0.01"
                value={monthlyPrice}
                onChange={(e) => setMonthlyPrice(e.target.value)}
                className="w-full mt-1 border rounded-xl px-3 py-2 text-sm"
                style={{ borderColor: C.line, direction: 'ltr' }}
                placeholder="مثال: 150"
              />
              <p className="text-xs mt-1" style={{ color: C.muted }}>
                المجموع الشهري = عدد الأشهر × معلوم الشهر
              </p>
            </div>

            <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
              <div className="font-semibold mb-2" style={{ color: C.ink }}>الأشهر</div>
              {loading ? <Loader2 className="animate-spin" /> : (
                <div className="flex flex-wrap gap-2">
                  {yearMonths.map((m) => {
                    const paid = paidMonths.includes(m);
                    const active = selectedMonths.includes(m);
                    return (
                      <button key={m} type="button" disabled={paid} onClick={() => toggleMonth(m)}
                        className="px-3 py-2 rounded-xl text-xs border"
                        style={{
                          background: paid ? '#DCFCE7' : active ? C.forest : 'white',
                          color: paid ? '#15803D' : active ? 'white' : C.ink,
                          borderColor: C.line,
                        }}>
                        {monthLabel(m)}{paid ? ' ✓' : ''}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
              <div className="font-semibold mb-2" style={{ color: C.ink }}>المعاليم / النوادي</div>
              <div className="space-y-2">
                {feeTypes.map((f) => (
                  <label key={f.id} className="flex items-center gap-3 p-2 rounded-xl border" style={{ borderColor: C.line }}>
                    <input type="checkbox" checked={!!selectedFees[f.id]} onChange={(e) => setSelectedFees((p) => ({ ...p, [f.id]: e.target.checked }))} />
                    <span className="flex-1 text-sm" style={{ color: C.ink }}>{f.name_ar}</span>
                    <input type="number" min="0" step="0.01" value={feeAmounts[f.id] || ''} onChange={(e) => setFeeAmounts((p) => ({ ...p, [f.id]: e.target.value }))}
                      className="w-24 border rounded-lg px-2 py-1 text-sm" style={{ borderColor: C.line, direction: 'ltr' }} />
                  </label>
                ))}
              </div>
            </div>

            <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-2 gap-3" style={{ borderColor: C.line }}>
              <div>
                <label className="text-sm font-semibold" style={{ color: C.ink }}>التخفيض</label>
                <input type="number" min="0" step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)}
                  className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line, direction: 'ltr' }} />
              </div>
              <div>
                <label className="text-sm font-semibold" style={{ color: C.ink }}>التاريخ</label>
                <input type="date" value={paymentDate} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }} />
              </div>
              <div className="md:col-span-2">
                <div className="text-sm font-semibold mb-2" style={{ color: C.ink }}>طريقة الدفع</div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                  {Object.entries(METHOD_LABELS).map(([k, label]) => (
                    <button key={k} type="button" onClick={() => setMethod(k as any)} className="py-2 rounded-xl text-sm border"
                      style={{ background: method === k ? C.forest : 'white', color: method === k ? 'white' : C.ink, borderColor: C.line }}>
                      {label}
                    </button>
                  ))}
                </div>
              </div>
              <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="مرجع / شيك"
                className="border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }} />
              <input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="ملاحظات"
                className="border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }} />
            </div>

            {ledgerRows.length > 0 && (
              <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
                <div className="font-semibold mb-3" style={{ color: C.ink }}>سجل المدفوعات (مسجّل في النظام)</div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr style={{ color: C.muted, textAlign: 'right' }}>
                        <th className="py-2">#</th>
                        <th>التاريخ</th>
                        <th>الأشهر</th>
                        <th>المبلغ</th>
                        <th>الطريقة</th>
                        <th>المستخدم</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {ledgerRows.map((r) => (
                        <tr key={r.payment_id} className="border-t" style={{ borderColor: C.line }}>
                          <td className="py-2">{r.payment_id}</td>
                          <td>{r.payment_date}</td>
                          <td>{monthLabel(r.month)}</td>
                          <td style={{ color: C.forest, fontWeight: 700 }}>{Number(r.amount).toFixed(2)} د.ت</td>
                          <td>{METHOD_LABELS[r.method] || r.method}</td>
                          <td>{r.created_by || r.user_name || '—'}</td>
                          <td>
                            <button
                              type="button"
                              className="text-xs px-2 py-1 rounded-lg text-white"
                              style={{ background: '#DC2626' }}
                              onClick={async () => {
                                if (!confirm('حذف هذه الدفعة نهائيًا من المداخيل والنظام؟')) return;
                                try {
                                  const token = getToken() || '';
                                  const res = await fetch('/api/payments/' + r.payment_id, {
                                    method: 'DELETE',
                                    headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
                                  });
                                  if (!res.ok) throw new Error('فشل الحذف');
                                  // refresh ledger
                                  if (picked) {
                                    const ledger: any = await getEnrollmentLedger(picked.enrollment_id);
                                    setPaidMonths(ledger.paid_months || []);
                                    setYearMonths(ledger.year_months || []);
                                    const rows: any[] = [];
                                    const bag = ledger.ledger || {};
                                    Object.keys(bag).forEach((month) => {
                                      (bag[month] || []).forEach((x: any) => rows.push({ month, ...x }));
                                    });
                                    const seen = new Set();
                                    const uniq: any[] = [];
                                    for (const x of rows) {
                                      if (seen.has(x.payment_id)) continue;
                                      seen.add(x.payment_id);
                                      uniq.push(x);
                                    }
                                    setLedgerRows(uniq);
                                  }
                                  alert('تم الحذف من النظام');
                                } catch (e: any) {
                                  alert(e.message || 'خطأ في الحذف');
                                }
                              }}
                            >حذف من النظام</button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            <div className="bg-white rounded-2xl border p-4 flex items-center justify-between" style={{ borderColor: C.line }}>
              <div>
                <div className="text-xs" style={{ color: C.muted }}>المجموع بعد التخفيض</div>
                <div className="text-2xl font-extrabold" style={{ color: C.forest }}>{total.toFixed(2)} د.ت</div>
              </div>
              <button type="button" onClick={handleSave} disabled={saving || total <= 0}
                className="px-6 py-3 rounded-2xl text-white font-bold"
                style={{ background: saving || total <= 0 ? C.muted : C.forest }}>
                {saving ? 'جاري الحفظ...' : 'حفظ'}
              </button>
            </div>
          </>
        )}
      </div>

      {receipt && (
        <ReceiptModal
          receipt={receipt}
          cashierName={[user?.first_name, user?.last_name].filter(Boolean).join(' ')}
          onClose={() => setReceipt(null)}
          onDelete={handleDelete}
        />
      )}

    </div>
  );
}
