import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Layers,
  Printer,
  RefreshCw,
  Plus,
  Trash2,
  Edit,
  CheckCircle2,
  AlertCircle,
  XCircle,
  ShieldAlert,
  Info,
  BadgePercent,
  Calendar,
  DollarSign,
  Users,
} from 'lucide-react';
import {
  fetchDiscountRosterOptions,
  fetchDiscountRoster,
  applyDiscountRoster,
  removeDiscountRoster,
  type DiscountRosterOptions,
  type DiscountRosterReport,
  type DiscountRosterRow,
  type MonthDiscountCell,
  type DiscountRosterMonthDef,
} from '../../api/discountRoster';
import { ApiError } from '../../api/http';
import { money } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  grid: '#D9E1D0',
  error: '#A03434',
  errorBg: '#FDECEC',
};

export function DiscountRosterPage() {
  const [options, setOptions] = useState<DiscountRosterOptions | null>(null);
  const [sectionId, setSectionId] = useState<string>('');
  const [yearId, setYearId] = useState<string>('');
  const [data, setData] = useState<DiscountRosterReport | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  // Edit / Add Modal State
  const [modalOpen, setModalOpen] = useState(false);
  const [selectedStudent, setSelectedStudent] = useState<DiscountRosterRow | null>(null);
  const [selectedMonth, setSelectedMonth] = useState<DiscountRosterMonthDef | null>(null);
  const [scope, setScope] = useState<'single_month' | 'range' | 'full_year'>('single_month');
  const [rangeStart, setRangeStart] = useState('');
  const [rangeEnd, setRangeEnd] = useState('');
  const [discountType, setDiscountType] = useState<'normal_monthly' | 'humanitarian_fixed' | 'full_waiver'>('normal_monthly');
  const [amountInput, setAmountInput] = useState('');
  const [reasonInput, setReasonInput] = useState('');
  const [notesInput, setNotesInput] = useState('');

  // Remove / Cancel Modal State
  const [removeModalOpen, setRemoveModalOpen] = useState(false);
  const [removeTargetMonth, setRemoveTargetMonth] = useState<string | null>(null);
  const [removeDiscountId, setRemoveDiscountId] = useState<number | null>(null);
  const [removeReason, setRemoveReason] = useState('');

  const errorText = (e: unknown, fallback: string): string =>
    e instanceof ApiError ? e.firstError : e instanceof Error ? e.message : fallback;

  // 1. Load options on mount
  useEffect(() => {
    const ctrl = new AbortController();
    fetchDiscountRosterOptions(ctrl.signal)
      .then((res) => {
        if (ctrl.signal.aborted) return;
        setOptions(res);
        if (res.active_year_id) setYearId(String(res.active_year_id));
        if (res.sections.length > 0) setSectionId(String(res.sections[0].id));
      })
      .catch((e) => {
        if (!ctrl.signal.aborted) setError(errorText(e, 'تعذّر تحميل خيارات الأقسام'));
      });
    return () => ctrl.abort();
  }, []);

  // 2. Load roster when section or year changes
  const loadRoster = async (signal?: AbortSignal) => {
    if (!sectionId) {
      setData(null);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const res = await fetchDiscountRoster(sectionId, yearId || undefined, signal);
      if (signal?.aborted) return;
      setData(res);
    } catch (e) {
      if (signal?.aborted) return;
      setData(null);
      setError(errorText(e, 'تعذّر تحميل كشف جرد التخفيضات'));
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  };

  useEffect(() => {
    const ctrl = new AbortController();
    loadRoster(ctrl.signal);
    return () => ctrl.abort();
  }, [sectionId, yearId]);

  // Handle opening add/edit modal for a specific student and month
  const openEditModal = (student: DiscountRosterRow, monthDef?: DiscountRosterMonthDef) => {
    setSelectedStudent(student);
    setSelectedMonth(monthDef ?? null);

    const cell = monthDef ? student.months[monthDef.key] : null;

    if (cell && cell.has_discount) {
      // Prefill with existing discount values
      setDiscountType(
        cell.discount_type === 'full_waiver'
          ? 'full_waiver'
          : cell.discount_type === 'humanitarian_fixed'
          ? 'humanitarian_fixed'
          : 'normal_monthly'
      );
      setAmountInput(cell.discount_type === 'full_waiver' ? '' : String(cell.amount));
      setReasonInput(cell.reason ?? '');
      setNotesInput(cell.notes ?? '');
      setScope('single_month');
    } else {
      // Empty discount default
      setDiscountType('normal_monthly');
      const maxCap = student.discount_cap;
      setAmountInput(String(Math.min(20, maxCap)));
      setReasonInput(monthDef ? `تخفيض شهر ${monthDef.label}` : 'تخفيض دراسي');
      setNotesInput('');
      setScope(monthDef ? 'single_month' : 'full_year');
    }

    if (monthDef) {
      setRangeStart(monthDef.key);
      setRangeEnd(monthDef.key);
    } else if (data?.months && data.months.length > 0) {
      setRangeStart(data.months[0].key);
      setRangeEnd(data.months[data.months.length - 1].key);
    }

    setModalOpen(true);
  };

  // Submit Add / Edit
  const handleSaveDiscount = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedStudent) return;

    setError('');
    setNotice('');

    if (discountType === 'humanitarian_fixed') {
      const amt = parseFloat(amountInput);
      if (isNaN(amt) || amt <= 20) {
        setError('مبلغ التخفيض الإنساني يجب أن يكون أكبر من 20 ديناراً');
        return;
      }
      if (amt > selectedStudent.monthly_fee) {
        setError(`مبلغ التخفيض الإنساني لا يمكن أن يتجاوز المعلوم الشهري (${selectedStudent.monthly_fee} د)`);
        return;
      }
    } else if (discountType === 'normal_monthly') {
      const amt = parseFloat(amountInput);
      if (isNaN(amt) || amt <= 0) {
        setError('مبلغ التخفيض الشهري يجب أن يكون أكبر من الصفر');
        return;
      }
      if (amt > selectedStudent.discount_cap) {
        setError(`مبلغ التخفيض يتجاوز سقف 20% المسموح به (${selectedStudent.discount_cap} د)`);
        return;
      }
    }

    if (!reasonInput.trim()) {
      setError('سبب التخفيض إجباري');
      return;
    }

    setBusy(true);
    try {
      await applyDiscountRoster({
        enrollment_id: selectedStudent.enrollment_id,
        scope,
        target_month: scope === 'single_month' ? (selectedMonth ? selectedMonth.key : rangeStart) : undefined,
        start_month: scope === 'range' ? rangeStart : undefined,
        end_month: scope === 'range' ? rangeEnd : undefined,
        discount_type: discountType,
        monthly_amount: discountType === 'full_waiver' ? null : parseFloat(amountInput),
        reason: reasonInput.trim(),
        notes: notesInput.trim() || undefined,
      });

      setNotice('تم حفظ التخفيض بنجاح وتحديث الكشف دون المساس ببقية الأشهر');
      setModalOpen(false);
      await loadRoster();
    } catch (err) {
      setError(errorText(err, 'تعذّر حفظ التخفيض'));
    } finally {
      setBusy(false);
    }
  };

  // Open remove modal
  const openRemoveModal = (student: DiscountRosterRow, monthKey?: string, discountId?: number) => {
    setSelectedStudent(student);
    setRemoveTargetMonth(monthKey ?? null);
    setRemoveDiscountId(discountId ?? null);
    setRemoveReason(monthKey ? `إلغاء تخفيض شهر ${monthKey}` : 'إلغاء التخفيض');
    setRemoveModalOpen(true);
  };

  // Submit removal
  const handleConfirmRemove = async () => {
    if (!selectedStudent || !removeReason.trim()) return;

    setBusy(true);
    setError('');
    setNotice('');
    try {
      const res = await removeDiscountRoster({
        enrollment_id: selectedStudent.enrollment_id,
        target_month: removeTargetMonth || undefined,
        discount_id: removeDiscountId || undefined,
        reason: removeReason.trim(),
      });

      setNotice(res.message || 'تم حذف التخفيض بنجاح مع الحفاظ على بقية الأشهر');
      setRemoveModalOpen(false);
      await loadRoster();
    } catch (err) {
      setError(errorText(err, 'تعذّر حذف التخفيض'));
    } finally {
      setBusy(false);
    }
  };

  const inputStyle = { border: `1px solid ${C.line}`, color: C.ink } as const;
  const cell = { border: `1px solid ${C.grid}` } as const;

  return (
    <div className="px-6 pb-12 max-w-full mx-auto" dir="rtl">
      {/* ── Print Styles ──────────────────────────────────────── */}
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap');
        @media print {
          @page { size: A4 landscape; margin: 8mm; }
          * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
          body * { visibility: hidden !important; }
          #discount-roster-print, #discount-roster-print * { visibility: visible !important; }
          #discount-roster-print {
            position: absolute !important;
            top: 0 !important; left: 0 !important; right: 0 !important;
            width: 100% !important;
            min-height: 88vh !important;
            margin: 0 !important;
            padding: 6mm !important;
            background: #fff !important;
            border: 1px solid #3B4A36 !important;
            font-family: 'Cairo', sans-serif !important;
            font-size: 11px !important;
          }
          #discount-roster-print table {
            width: 100% !important;
            table-layout: fixed !important;
            border-collapse: collapse !important;
          }
          #discount-roster-print th {
            background: #3B4A36 !important;
            color: #fff !important;
            font-size: 10px !important;
            padding: 4px 6px !important;
            border: 1px solid #3B4A36 !important;
          }
          #discount-roster-print td {
            font-size: 10px !important;
            padding: 4px 5px !important;
            border: 1px solid #ddd !important;
          }
          .no-print { display: none !important; }
        }
      `}</style>

      {/* ── Top Breadcrumb & Actions ──────────────────────────── */}
      <div className="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4 no-print">
        <div>
          <div className="flex items-center gap-2 text-xs text-slate-500 mb-1">
            <Link to="/discounts" className="hover:underline text-[#3B4A36] font-semibold">التخفيضات</Link>
            <span>/</span>
            <span className="font-bold text-slate-800">جرد التخفيضات (حسب القسم والأشهر)</span>
          </div>
          <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <Layers className="text-[#3B4A36]" size={26} />
            جرد التخفيضات بالقسم والأشهر
          </h1>
          <p className="text-slate-500 text-sm mt-1">
            كشفٌ شامل لكل تلاميذ القسم وتخفيضاتهم الموزّعة على أشهر السنة العشرة، مع إمكانية تعديل شهر منفرد أو حذفه دون المساس ببقية الأشهر.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <Link
            to="/discounts"
            className="px-4 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 shadow-sm transition"
          >
            التخفيض الفردي
          </Link>
          <Link
            to="/discounts/monthly"
            className="px-4 py-2 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 shadow-sm transition"
          >
            إعفاءات النوادي والدروس
          </Link>
        </div>
      </div>

      {/* ── Filter Bar ────────────────────────────────────────── */}
      <div
        className="bg-white rounded-2xl p-4 mb-5 flex flex-wrap items-end gap-4 shadow-sm no-print"
        style={{ border: `1px solid ${C.line}` }}
      >
        <div>
          <label htmlFor="roster_section" className="block text-xs font-semibold mb-1" style={{ color: C.muted }}>
            القسم
          </label>
          <select
            id="roster_section"
            value={sectionId}
            onChange={(e) => setSectionId(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm min-w-[14rem] bg-white outline-none focus:border-[#3B4A36]"
            style={inputStyle}
          >
            <option value="">اختر القسم…</option>
            {(options?.sections ?? []).map((s) => (
              <option key={s.id} value={s.id}>
                {s.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="roster_year" className="block text-xs font-semibold mb-1" style={{ color: C.muted }}>
            السنة الدراسية
          </label>
          <select
            id="roster_year"
            value={yearId}
            onChange={(e) => setYearId(e.target.value)}
            className="rounded-xl px-3 py-2 text-sm bg-white outline-none focus:border-[#3B4A36]"
            style={inputStyle}
          >
            {(options?.years ?? []).map((y) => (
              <option key={y.id} value={y.id}>
                {y.name} {y.is_active ? ' (النشطة)' : ''}
              </option>
            ))}
          </select>
        </div>

        <button
          type="button"
          onClick={() => void loadRoster()}
          disabled={!sectionId || loading}
          className="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold disabled:opacity-50 transition"
          style={{ border: `1px solid ${C.line}`, color: C.forest }}
        >
          <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
          <span>تحديث</span>
        </button>

        <button
          type="button"
          onClick={() => window.print()}
          disabled={!data || data.rows.length === 0}
          className="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold text-white disabled:opacity-50 shadow-sm transition"
          style={{ backgroundColor: C.forest }}
        >
          <Printer size={16} />
          <span>طباعة كشف الجرد</span>
        </button>
      </div>

      {/* ── Alerts ────────────────────────────────────────────── */}
      {error && (
        <div
          className="rounded-2xl p-4 mb-4 flex items-start gap-3 text-sm no-print shadow-sm"
          style={{ backgroundColor: C.errorBg, color: C.error }}
          role="alert"
        >
          <AlertCircle size={18} className="shrink-0 mt-0.5" />
          <span className="font-semibold">{error}</span>
        </div>
      )}

      {notice && (
        <div className="rounded-2xl p-4 mb-4 flex items-start gap-3 text-sm no-print shadow-sm" style={{ backgroundColor: C.sage, color: C.forest }}>
          <CheckCircle2 size={18} className="shrink-0 mt-0.5" />
          <span className="font-semibold">{notice}</span>
        </div>
      )}

      {loading && <PageDataSkeleton />}

      {!loading && !data && !error && (
        <div className="bg-white rounded-2xl p-12 text-center text-sm text-slate-500 border border-slate-200 no-print">
          يرجى اختيار القسم لعرض كشف جرد التخفيضات.
        </div>
      )}

      {/* ── Roster Content & Print Container ──────────────────── */}
      {!loading && data && (
        <div id="discount-roster-print">
          {/* Print Header */}
          <div className="hidden print:block mb-4 border-b pb-2">
            <div className="flex justify-between items-center">
              <div>
                <h2 className="text-base font-bold text-slate-900">مجمع بروفيدنس التربوي بسيدي بوزيد</h2>
                <h3 className="text-sm font-semibold text-slate-700">
                  كشف جرد تخفيضات قسم {data.section.label} — السنة الدراسية {data.academic_year.name}
                </h3>
              </div>
              <div className="text-left text-xs text-slate-500">
                <p>تاريخ الطباعة: {new Date().toLocaleDateString('fr-TN')}</p>
                <p>المعلوم الشهري المرجعي: {money(data.reference_monthly_fee)}</p>
              </div>
            </div>
          </div>

          {/* ── Summary Stat Cards ──────────────────────────────── */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5 no-print">
            <div className="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-700 shrink-0">
                <Users size={20} />
              </div>
              <div>
                <p className="text-xs text-slate-500 font-semibold">إجمالي تلاميذ القسم</p>
                <p className="text-xl font-bold text-slate-800">{data.summary.students_count}</p>
              </div>
            </div>

            <div className="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-700 shrink-0">
                <BadgePercent size={20} />
              </div>
              <div>
                <p className="text-xs text-slate-500 font-semibold">متمتعون بتخفيض</p>
                <p className="text-xl font-bold text-emerald-700">
                  {data.summary.with_discount_count}
                  <span className="text-xs text-slate-400 font-normal mr-1">
                    ({data.summary.students_count > 0 ? Math.round((data.summary.with_discount_count / data.summary.students_count) * 100) : 0}%)
                  </span>
                </p>
              </div>
            </div>

            <div className="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-600 shrink-0">
                <Users size={20} />
              </div>
              <div>
                <p className="text-xs text-slate-500 font-semibold">بدون تخفيض</p>
                <p className="text-xl font-bold text-slate-700">{data.summary.without_discount_count}</p>
              </div>
            </div>

            <div className="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-[#E3EBDB] flex items-center justify-center text-[#3B4A36] shrink-0">
                <DollarSign size={20} />
              </div>
              <div>
                <p className="text-xs text-slate-500 font-semibold">مجموع التخفيضات السنوية</p>
                <p className="text-xl font-bold text-[#3B4A36]">{money(data.summary.total_discount_amount)}</p>
              </div>
            </div>
          </div>

          {/* ── Legend Bar ──────────────────────────────────────── */}
          <div className="flex flex-wrap items-center justify-between gap-3 mb-3 text-xs no-print px-1">
            <div className="flex items-center gap-4">
              <span className="inline-flex items-center gap-1.5 font-semibold text-slate-600">
                <span className="w-3 h-3 rounded-full bg-blue-500" /> تخفيض عادي (سقف 20%)
              </span>
              <span className="inline-flex items-center gap-1.5 font-semibold text-slate-600">
                <span className="w-3 h-3 rounded-full bg-emerald-600" /> إعفاء كلي تام
              </span>
              <span className="inline-flex items-center gap-1.5 font-semibold text-slate-600">
                <span className="w-3 h-3 rounded-full bg-amber-600" /> تخفيض حالة إنسانية (&gt; 20 د)
              </span>
            </div>
            <div className="text-slate-500">
              المعلوم المرجعي: <strong className="text-slate-800">{money(data.reference_monthly_fee)}</strong> | سقف التخفيض العادي (20%): <strong className="text-slate-800">{money(data.discount_cap)}</strong>
            </div>
          </div>

          {/* ── Main Roster Table ───────────────────────────────── */}
          <div className="bg-white rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
            <div className="overflow-x-auto">
              <table className="w-full text-sm border-collapse" style={{ borderCollapse: 'collapse' }}>
                <thead style={{ backgroundColor: C.sage }}>
                  <tr>
                    <th className="text-right px-3 py-2.5 font-bold text-slate-800 w-10" style={cell}>#</th>
                    <th className="text-right px-3 py-2.5 font-bold text-slate-800 whitespace-nowrap min-w-[12rem]" style={cell}>
                      الإسم واللقب
                    </th>
                    <th className="text-right px-2 py-2.5 font-bold text-slate-800 whitespace-nowrap" style={cell}>الرمز</th>
                    <th className="text-right px-2 py-2.5 font-bold text-slate-800 whitespace-nowrap" style={cell}>المعلوم المرجعي</th>
                    {data.months.map((m) => (
                      <th key={m.key} className="text-center px-2 py-2.5 font-bold text-slate-800 whitespace-nowrap" style={cell}>
                        {m.label}
                      </th>
                    ))}
                    <th className="text-center px-3 py-2.5 font-bold text-slate-800 whitespace-nowrap" style={cell}>
                      إجمالي التخفيض
                    </th>
                    <th className="text-center px-3 py-2.5 font-bold text-slate-800 whitespace-nowrap no-print" style={cell}>
                      إجراءات
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.length === 0 ? (
                    <tr>
                      <td colSpan={data.months.length + 6} className="py-12 text-center text-slate-400">
                        لا يوجد تلاميذ مسجلون في هذا القسم في السنة المختارة.
                      </td>
                    </tr>
                  ) : (
                    data.rows.map((row, idx) => (
                      <tr key={row.enrollment_id} className="hover:bg-slate-50/60 transition">
                        <td className="px-3 py-2 text-center text-slate-400 text-xs" style={cell}>
                          {idx + 1}
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap font-bold text-slate-800" style={cell}>
                          {row.name}
                        </td>
                        <td className="px-2 py-2 text-slate-500 text-xs whitespace-nowrap font-mono" style={cell}>
                          {row.student_code ?? '—'}
                        </td>
                        <td className="px-2 py-2 text-slate-600 text-xs whitespace-nowrap" style={cell}>
                          {money(row.monthly_fee)}
                        </td>

                        {/* 10 Month Cells */}
                        {data.months.map((m) => {
                          const cellData: MonthDiscountCell = row.months[m.key];
                          const hasDisc = cellData && cellData.has_discount;

                          let badgeColor = 'bg-slate-100 text-slate-700';
                          if (hasDisc) {
                            if (cellData.discount_type === 'full_waiver') {
                              badgeColor = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                            } else if (cellData.discount_type === 'humanitarian_fixed') {
                              badgeColor = 'bg-amber-100 text-amber-900 border-amber-300';
                            } else {
                              badgeColor = 'bg-blue-100 text-blue-900 border-blue-300';
                            }
                          }

                          return (
                            <td
                              key={m.key}
                              className="px-1.5 py-2 text-center text-xs"
                              style={cell}
                            >
                              {hasDisc ? (
                                <div className="group relative inline-block">
                                  <button
                                    type="button"
                                    onClick={() => openEditModal(row, m)}
                                    title={`${cellData.reason ?? 'تخفيض'} (${cellData.amount_formatted})`}
                                    className={`px-2 py-1 rounded-lg font-bold border text-[11px] shadow-xs cursor-pointer hover:opacity-80 transition ${badgeColor}`}
                                  >
                                    {cellData.amount_formatted}
                                  </button>

                                  {/* Quick Actions on Hover / Click (No print) */}
                                  <div className="hidden group-hover:flex no-print absolute left-1/2 -translate-x-1/2 -top-7 z-20 items-center gap-1 bg-slate-900 text-white p-1 rounded-lg shadow-lg text-[10px]">
                                    <button
                                      type="button"
                                      onClick={() => openEditModal(row, m)}
                                      className="hover:text-blue-300 px-1 font-bold"
                                      title="تعديل هذا الشهر"
                                    >
                                      تعديل
                                    </button>
                                    <span>|</span>
                                    <button
                                      type="button"
                                      onClick={() => openRemoveModal(row, m.key, cellData.discount_id ?? undefined)}
                                      className="hover:text-red-300 px-1 font-bold"
                                      title="حذف تخفيض هذا الشهر"
                                    >
                                      حذف
                                    </button>
                                  </div>
                                </div>
                              ) : (
                                <button
                                  type="button"
                                  onClick={() => openEditModal(row, m)}
                                  title={`إضافة تخفيض لشهر ${m.label}`}
                                  className="w-full py-1 text-slate-300 hover:text-[#3B4A36] hover:bg-[#E3EBDB]/30 rounded transition text-center cursor-pointer no-print font-bold text-xs"
                                >
                                  —
                                </button>
                              )}
                            </td>
                          );
                        })}

                        {/* Total Student Discount */}
                        <td className="px-3 py-2 text-center font-bold text-slate-800 whitespace-nowrap text-xs" style={cell}>
                          {row.total_discount > 0 ? (
                            <span className="text-[#3B4A36]">{money(row.total_discount)}</span>
                          ) : (
                            <span className="text-slate-400">0.000 د</span>
                          )}
                        </td>

                        {/* Student Actions */}
                        <td className="px-2 py-2 text-center whitespace-nowrap no-print" style={cell}>
                          <button
                            type="button"
                            onClick={() => openEditModal(row)}
                            className="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-[#E3EBDB] text-slate-700 hover:text-[#3B4A36] transition"
                            title="إضافة / تعديل تخفيض"
                          >
                            <Plus size={13} />
                            <span>تعديل</span>
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ── Edit / Add Modal ──────────────────────────────────── */}
      {modalOpen && selectedStudent && (
        <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl max-w-xl w-full shadow-2xl overflow-hidden border border-slate-200" dir="rtl">
            <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50">
              <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <DollarSign size={20} className="text-[#3B4A36]" />
                إسناد / تعديل تخفيض للتلميذ: {selectedStudent.name}
              </h3>
              <button
                type="button"
                onClick={() => setModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 rounded-xl p-1"
              >
                <XCircle size={20} />
              </button>
            </div>

            <form onSubmit={handleSaveDiscount} className="p-6 space-y-5">
              {/* Scope Selection */}
              <div>
                <span className="block text-xs font-bold text-slate-600 mb-2">
                  نطاق تطبيق التخفيض:
                </span>
                <div className="grid grid-cols-3 gap-2">
                  <button
                    type="button"
                    onClick={() => setScope('single_month')}
                    className={`px-3 py-2 rounded-xl text-xs font-bold border transition ${
                      scope === 'single_month'
                        ? 'bg-[#3B4A36] text-white border-[#3B4A36] shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    شهر منفرد {selectedMonth ? `(${selectedMonth.label})` : ''}
                  </button>
                  <button
                    type="button"
                    onClick={() => setScope('range')}
                    className={`px-3 py-2 rounded-xl text-xs font-bold border transition ${
                      scope === 'range'
                        ? 'bg-[#3B4A36] text-white border-[#3B4A36] shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    فترة محددة بالأشهر
                  </button>
                  <button
                    type="button"
                    onClick={() => setScope('full_year')}
                    className={`px-3 py-2 rounded-xl text-xs font-bold border transition ${
                      scope === 'full_year'
                        ? 'bg-[#3B4A36] text-white border-[#3B4A36] shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    كامل السنة الدراسية
                  </button>
                </div>
              </div>

              {/* Month Selector for Single Month */}
              {scope === 'single_month' && (
                <div className="bg-slate-50 p-3 rounded-2xl border border-slate-200">
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    الشهر المستهدف حصراً (دون المساس ببقية الأشهر):
                  </label>
                  <select
                    value={selectedMonth?.key ?? rangeStart}
                    onChange={(e) => {
                      const found = data?.months.find((m) => m.key === e.target.value);
                      if (found) setSelectedMonth(found);
                      setRangeStart(e.target.value);
                    }}
                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
                  >
                    {data?.months.map((m) => (
                      <option key={m.key} value={m.key}>
                        {m.label} ({m.year})
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {/* Range Selector */}
              {scope === 'range' && (
                <div className="bg-slate-50 p-3 rounded-2xl border border-slate-200 grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">من شهر:</label>
                    <select
                      value={rangeStart}
                      onChange={(e) => setRangeStart(e.target.value)}
                      className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
                    >
                      {data?.months.map((m) => (
                        <option key={m.key} value={m.key}>
                          {m.label} {m.year}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">إلى شهر:</label>
                    <select
                      value={rangeEnd}
                      onChange={(e) => setRangeEnd(e.target.value)}
                      className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
                    >
                      {data?.months.map((m) => (
                        <option key={m.key} value={m.key}>
                          {m.label} {m.year}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              )}

              {/* Discount Type */}
              <div>
                <span className="block text-xs font-bold text-slate-600 mb-2">نوع التخفيض:</span>
                <div className="grid grid-cols-3 gap-2">
                  <button
                    type="button"
                    onClick={() => setDiscountType('normal_monthly')}
                    className={`px-3 py-2 rounded-xl text-xs font-bold border transition ${
                      discountType === 'normal_monthly'
                        ? 'bg-blue-700 text-white border-blue-700 shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    عادي (سقف 20%)
                  </button>
                  <button
                    type="button"
                    onClick={() => setDiscountType('full_waiver')}
                    className={`px-3 py-2 rounded-xl text-xs font-bold border transition ${
                      discountType === 'full_waiver'
                        ? 'bg-emerald-700 text-white border-emerald-700 shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    إعفاء كلي تام
                  </button>
                  <button
                    type="button"
                    onClick={() => setDiscountType('humanitarian_fixed')}
                    className={`px-3 py-2 rounded-xl text-xs font-bold border transition ${
                      discountType === 'humanitarian_fixed'
                        ? 'bg-amber-700 text-white border-amber-700 shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    حالة إنسانية (&gt; 20 د)
                  </button>
                </div>
              </div>

              {/* Amount Input */}
              {discountType !== 'full_waiver' ? (
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    مبلغ التخفيض الشهري (د.ت) <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    min={discountType === 'humanitarian_fixed' ? 20.01 : 0.01}
                    max={discountType === 'normal_monthly' ? selectedStudent.discount_cap : selectedStudent.monthly_fee}
                    required
                    value={amountInput}
                    onChange={(e) => setAmountInput(e.target.value)}
                    placeholder="مثلاً 20.00"
                    className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
                  />
                  <div className="flex justify-between text-[11px] mt-1 text-slate-500">
                    <span>المعلوم الشهري: {money(selectedStudent.monthly_fee)}</span>
                    {discountType === 'normal_monthly' && (
                      <span className="text-blue-700 font-semibold">
                        السقف الأقصى المسموح (20%): {money(selectedStudent.discount_cap)}
                      </span>
                    )}
                  </div>
                </div>
              ) : (
                <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center gap-2">
                  <Info size={16} className="shrink-0 text-emerald-600" />
                  <span>إعفاء كلي: يُعفى التلميذ بنسبة 100% ويصبح المطلوب 0 د.ت لهذا النطاق.</span>
                </div>
              )}

              {/* Reason Input */}
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  سبب التخفيض التوثيقي <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={reasonInput}
                  onChange={(e) => setReasonInput(e.target.value)}
                  placeholder="مثلاً: أخوة، ظرف استثنائي، تفوق دراسي..."
                  className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#3B4A36]"
                />
              </div>

              {/* Notes */}
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">
                  ملاحظات إضافية (اختياري)
                </label>
                <textarea
                  rows={2}
                  value={notesInput}
                  onChange={(e) => setNotesInput(e.target.value)}
                  placeholder="ملاحظات مرجعية للإدارة..."
                  className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs outline-none focus:border-[#3B4A36]"
                />
              </div>

              {/* Action Buttons */}
              <div className="flex items-center justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={busy}
                  className="px-5 py-2 rounded-xl text-xs font-bold bg-[#3B4A36] text-white hover:bg-[#2E3B2A] transition disabled:opacity-50 shadow-sm"
                >
                  {busy ? 'جارٍ الحفظ…' : 'حفظ التخفيض'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ── Remove / Cancel Confirmation Modal ────────────────── */}
      {removeModalOpen && selectedStudent && (
        <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl max-w-md w-full shadow-2xl p-6 border border-slate-200 space-y-4" dir="rtl">
            <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
              <ShieldAlert size={20} className="text-red-600" />
              تأكيد حذف / إلغاء التخفيض
            </h3>

            <div className="p-3 bg-red-50 border border-red-200 rounded-2xl text-xs text-red-800">
              {removeTargetMonth ? (
                <p>
                  سيتم حذف التخفيض لـ <strong>شهر {removeTargetMonth} فقط</strong> للتلميذ{' '}
                  <strong>{selectedStudent.name}</strong>، مع <strong>الحفاظ التام على تخفيض بقية الأشهر</strong> السابقة واللاحقة.
                </p>
              ) : (
                <p>
                  سيتم إلغاء التخفيض بالكامل للتلميذ <strong>{selectedStudent.name}</strong>. سيبقى أثر التخفيض وإلغائه موثقاً في السجلات.
                </p>
              )}
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                سبب الإلغاء التوثيقي <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                required
                value={removeReason}
                onChange={(e) => setRemoveReason(e.target.value)}
                placeholder="سبب الإلغاء..."
                className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-red-500"
              />
            </div>

            <div className="flex items-center justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setRemoveModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200"
              >
                تراجع
              </button>
              <button
                type="button"
                onClick={handleConfirmRemove}
                disabled={busy || !removeReason.trim()}
                className="px-5 py-2 rounded-xl text-xs font-bold bg-red-600 text-white hover:bg-red-700 disabled:opacity-50"
              >
                {busy ? 'جارٍ الإلغاء…' : 'تأكيد الحذف'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
