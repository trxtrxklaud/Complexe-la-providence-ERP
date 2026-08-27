import React, { useEffect, useState } from 'react';
import {
  X, CheckSquare, Square, DollarSign, Loader2, AlertCircle, Award, CheckCircle2,
  Calendar, Shield, User, Sparkles, AlertTriangle
} from 'lucide-react';
import {
  type FamilyFullDetails,
  type FamilyStudentDetail,
  type StudentAllocationInput,
  type FamilyOldDebtsResponse,
  type FamilyOldDebtStudent,
  collectFamilyPayment,
  fetchFamilyOldDebts
} from '../../api/families';
import type { ReceiptData } from '../../pages/Payments/ReceiptModal';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };

interface Props {
  family: FamilyFullDetails;
  onClose: () => void;
  onSuccess: (receipt: ReceiptData) => void;
}

function money(v: number): string {
  return (v || 0).toFixed(2);
}

export function FamilyCollectionModal({ family, onClose, onSuccess }: Props) {
  // تتبع الأشهر المختارة لكل تلميذ: { [studentId]: string[] (e.g. ['2026-09', '2026-10']) }
  const [selectedMonthsByStudent, setSelectedMonthsByStudent] = useState<Record<number, string[]>>({});

  // تتبع النوادي المختارة لكل تلميذ: { [studentId]: { [clubMonthlyFeeId]: number (amount) } }
  const [selectedClubsByStudent, setSelectedClubsByStudent] = useState<Record<number, Record<number, number>>>({});

  // تتبع المتخلدات المختارة ومبالغها: { [studentId]: { [studentFeeId]: number (amount to pay) } }
  const [selectedArrearsByStudent, setSelectedArrearsByStudent] = useState<Record<number, Record<number, number>>>({});

  // بيانات عملية القبض
  const [paymentDate, setPaymentDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [method, setMethod] = useState<string>('cash');
  const [reference, setReference] = useState<string>('');
  const [notes, setNotes] = useState<string>('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // تنبيه الديون القديمة (قراءة فقط): يُجلب مرة واحدة عند فتح النافذة أو تغيّر
  // العائلة، ولا يُعاد مع كل تعديل مبلغ. فشله لا يعطّل الاستخلاص إطلاقاً.
  const [oldDebts, setOldDebts] = useState<FamilyOldDebtsResponse | null>(null);

  useEffect(() => {
    let active = true;
    fetchFamilyOldDebts(family.id)
      .then((res) => { if (active) setOldDebts(res); })
      .catch((err) => {
        console.error('family old-debts warning failed', err);
        if (active) setOldDebts(null);
      });
    return () => { active = false; };
  }, [family.id]);

  // تبديل اختيار شهر دراسي لتلميذ
  const toggleStudentMonth = (student: FamilyStudentDetail, monthKey: string) => {
    const current = selectedMonthsByStudent[student.id] || [];
    const isSelected = current.includes(monthKey);

    if (isSelected) {
      // عند إلغاء تحديد شهر، أزل هذا الشهر وكل ما بعده
      setSelectedMonthsByStudent((prev) => ({
        ...prev,
        [student.id]: current.filter((m) => m < monthKey),
      }));
      return;
    }

    // منع تجاوز الأشهر: يجب أن يكون الشهر السابق مدفوعاً أو مختاراً
    const unpaidMonths = student.months_grid
      .filter((m) => m.status !== 'paid' && m.status !== 'waived')
      .map((m) => m.month)
      .sort();

    const firstUnpaid = unpaidMonths[0];
    if (firstUnpaid && monthKey > firstUnpaid && !current.includes(firstUnpaid)) {
      // الشهر الأول غير المدفوع لم يُختر بعد — لا يمكن تجاوزه
      alert(`يجب استخلاص ${student.months_grid.find(m => m.month === firstUnpaid)?.name_ar ?? firstUnpaid} أولاً قبل اختيار شهر لاحق`);
      return;
    }

    // تأكد أن كل الأشهر السابقة غير المدفوعة مختارة قبل هذا الشهر
    const monthsBefore = unpaidMonths.filter((m) => m < monthKey && !current.includes(m));
    if (monthsBefore.length > 0) {
      alert(`يجب اختيار الأشهر السابقة أولاً قبل اختيار هذا الشهر`);
      return;
    }

    setSelectedMonthsByStudent((prev) => ({
      ...prev,
      [student.id]: [...current, monthKey].sort(),
    }));
  };

  // تبديل اختيار شهر نادي لتلميذ
  const toggleStudentClub = (studentId: number, clubMonthlyFeeId: number, feeAmount: number) => {
    setSelectedClubsByStudent((prev) => {
      const studentClubs = { ...(prev[studentId] || {}) };
      if (studentClubs[clubMonthlyFeeId]) {
        delete studentClubs[clubMonthlyFeeId];
      } else {
        studentClubs[clubMonthlyFeeId] = feeAmount;
      }
      return {
        ...prev,
        [studentId]: studentClubs,
      };
    });
  };

  // تبديل اختيار متخلد سابق
  const toggleStudentArrear = (studentId: number, feeId: number, remaining: number) => {
    setSelectedArrearsByStudent((prev) => {
      const studentArrears = { ...(prev[studentId] || {}) };
      if (studentArrears[feeId] !== undefined) {
        delete studentArrears[feeId];
      } else {
        studentArrears[feeId] = remaining;
      }
      return {
        ...prev,
        [studentId]: studentArrears,
      };
    });
  };

  // تعديل مبلغ الدفع الجزئي للمتخلد
  const updateArrearAmount = (studentId: number, feeId: number, amount: number, maxAmount: number) => {
    const validAmount = Math.max(0, Math.min(amount, maxAmount));
    setSelectedArrearsByStudent((prev) => {
      const studentArrears = { ...(prev[studentId] || {}) };
      if (validAmount > 0) {
        studentArrears[feeId] = validAmount;
      } else {
        delete studentArrears[feeId];
      }
      return {
        ...prev,
        [studentId]: studentArrears,
      };
    });
  };

  // حساب المجموع لكل تلميذ
  const calculateStudentSubtotal = (student: FamilyStudentDetail): number => {
    let subtotal = 0;

    // 1. الأشهر الدراسية المختارة
    const months = selectedMonthsByStudent[student.id] || [];
    for (const mKey of months) {
      const mObj = student.months_grid.find((m) => m.month === mKey);
      if (mObj) {
        subtotal += Number(mObj.net_amount || 0);
      }
    }

    // 2. النوادي المختارة
    const clubs = selectedClubsByStudent[student.id] || {};
    for (const amt of Object.values(clubs)) {
      subtotal += Number(amt || 0);
    }

    // 3. المتخلدات المختارة
    const arrears = selectedArrearsByStudent[student.id] || {};
    for (const amt of Object.values(arrears)) {
      subtotal += Number(amt || 0);
    }

    return roundMoney(subtotal);
  };

  function roundMoney(v: number): number {
    return Math.round((v + Number.EPSILON) * 100) / 100;
  }

  // إجمالي العملية العائلية
  const grandTotal = family.students.reduce((sum, st) => sum + calculateStudentSubtotal(st), 0);

  // إرسال عملية الاستخلاص العائلي
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (grandTotal <= 0) {
      setError('يرجى اختيار شهر أو خدمة أو متخلد واحد على الأقل للاستخلاص');
      return;
    }

    setSaving(true);
    setError(null);

    const studentsAllocations: StudentAllocationInput[] = [];

    for (const student of family.students) {
      const months = selectedMonthsByStudent[student.id] || [];
      const clubsObj = selectedClubsByStudent[student.id] || {};
      const arrearsObj = selectedArrearsByStudent[student.id] || {};

      const clubItems = Object.entries(clubsObj).map(([id, amount]) => ({
        club_monthly_fee_id: Number(id),
        amount: Number(amount),
      }));

      const priorAllocations = Object.entries(arrearsObj).map(([id, amount]) => ({
        student_fee_id: Number(id),
        amount: Number(amount),
      }));

      if (months.length > 0 || clubItems.length > 0 || priorAllocations.length > 0) {
        studentsAllocations.push({
          student_id: student.id,
          enrollment_id: student.enrollment_id,
          months,
          club_items: clubItems,
          prior_allocations: priorAllocations,
        });
      }
    }

    try {
      const res = await collectFamilyPayment(family.id, {
        payment_date: paymentDate,
        method,
        reference: reference || null,
        notes: notes || null,
        students_allocations: studentsAllocations,
      });

      onSuccess(res.receipt);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'فشل تنفيذ الاستخلاص العائلي');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 sm:p-5 overflow-y-auto"
      dir="rtl"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="bg-white rounded-3xl w-full max-w-5xl max-h-[92vh] flex flex-col shadow-2xl border border-slate-100 overflow-hidden">
        {/* Top Header */}
        <div className="p-5 border-b flex items-center justify-between flex-wrap gap-3 bg-slate-50/70" style={{ borderColor: C.line }}>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl flex items-center justify-center shadow-xs" style={{ backgroundColor: C.sage }}>
              <Shield size={20} style={{ color: C.forest }} />
            </div>
            <div>
              <h2 className="text-lg font-bold" style={{ color: C.ink }}>
                الاستخلاص العائلي الموحد — {family.guardian_name}
              </h2>
              <p className="text-xs text-slate-500">
                هاتف الولي: {family.phone} • عدد الأبناء المسجلين: {family.students.length}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-200 transition"
          >
            <X size={20} />
          </button>
        </div>

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-5 space-y-6">
          {error && (
            <div className="p-4 rounded-2xl bg-red-50 text-red-700 text-xs sm:text-sm flex items-center gap-3 border border-red-200 shadow-xs">
              <AlertCircle size={20} className="shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {/* تنبيه غير حاجب للديون القديمة — معلوماتي فقط، لا يغيّر مبلغ الاستخلاص ولا يعطّله */}
          {oldDebts && oldDebts.count > 0 && (
            <div className="p-4 rounded-2xl bg-amber-50 text-amber-900 text-xs sm:text-sm border border-amber-300 shadow-xs">
              <div className="flex items-start gap-3">
                <AlertTriangle size={20} className="shrink-0 text-amber-600 mt-0.5" />
                <div className="space-y-1.5 flex-1">
                  <div className="font-bold">تنبيه: على بعض التلاميذ ديون قديمة.</div>
                  <div>
                    الإجمالي المتبقي: <span dir="ltr" className="font-bold">{money(oldDebts.total)} د.ت</span> • عدد التلاميذ: {oldDebts.count}
                  </div>
                  <ul className="space-y-0.5">
                    {(Object.values(oldDebts.students) as FamilyOldDebtStudent[]).map((s) => (
                      <li key={s.student_id}>
                        • {s.student_name}{s.student_code ? ` (${s.student_code})` : ''} — المتبقي: <span dir="ltr">{money(s.amount)} د.ت</span> ({s.debts_count} دين)
                      </li>
                    ))}
                  </ul>
                  <p className="text-amber-700">هذا التنبيه للمعلومات فقط ولا يُضاف إلى مبلغ الاستخلاص الحالي.</p>
                </div>
              </div>
            </div>
          )}

          {/* Children Sections Grid */}
          <div className="space-y-6">
            {family.students.map((student, idx) => {
              const studentSubtotal = calculateStudentSubtotal(student);
              const selectedMonths = selectedMonthsByStudent[student.id] || [];
              const selectedClubs = selectedClubsByStudent[student.id] || {};
              const selectedArrears = selectedArrearsByStudent[student.id] || {};

              return (
                <div
                  key={student.id}
                  className="rounded-2xl border border-slate-200 bg-white p-5 space-y-5 shadow-xs transition hover:border-slate-300"
                >
                  {/* Student Title Banner */}
                  <div className="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-slate-100">
                    <div className="flex items-center gap-3">
                      <span className="w-7 h-7 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold flex items-center justify-center">
                        {idx + 1}
                      </span>
                      <div>
                        <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                          {student.name || student.full_name}
                          {student.student_code && (
                            <span className="text-[11px] font-normal px-2 py-0.5 rounded-md bg-slate-100 text-slate-600">
                              {student.student_code}
                            </span>
                          )}
                        </h3>
                        <p className="text-xs text-slate-500">
                          المستوى: {student.level_name} • القسم: {student.section_name} • المعلوم الأساسي: {money(student.base_monthly_fee)} د.ت
                        </p>
                      </div>
                    </div>

                    <div className="flex items-center gap-2">
                      <span className="text-xs text-slate-500 font-medium">مستحقات مختارة:</span>
                      <span className="text-sm font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200">
                        {money(studentSubtotal)} د.ت
                      </span>
                    </div>
                  </div>

                  {/* 1. Tuition Months Grid (September -> June) */}
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                        <Calendar size={14} className="text-slate-400" />
                        معلوم الدراسة الشهري (من سبتمبر إلى جوان):
                      </span>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-5 md:grid-cols-10 gap-2">
                      {student.months_grid.map((m) => {
                        const isPaid = m.status === 'paid';
                        const isWaived = m.status === 'waived';
                        const isSelected = selectedMonths.includes(m.month);

                        let cardBg = 'bg-white border-slate-200 hover:border-slate-300';
                        let badgeBg = 'bg-slate-100 text-slate-600';
                        let badgeText = `${money(m.net_amount)} د.ت`;

                        if (isPaid) {
                          cardBg = 'bg-emerald-50/60 border-emerald-200 text-emerald-900 cursor-not-allowed';
                          badgeBg = 'bg-emerald-100 text-emerald-800';
                          badgeText = 'مدفوع ✓';
                        } else if (isWaived) {
                          cardBg = 'bg-slate-50 border-slate-200 text-slate-400 cursor-not-allowed';
                          badgeBg = 'bg-slate-200 text-slate-600';
                          badgeText = 'معفى';
                        } else if (isSelected) {
                          cardBg = 'bg-emerald-50 border-emerald-500 text-emerald-950 shadow-xs ring-1 ring-emerald-500';
                          badgeBg = 'bg-emerald-600 text-white font-bold';
                          badgeText = `${money(m.net_amount)} د.ت`;
                        }

                        return (
                          <div
                            key={m.month}
                            onClick={() => !isPaid && !isWaived && toggleStudentMonth(student, m.month)}
                            className={`p-2.5 rounded-xl border flex flex-col items-center justify-between text-center transition select-none ${cardBg} ${
                              !isPaid && !isWaived ? 'cursor-pointer hover:shadow-xs' : ''
                            }`}
                          >
                            <div className="flex items-center justify-between w-full mb-1">
                              <span className="text-xs font-bold">{m.name_ar}</span>
                              {!isPaid && !isWaived && (
                                isSelected ? (
                                  <CheckSquare size={14} className="text-emerald-600" />
                                ) : (
                                  <Square size={14} className="text-slate-300" />
                                )
                              )}
                            </div>
                            <span className={`text-[10px] px-1.5 py-0.5 rounded-md w-full truncate ${badgeBg}`}>
                              {badgeText}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {/* 2. Subscribed Clubs Grid (September -> May) */}
                  {student.clubs && student.clubs.length > 0 && (
                    <div className="space-y-2 pt-2 border-t border-slate-100">
                      <span className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                        <Award size={14} className="text-amber-500" />
                        اشتراكات النوادي (من سبتمبر إلى ماي):
                      </span>

                      <div className="space-y-2">
                        {student.clubs.map((club) => (
                          <div key={club.club_id} className="p-3 rounded-xl bg-amber-50/30 border border-amber-200/60 space-y-2">
                            <div className="flex items-center justify-between">
                              <span className="text-xs font-bold text-amber-950">
                                {club.club_name} ({money(club.monthly_fee)} د.ت / شهر)
                              </span>
                            </div>

                            <div className="grid grid-cols-3 sm:grid-cols-9 gap-1.5">
                              {club.months.map((cm) => {
                                const isClubPaid = cm.status === 'paid';
                                const isClubSelected = selectedClubs[cm.club_monthly_fee_id] !== undefined;

                                return (
                                  <div
                                    key={cm.month}
                                    onClick={() => !isClubPaid && toggleStudentClub(student.id, cm.club_monthly_fee_id, cm.amount_due)}
                                    className={`p-1.5 rounded-lg border text-center text-xs transition select-none ${
                                      isClubPaid
                                        ? 'bg-emerald-50 border-emerald-200 text-emerald-800 cursor-not-allowed'
                                        : isClubSelected
                                        ? 'bg-amber-100 border-amber-500 text-amber-950 font-bold shadow-xs cursor-pointer'
                                        : 'bg-white border-slate-200 hover:border-slate-300 cursor-pointer'
                                    }`}
                                  >
                                    <div className="text-[11px]">{cm.name_ar}</div>
                                    <div className="text-[10px] text-slate-500">
                                      {isClubPaid ? 'مدفوع ✓' : `${money(cm.amount_due)} د.ت`}
                                    </div>
                                  </div>
                                );
                              })}
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* 3. Arrears & Prior Debt Section */}
                  {student.arrears && student.arrears.length > 0 && (
                    <div className="space-y-2 pt-2 border-t border-slate-100">
                      <span className="text-xs font-bold text-amber-800 flex items-center gap-1.5">
                        <AlertCircle size={14} />
                        الديون والمتخلدات السابقة (مع إمكانية الدفع الجزئي):
                      </span>

                      <div className="space-y-2">
                        {student.arrears.map((arr) => {
                          const isSelected = selectedArrears[arr.student_fee_id] !== undefined;
                          const currentAmount = selectedArrears[arr.student_fee_id] || arr.remaining_amount;

                          return (
                            <div
                              key={arr.student_fee_id}
                              className={`p-3 rounded-xl border flex items-center justify-between flex-wrap gap-3 transition ${
                                isSelected ? 'bg-amber-50/80 border-amber-300' : 'bg-slate-50 border-slate-200'
                              }`}
                            >
                              <div className="flex items-center gap-3">
                                <button
                                  type="button"
                                  onClick={() => toggleStudentArrear(student.id, arr.student_fee_id, arr.remaining_amount)}
                                  className="text-slate-400 hover:text-slate-700"
                                >
                                  {isSelected ? (
                                    <CheckSquare size={18} className="text-amber-700" />
                                  ) : (
                                    <Square size={18} />
                                  )}
                                </button>
                                <div>
                                  <div className="text-xs font-bold text-slate-800">{arr.description}</div>
                                  <div className="text-[11px] text-slate-500">
                                    المستحق: {money(arr.amount_due)} د.ت • المقبوض: {money(arr.amount_paid)} د.ت • المتبقي: {money(arr.remaining_amount)} د.ت
                                  </div>
                                </div>
                              </div>

                              {isSelected && (
                                <div className="flex items-center gap-2">
                                  <label className="text-[11px] text-slate-600">المبلغ المدفوع:</label>
                                  <input
                                    type="number"
                                    min="1"
                                    max={arr.remaining_amount}
                                    step="0.01"
                                    value={currentAmount}
                                    onChange={(e) => updateArrearAmount(student.id, arr.student_fee_id, parseFloat(e.target.value) || 0, arr.remaining_amount)}
                                    className="w-24 px-2 py-1 rounded-lg border border-amber-300 text-xs text-center font-bold bg-white focus:outline-hidden focus:ring-1 focus:ring-amber-500"
                                  />
                                  <span className="text-xs text-slate-500">د.ت</span>
                                </div>
                              )}
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>

          {/* Payment Method Details Panel */}
          <div className="rounded-2xl border border-slate-200 bg-slate-50/50 p-4 space-y-4">
            <h4 className="text-xs font-bold text-slate-700 flex items-center gap-2">
              <DollarSign size={15} style={{ color: C.forest }} />
              بيانات الدفع والتحصيل
            </h4>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label className="block text-[11px] font-medium text-slate-600 mb-1">تاريخ الاستخلاص *</label>
                <input
                  type="date"
                  required
                  value={paymentDate}
                  onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                />
              </div>

              <div>
                <label className="block text-[11px] font-medium text-slate-600 mb-1">طريقة الدفع *</label>
                <select
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                >
                  <option value="cash">نقداً (Cash)</option>
                  <option value="check">شيك (Check)</option>
                  <option value="bank_transfer">تحويل بنكي (Bank Transfer)</option>
                  <option value="card">بطاقة بنكية (Card)</option>
                </select>
              </div>

              <div>
                <label className="block text-[11px] font-medium text-slate-600 mb-1">رقم المرجع / الشيك (اختياري)</label>
                <input
                  type="text"
                  placeholder="مثال: رقم الشيك أو رقم التحويل"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
                />
              </div>
            </div>

            <div>
              <label className="block text-[11px] font-medium text-slate-600 mb-1">ملاحظات إضافية</label>
              <input
                type="text"
                placeholder="أي ملاحظات حول العملية العائلية"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white focus:outline-hidden focus:ring-2 focus:ring-slate-300"
              />
            </div>
          </div>
        </form>

        {/* Sticky Bottom Summary Checkout Bar */}
        <div className="p-4 border-t border-slate-200 bg-white flex items-center justify-between flex-wrap gap-4 shadow-lg">
          <div className="flex items-center gap-3">
            <div className="text-right">
              <span className="block text-[11px] text-slate-500 font-medium">المبلغ الإجمالي للاستخلاص العائلي:</span>
              <span className="text-xl font-bold text-slate-900" style={{ color: C.forest }}>
                {money(grandTotal)} <span className="text-sm font-normal text-slate-500">دينار تونسي</span>
              </span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-100 transition"
            >
              إلغاء
            </button>

            <button
              type="button"
              onClick={handleSubmit}
              disabled={saving || grandTotal <= 0}
              className="px-6 py-2.5 rounded-xl text-xs font-bold text-white shadow-md flex items-center gap-2 transition disabled:opacity-50"
              style={{ backgroundColor: C.forest }}
            >
              {saving ? (
                <>
                  <Loader2 size={16} className="animate-spin" />
                  <span>جاري تسجيل الاستخلاص...</span>
                </>
              ) : (
                <>
                  <Sparkles size={16} />
                  <span>تأكيد الاستخلاص وطباعة الوصل ({money(grandTotal)} د.ت)</span>
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
