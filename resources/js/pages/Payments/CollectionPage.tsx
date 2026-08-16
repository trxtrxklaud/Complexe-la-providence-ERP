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
  getCollectionPreview,
  getStudentOpeningBalances,
  type CollectionPreview,
  paymentsApi,
} from '../../api/payments';

import { ReceiptModal } from './ReceiptModal';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

const METHOD_LABELS: Record<string, string> = {
  cash: '?????',
  bank_transfer: '????? ????',
  check: '???',
  card: '?????',
};

const TUITION_AR = '????? ??????';

const MONTH_AR: Record<string, string> = {
  '01': '?????', '02': '?????', '03': '????', '04': '?????',
  '05': '???', '06': '????', '07': '??????', '08': '???',
  '09': '??????', '10': '??????', '11': '??????', '12': '??????',
};

function monthLabel(m: string) {
  const p = m.split('-');
  return (MONTH_AR[p[1]] || p[1]) + ' ' + p[0];
}


function userCode(u?: any) {
  if (!u) return '?';
  if (u.code) return String(u.code);
  const a = (u.first_name || '').trim();
  const b = (u.last_name || '').trim();
  if (a && b) return (a[0] + '.' + b[0]).toUpperCase();
  return String(u.username || '?').slice(0, 2).toUpperCase();
}

export function CollectionPage() {
  const { user } = useAuth();
  const [years, setYears] = useState<any[]>([]);
  const [sections, setSections] = useState<any[]>([]);
  const [students, setStudents] = useState<any[]>([]);
  const [feeTypes, setFeeTypes] = useState<any[]>([]);
  const [tuitionFee, setTuitionFee] = useState<any>(null);

  const [yearId, setYearId] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [picked, setPicked] = useState<any>(null);

  const [yearMonths, setYearMonths] = useState<string[]>([]);
  const [paidMonths, setPaidMonths] = useState<string[]>([]);
  const [selectedMonths, setSelectedMonths] = useState<string[]>([]);

  const [feeAmounts, setFeeAmounts] = useState<Record<number, string>>({});
  const [selectedFees, setSelectedFees] = useState<Record<number, boolean>>({});
  const [selectedClubFees, setSelectedClubFees] = useState<Record<number, boolean>>({});
  const [clubAmounts, setClubAmounts] = useState<Record<number, string>>({});
  const [monthlyPrice, setMonthlyPrice] = useState('0');
  const [method, setMethod] = useState<'cash' | 'bank_transfer' | 'check' | 'card'>('cash');
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [receipt, setReceipt] = useState<any>(null);
  const [ledgerRows, setLedgerRows] = useState<any[]>([]);

  const [previewData, setPreviewData] = useState<CollectionPreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  // ???????? ??????? ??????? (???? ???????) ? ?????? ????? ????? ?? ?????? ?????.
  const [priorItems, setPriorItems] = useState<any[]>([]);
  const [priorSelections, setPriorSelections] = useState<Record<number, boolean>>({});
  const [priorAmounts, setPriorAmounts] = useState<Record<number, string>>({});


  useEffect(() => {
    if (!picked || selectedMonths.length === 0) {
      setPreviewData(null);
      return;
    }
    setPreviewLoading(true);
    getCollectionPreview(picked.enrollment_id, selectedMonths, tuitionFee?.id)
      .then((res) => {
        setPreviewData(res);
        const clubDefaults: Record<number, string> = {};
        (res.club_items || []).forEach((item: any) => {
          clubDefaults[item.club_monthly_fee_id] = String(item.remaining_amount ?? 0);
        });
        setClubAmounts(clubDefaults);
        setSelectedClubFees({});
        if (res.items && res.items.length > 0) {
          setMonthlyPrice(String(res.remaining_amount));
        }
      })
      .catch((e) => {
        setError(e.message || '??? ??? ???????? ????????');
      })
      .finally(() => setPreviewLoading(false));
  }, [picked, selectedMonths, tuitionFee]);

  useEffect(() => {
    Promise.all([getCollectionYears(), getFeeTypes()])

      .then(([y, f]) => {
        setYears(Array.isArray(y) ? y : []);
        const fees = Array.isArray(f) ? f : [];
        const active = fees.filter((x: any) => x.is_active !== false);
        const tuition = active.find((x: any) => x.name_ar === TUITION_AR) || null;
        setTuitionFee(tuition);
        setFeeTypes(active.filter((x: any) => !tuition || x.id !== tuition.id));
        const am: Record<number, string> = {};
        fees.forEach((x: any) => { am[x.id] = String(x.price ?? 0); });
        setFeeAmounts(am);
      })
      .catch((e) => setError(e.message || '??? ???????'));

    const handleClubFeeUpdated = () => {
      getFeeTypes().then((f) => {
        const fees = Array.isArray(f) ? f : [];
        const active = fees.filter((x: any) => x.is_active !== false);
        const tuition = active.find((x: any) => x.name_ar === TUITION_AR) || null;
        setTuitionFee(tuition);
        setFeeTypes(active.filter((x: any) => !tuition || x.id !== tuition.id));
        const am: Record<number, string> = {};
        fees.forEach((x: any) => { am[x.id] = String(x.price ?? 0); });
        setFeeAmounts(am);
      }).catch(console.error);
    };
    window.addEventListener('club-fee-updated', handleClubFeeUpdated);
    return () => {
      window.removeEventListener('club-fee-updated', handleClubFeeUpdated);
    };
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
      setError(e.message || '??? ??? ???????');
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
      setError(e.message || '??? ??? ????????');
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

      // ?????? ?????????: ???????? ??????? ??????? ??????? ??? ?????? ?????.
      try {
        const ob: any = await getStudentOpeningBalances(row.student.id);
        setPriorItems(ob.items || []);
        const am: Record<number, string> = {};
        (ob.items || []).forEach((it: any) => {
          am[it.student_fee_id] = String(it.outstanding ?? it.amount ?? 0);
        });
        setPriorAmounts(am);
        setPriorSelections({});
      } catch (e: any) {
        setPriorItems([]);
        setPriorSelections({});
        setPriorAmounts({});
      }
    } catch (e: any) {
      setError(e.message || '??? ??? ??????');
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
        setError('??? ????? ?? ??? ??? ??? ?????: ' + monthLabel(firstUnpaid));
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

  const clubTotal = useMemo(() => {
    if (!previewData?.club_items) return 0;
    return previewData.club_items.reduce((sum, item) => {
      if (!selectedClubFees[item.club_monthly_fee_id]) return sum;
      return sum + (parseFloat(clubAmounts[item.club_monthly_fee_id] || '0') || 0);
    }, 0);
  }, [previewData, selectedClubFees, clubAmounts]);

  const productsTotal = useMemo(() => {
    return Object.entries(selectedFees).reduce((sum, [id, on]) => {
      if (!on) return sum;
      return sum + (parseFloat(feeAmounts[Number(id)] || '0') || 0);
    }, 0);
  }, [selectedFees, feeAmounts]);

  // ??? ???? ??????? ???????: ?????? ?? ???? ?????? ???? ?? ?????? ??????? ??????.
  const priorTotal = useMemo(() => {
    return Object.entries(priorSelections).reduce((sum, [id, on]) => {
      if (!on) return sum;
      return sum + (parseFloat(priorAmounts[Number(id)] || '0') || 0);
    }, 0);
  }, [priorSelections, priorAmounts]);

  const monthsTotal = parseFloat(monthlyPrice || '0') || 0;
  const itemsTotal = productsTotal + monthsTotal + clubTotal;
  // ??????? ?? ??? ??????? ??? ?????: ??? ????? ?????? ?????? ?? enrollment_discounts.
  // ??? ????? ????? ??????? ???????? ?????? ????? ?? ???? ???????.
  const total = itemsTotal + priorTotal;
  const blockedByFullWaiver = Boolean(previewData?.is_fully_waived) && clubTotal <= 0 && priorTotal <= 0;

  async function handleSave() {
    if (!picked) return;
    if (!selectedMonths.length) { setError('???? ?????'); return; }
    const items = Object.entries(selectedFees)
      .filter(([, on]) => on)
      .map(([id]) => ({ fee_type_id: Number(id), amount: parseFloat(feeAmounts[Number(id)] || '0') }))
      .filter((x) => x.amount > 0);
    const mp = parseFloat(monthlyPrice || '0') || 0;
    if (mp > 0) {
      if (!tuitionFee) { setError('?? ???? ??? ????? ???? «????? ??????». ???? ???? ???????? ?? ???? ?? ???? ????? ????????.'); return; }
      items.unshift({ fee_type_id: Number(tuitionFee.id), amount: mp });
    }
    const mergedItems = Object.values(
      items.reduce((acc: Record<number, { fee_type_id: number; amount: number }>, it) => {
        const key = Number(it.fee_type_id);
        acc[key] = acc[key]
          ? { fee_type_id: key, amount: acc[key].amount + it.amount }
          : { fee_type_id: key, amount: it.amount };
        return acc;
      }, {})
    );
    const clubItems = Object.entries(selectedClubFees)
      .filter(([, on]) => on)
      .map(([id]) => ({
        club_monthly_fee_id: Number(id),
        amount: parseFloat(clubAmounts[Number(id)] || '0'),
      }))
      .filter((x) => x.amount > 0);
    const priorAllocs = Object.entries(priorSelections)
      .filter(([, on]) => on)
      .map(([id]) => ({ student_fee_id: Number(id), amount: parseFloat(priorAmounts[Number(id)] || '0') }))
      .filter((x) => x.amount > 0);

    if (!mergedItems.length && priorAllocs.length === 0) { setError('???? ????? ????? ?? ???? ???????'); return; }

    const payload = {
      student_id: picked.student.id,
      enrollment_id: picked.enrollment_id,
      months: selectedMonths,
      payment_date: paymentDate,
      method,
      reference: reference || null,
      notes: notes || null,
      items: mergedItems,
      club_items: clubItems,
      prior_allocations: priorAllocs,
    };

    setSaving(true);
    setError('');
    try {
      const res: any = await collectPayment(payload as any);
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
          || '?',
        section_name: raw.section_name
          || [level.name, section.name].filter(Boolean).join(' - ')
          || (document.querySelector('select') ? '' : '')
          || (sections.find((s: any) => String(s.id) === String(sectionId))
            ? ((sections.find((s: any) => String(s.id) === String(sectionId))?.level?.name || '') + ' ' + (sections.find((s: any) => String(s.id) === String(sectionId))?.name || '')).trim()
            : '?'),
        guardian_name: raw.guardian_name
          || [guardian.first_name, guardian.last_name].filter(Boolean).join(' ')
          || [picked?.guardian?.first_name, picked?.guardian?.last_name].filter(Boolean).join(' ')
          || '?',
        months_label: raw.months_label || raw.months || selectedMonths,
        items: (raw.items || raw.allocations || []).map((it: any) => ({
          ...it,
          description: it.description || it.name || it.fee_type_name || it.name_ar
            || it.student_fee?.description || it.feeType?.name_ar || '???',
          amount: it.amount ?? it.amount_allocated ?? 0,
          is_prior_year: Boolean(it.is_prior_year),
        })),
        total: raw.total ?? raw.amount,
        amount: raw.amount ?? raw.total,
        discount: raw.discount ?? 0,
        prior_total: raw.prior_total ?? 0,
        method_label: raw.method_label || raw.method,
        payment_id: raw.payment_id || raw.id,
        payment_date: raw.payment_date || raw.paid_at,
        user_name: [user?.first_name, user?.last_name].filter(Boolean).join(' ') || '?',
      });

      await refreshLedger(picked.enrollment_id);
      setSelectedMonths([]);
      setSelectedFees({});
      setSelectedClubFees({});
    } catch (e: any) {
      setError(e.message || '??? ?????');
    } finally {
      setSaving(false);
    }
  }

  // ????? ??? ??? ?????? ?????????? ??? ?? ????? (??? ?? ?????).
  async function refreshLedger(enrollmentId?: number) {
    const eid = enrollmentId ?? picked?.enrollment_id;
    if (!eid) return;
    const ledger: any = await getEnrollmentLedger(eid);
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
    setLedgerRows(uniq.sort((a, b) => String(b.payment_date).localeCompare(String(a.payment_date))));
  }

  // ????? ????? ??????: ???? ????? ???????? ?? ?????? ???? ???????.
  async function handleCancelPayment(paymentId: number): Promise<boolean> {
    const reason = window.prompt('??? ????? ?????? (??????):');
    if (reason === null) return false;
    if (reason.trim().length < 3) { alert('???? ????? ??? ???? (3 ???? ??? ?????)'); return false; }
    try {
      await paymentsApi.cancel(paymentId, reason.trim());
      await refreshLedger();
      alert('?? ????? ?????? ?????? ?????. ???? ?????? ????? ?????? ?????.');
      return true;
    } catch (e: any) {
      alert(e.message || '??? ????? ??????');
      return false;
    }
  }

  async function handleDelete() {
    if (!receipt?.payment_id) return;
    const ok = await handleCancelPayment(Number(receipt.payment_id));
    if (ok) setReceipt(null);
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
            <h1 className="text-2xl font-bold" style={{ color: C.ink }}>??????? ??????</h1>
            <p className="text-sm" style={{ color: C.muted }}>??? ? ??? ? ????? ? ???? ? ?????? ? ???</p>
          </div>
        </div>

        {error && (
          <div className="p-3 rounded-xl text-sm flex gap-2 items-center" style={{ background: '#FEE2E2', color: '#B91C1C' }}>
            <AlertCircle size={16} /> {error}
          </div>
        )}

        <div className="bg-white rounded-2xl border p-4 grid grid-cols-1 md:grid-cols-3 gap-3" style={{ borderColor: C.line }}>
          <div>
            <label className="text-sm font-semibold" style={{ color: C.ink }}>????? ????????</label>
            <select value={yearId} onChange={(e) => onYearChange(e.target.value)} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
              <option value="">???? ?????</option>
              {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
            </select>
          </div>
          <div>
            <label className="text-sm font-semibold" style={{ color: C.ink }}>?????</label>
            <select value={sectionId} onChange={(e) => onSectionChange(e.target.value)} disabled={!yearId} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
              <option value="">???? ?????</option>
              {sections.map((s) => (
                <option key={s.id} value={s.id}>{(s.level?.name ? s.level.name + ' - ' : '') + s.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-sm font-semibold" style={{ color: C.ink }}>???????</label>
            <select value={picked?.enrollment_id || ''} onChange={(e) => onStudentChange(e.target.value)} disabled={!sectionId} className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }}>
              <option value="">???? ???????</option>
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
            {previewData && (
              <div className={`p-4 rounded-2xl border ${
                previewData.is_fully_waived
                  ? 'bg-emerald-50 border-emerald-300 text-emerald-900'
                  : previewData.discount_type === 'humanitarian_fixed'
                  ? 'bg-amber-50 border-amber-300 text-amber-900'
                  : 'bg-blue-50 border-blue-300 text-blue-900'
              }`}>
                <div className="font-bold text-sm mb-1">
                  {previewData.is_fully_waived
                    ? '????? ??? ? ??? ??????? ???? ????? (0 ?.?) ??? ???? ???? ????? ?????'
                    : previewData.discount_type === 'humanitarian_fixed'
                    ? '????? ???? ??????? ????'
                    : previewData.discount_type === 'normal_monthly' || previewData.discount_type === 'normal'
                    ? '????? ???? ????'

                    : '?? ???? ????? ??? ?????? ????????'}
                </div>
                <div className="text-xs space-y-0.5">
                  <div>??????? ??????: {previewData.gross_amount} ? | ???????: {previewData.discount_amount} ? | ?????? ???????: {previewData.remaining_amount} ?</div>
                  {previewData.discount_reason && <div>?????: {previewData.discount_reason}</div>}
                </div>
              </div>
            )}

            <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
              <label className="text-sm font-semibold" style={{ color: C.ink }}>????? ?????? ?????? ???????? (?.?)</label>
              <input
                type="number"
                min="0"
                step="0.01"
                disabled={Boolean(previewData?.is_fully_waived)}
                value={monthlyPrice}
                onChange={(e) => setMonthlyPrice(e.target.value)}
                className="w-full mt-1 border rounded-xl px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400"
                style={{ borderColor: C.line, direction: 'ltr' }}
                placeholder="????: 150"
              />
              <p className="text-xs mt-1" style={{ color: C.muted }}>
                {previewData?.is_fully_waived ? '??????? ???? ????? ??? ???? ????? ??????' : '??????? ?????? ???? ????? ???? ??? ????????? ???????'}
              </p>
            </div>


            <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
              <div className="font-semibold mb-2" style={{ color: C.ink }}>??????</div>
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
                        {monthLabel(m)}{paid ? ' ?' : ''}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            {priorItems.length > 0 && (
              <div className="bg-white rounded-2xl border p-4" style={{ borderColor: '#E7E5E4' }}>
                <div className="font-semibold mb-1" style={{ color: '#57534E' }}>???????? ??????? ??????? (???? ???????)</div>
                <p className="text-xs mb-2" style={{ color: C.muted }}>
                  ??? ??? ?????????? ???? ??????? ???? ?? ?????? ??????? ????? ??????? ? ????? ????? ??????? ??? ?????.
                </p>
                <div className="space-y-2">
                  {priorItems.map((it) => (
                    <label key={it.student_fee_id} className="flex items-center gap-3 p-2 rounded-xl border" style={{ borderColor: '#EDE9E9' }}>
                      <input
                        type="checkbox"
                        checked={!!priorSelections[it.student_fee_id]}
                        onChange={(e) => setPriorSelections((p) => ({ ...p, [it.student_fee_id]: e.target.checked }))}
                      />
                      <span className="flex-1 text-sm" style={{ color: '#44403C' }}>
                        {it.description}
                        <span className="mr-2 text-xs" style={{ color: '#A8A29E' }}>(??????: {Number(it.outstanding ?? 0).toFixed(2)} ?.?)</span>
                      </span>
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        max={Number(it.outstanding ?? 0)}
                        value={priorAmounts[it.student_fee_id] || ''}
                        onChange={(e) => setPriorAmounts((p) => ({ ...p, [it.student_fee_id]: e.target.value }))}
                        className="w-24 border rounded-lg px-2 py-1 text-sm"
                        style={{ borderColor: '#E7E5E4', direction: 'ltr' }}
                      />
                    </label>
                  ))}
                </div>
              </div>
            )}

            {previewData?.club_items?.length > 0 && (
              <div className="bg-white rounded-2xl border p-4" style={{ borderColor: '#D9E6D1' }}>
                <div className="font-semibold mb-1" style={{ color: C.forest }}>??????? ??????? ?????? ????????</div>
                <p className="text-xs mb-2" style={{ color: C.muted }}>????? ???????? ??? ??????? ??????? ??????? ??? ????? ??? ??? ??????? ???.</p>
                <div className="space-y-2">
                  {previewData.club_items.map((item) => (
                    <label key={item.club_monthly_fee_id} className="flex items-center gap-3 p-2 rounded-xl border" style={{ borderColor: C.line }}>
                      <input type="checkbox" checked={!!selectedClubFees[item.club_monthly_fee_id]} onChange={(e) => setSelectedClubFees((p) => ({ ...p, [item.club_monthly_fee_id]: e.target.checked }))} />
                      <span className="flex-1 text-sm" style={{ color: C.ink }}>{item.club_name} ? {monthLabel(item.month)}<span className="mr-2 text-xs" style={{ color: C.muted }}>(??????: {Number(item.remaining_amount).toFixed(2)} ?.?)</span></span>
                      <input type="number" min="0" max={item.remaining_amount} step="0.01" value={clubAmounts[item.club_monthly_fee_id] || ''} onChange={(e) => setClubAmounts((p) => ({ ...p, [item.club_monthly_fee_id]: e.target.value }))} className="w-24 border rounded-lg px-2 py-1 text-sm" style={{ borderColor: C.line, direction: 'ltr' }} />
                    </label>
                  ))}
                </div>
              </div>
            )}

            <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
              <div className="font-semibold mb-2" style={{ color: C.ink }}>???????? / ???????</div>
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
                <label className="text-sm font-semibold" style={{ color: C.ink }}>???????</label>
                <input type="date" value={paymentDate} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full mt-1 border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }} />
              </div>
              <div className="md:col-span-2">
                <div className="text-sm font-semibold mb-2" style={{ color: C.ink }}>????? ?????</div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                  {Object.entries(METHOD_LABELS).map(([k, label]) => (
                    <button key={k} type="button" onClick={() => setMethod(k as any)} className="py-2 rounded-xl text-sm border"
                      style={{ background: method === k ? C.forest : 'white', color: method === k ? 'white' : C.ink, borderColor: C.line }}>
                      {label}
                    </button>
                  ))}
                </div>
              </div>
              <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="???? / ???"
                className="border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }} />
              <input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="???????"
                className="border rounded-xl px-3 py-2 text-sm" style={{ borderColor: C.line }} />
            </div>

            {ledgerRows.length > 0 && (
              <div className="bg-white rounded-2xl border p-4" style={{ borderColor: C.line }}>
                <div className="font-semibold mb-3" style={{ color: C.ink }}>??? ????????? (????? ?? ??????)</div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr style={{ color: C.muted, textAlign: 'right' }}>
                        <th className="py-2">#</th>
                        <th>???????</th>
                        <th>??????</th>
                        <th>??????</th>
                        <th>???????</th>
                        <th>????????</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {ledgerRows.map((r) => (
                        <tr key={r.payment_id} className="border-t" style={{ borderColor: C.line }}>
                          <td className="py-2">{r.payment_id}</td>
                          <td>{r.payment_date}</td>
                          <td>{monthLabel(r.month)}</td>
                          <td style={{ color: C.forest, fontWeight: 700 }}>{Number(r.amount).toFixed(2)} ?.?</td>
                          <td>{METHOD_LABELS[r.method] || r.method}</td>
                          <td>{r.created_by || r.user_name || '?'}</td>
                          <td>
                            <button
                              type="button"
                              className="text-xs px-2 py-1 rounded-lg text-white"
                              style={{ background: '#DC2626' }}
                              onClick={() => handleCancelPayment(r.payment_id)}
                            >?????</button>
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
                <div className="text-xs" style={{ color: C.muted }}>???????</div>
                <div className="text-2xl font-extrabold" style={{ color: C.forest }}>{total.toFixed(2)} ?.?</div>
                {clubTotal > 0 && (
                  <div className="text-xs mt-1" style={{ color: C.forest }}>???? ?????? ?????: {clubTotal.toFixed(2)} ?.?</div>
                )}
                {priorTotal > 0 && (
                  <div className="text-xs mt-1" style={{ color: '#A16207' }}>
                    ???? ??? ???? ???? (??? ??????? ??????): {priorTotal.toFixed(2)} ?.?
                  </div>
                )}
              </div>
              <button type="button" onClick={handleSave} disabled={saving || total <= 0 || blockedByFullWaiver}
                className="px-6 py-3 rounded-2xl text-white font-bold transition disabled:opacity-50"
                style={{ background: saving || total <= 0 || blockedByFullWaiver ? C.muted : C.forest }}>
                {saving ? '???? ?????...' : blockedByFullWaiver ? '???? ????? ? ?? ???? ?????' : '???'}
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
