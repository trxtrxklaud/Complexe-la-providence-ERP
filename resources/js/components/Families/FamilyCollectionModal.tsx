import React, { useState } from 'react';
import {
  X, CheckSquare, Square, DollarSign, Loader2, AlertCircle, PlusCircle, Award, CheckCircle2
} from 'lucide-react';
import {
  type FamilyFullDetails,
  type UnpaidFeeItem,
  collectFamilyPayment
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

const MONTHS_LIST = [
  'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر', 'جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان'
];

export function FamilyCollectionModal({ family, onClose, onSuccess }: Props) {
  // القوائم الديناميكية المضافة (ترسيم / نادي)
  const [customFeeItems, setCustomFeeItems] = useState<Record<number, UnpaidFeeItem[]>>({});

  // حالة الاختيار والمبالغ: { [uniqueKey]: { selected: boolean, amount: number, student_id: number, fee_id: number, item: any } }
  interface SelectedItem {
    selected: boolean;
    amount: number;
    student_id: number;
    student_fee_id: number;
    gross: number;
    remaining: number;
    newItemData?: any;
  }
  const [selectedItems, setSelectedItems] = useState<Record<string, SelectedItem>>({});

  // حقول العملية المالية
  const [paymentDate, setPaymentDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [method, setMethod] = useState<string>('cash');
  const [reference, setReference] = useState<string>('');
  const [notes, setNotes] = useState<string>('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // حوار إضافة ترسيم
  const [showAddRegistrationModal, setShowAddRegistrationModal] = useState(false);
  const [selectedStudentForReg, setSelectedStudentForReg] = useState<number>(family.students[0]?.id || 0);
  const [regFeeAmount, setRegFeeAmount] = useState<number>(50);

  // حوار إضافة نادي
  const [showAddClubModal, setShowAddClubModal] = useState(false);
  const [selectedStudentForClub, setSelectedStudentForClub] = useState<number>(family.students[0]?.id || 0);
  const [selectedClubId, setSelectedClubId] = useState<number>(family.available_clubs?.[0]?.id || 0);
  const [selectedClubMonth, setSelectedClubMonth] = useState<string>('أكتوبر');

  // حساب التخصيصات المختارة
  const selectedList = (Object.values(selectedItems) as SelectedItem[])
    .filter((item) => item.selected && item.amount > 0)
    .map((item) => ({
      student_id: item.student_id,
      student_fee_id: item.student_fee_id,
      amount: item.amount,
      new_item: item.newItemData,
    }));

  const totalToPay = selectedList.reduce((sum, item) => sum + item.amount, 0);
  const hasAnyUnpaidOrCustomFee = family.students.some((st) => (st.unpaid_fees.length > 0 || (customFeeItems[st.id] || []).length > 0));

  // تبديل اختيار عنصر مفرد
  const toggleItem = (key: string, studentId: number, feeId: number, maxAmount: number, gross: number, newItemData?: any) => {
    setSelectedItems((prev) => {
      const current = prev[key];
      if (current?.selected) {
        const next = { ...prev };
        delete next[key];
        return next;
      }
      return {
        ...prev,
        [key]: {
          selected: true,
          amount: maxAmount,
          student_id: studentId,
          student_fee_id: feeId,
          gross,
          remaining: maxAmount,
          newItemData
        },
      };
    });
  };

  // تحديث مبلغ الاستخلاص الجزئي/الكامل
  const updateItemAmount = (key: string, newAmount: number, maxAmount: number) => {
    const validAmount = Math.max(0.01, Math.min(newAmount, maxAmount));
    setSelectedItems((prev) => ({
      ...prev,
      [key]: {
        ...(prev[key]),
        selected: true,
        amount: validAmount,
      },
    }));
  };

  // تبديل نوع الدفع كامل / جزئي
  const toggleFullOrPartial = (key: string, maxAmount: number) => {
    const current = selectedItems[key];
    if (!current) return;
    const isFull = Math.abs(current.amount - maxAmount) < 0.001;
    updateItemAmount(key, isFull ? Math.round((maxAmount / 2) * 100) / 100 : maxAmount, maxAmount);
  };

  // إضافة بند معلوم ترسيم ديناميكي
  const handleAddRegistrationFee = (e: React.FormEvent) => {
    e.preventDefault();
    const st = family.students.find((s) => s.id === Number(selectedStudentForReg));
    if (!st || !st.enrollment_id) return;

    const fakeId = -Date.now();
    const newFeeItem: UnpaidFeeItem = {
      id: fakeId,
      fee_type_id: 0,
      description: `معلوم الترسيم — ${st.name}`,
      gross_amount: regFeeAmount,
      discount_amount: 0,
      paid_amount: 0,
      remaining_amount: regFeeAmount,
      status: 'unpaid',
      is_new: true,
      item_type: 'registration',
    };

    setCustomFeeItems((prev) => ({
      ...prev,
      [st.id]: [...(prev[st.id] || []), newFeeItem],
    }));

    const key = `new_reg_${fakeId}`;
    toggleItem(key, st.id, 0, regFeeAmount, regFeeAmount, {
      student_id: st.id,
      enrollment_id: st.enrollment_id,
      type: 'registration',
      description: `معلوم الترسيم — ${st.name}`,
      amount_due: regFeeAmount,
    });

    setShowAddRegistrationModal(false);
  };

  // إضافة بند نادي ديناميكي
  const handleAddClubFee = (e: React.FormEvent) => {
    e.preventDefault();
    const st = family.students.find((s) => s.id === Number(selectedStudentForClub));
    const club = family.available_clubs?.find((c) => c.id === Number(selectedClubId));
    if (!st || !st.enrollment_id || !club) return;

    const fakeId = -Date.now();
    const feeAmount = Number(club.monthly_fee) || 30;
    const desc = `معلوم نادي ${club.name} (${selectedClubMonth}) — ${st.name}`;

    const newFeeItem: UnpaidFeeItem = {
      id: fakeId,
      fee_type_id: 0,
      description: desc,
      month_name: selectedClubMonth,
      gross_amount: feeAmount,
      discount_amount: 0,
      paid_amount: 0,
      remaining_amount: feeAmount,
      status: 'unpaid',
      is_new: true,
      item_type: 'club',
    };

    setCustomFeeItems((prev) => ({
      ...prev,
      [st.id]: [...(prev[st.id] || []), newFeeItem],
    }));

    const key = `new_club_${fakeId}`;
    toggleItem(key, st.id, 0, feeAmount, feeAmount, {
      student_id: st.id,
      enrollment_id: st.enrollment_id,
      club_id: club.id,
      type: 'club',
      description: desc,
      month_name: selectedClubMonth,
      amount_due: feeAmount,
    });

    setShowAddClubModal(false);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (selectedList.length === 0) {
      setError('لا توجد مستحقات غير مدفوعة لهذه العائلة (يرجى تحديد بند واحد على الأقل للتحصيل)');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const res = await collectFamilyPayment(family.id, {
        allocations: selectedList,
        payment_date: paymentDate,
        method,
        reference: reference || null,
        notes: notes || null,
      });

      onSuccess(res.receipt);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'فشل تنفيذ الاستخلاص الجماعي');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 no-print" dir="rtl">
      <div className="bg-white rounded-2xl w-full max-w-5xl max-h-[94vh] flex flex-col shadow-2xl overflow-hidden">
        {/* Modal Header Bar */}
        <div className="flex items-center justify-between p-4 border-b shrink-0" style={{ borderColor: C.line, backgroundColor: C.sage }}>
          <div>
            <h2 className="text-lg font-bold flex items-center gap-2" style={{ color: C.ink }}>
              <DollarSign size={20} style={{ color: C.forest }} />
              استخلاص جماعي للعائلة — {family.guardian_name}
            </h2>
            <p className="text-xs" style={{ color: C.muted }}>
              تحصيل موحد برقم وصل واحد لجميع أبناء العائلة ({family.students_count} أبناء) | الهاتف: {family.phone || '—'}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-2 rounded-xl hover:bg-black/5 transition"
            style={{ color: C.ink }}
          >
            <X size={18} />
          </button>
        </div>

        {error && (
          <div className="m-4 p-3.5 rounded-xl bg-red-50 text-red-700 text-sm flex items-center gap-2 border border-red-200 shrink-0">
            <AlertCircle size={18} /> {error}
          </div>
        )}

        {!hasAnyUnpaidOrCustomFee && (
          <div className="mx-5 mt-4 p-3.5 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs font-bold flex items-center gap-2 shrink-0">
            <CheckCircle2 size={18} className="text-emerald-600 shrink-0" />
            <span>لا توجد مستحقات غير مدفوعة لهذه العائلة — يمكنك إضافة معلوم ترسيم جديد أو نادي من الأزرار أعلاه إذا لزم الأمر.</span>
          </div>
        )}

        {/* Action Toolbar for Adding Fees */}
        <div className="px-5 py-3 border-b bg-slate-50 flex items-center justify-between flex-wrap gap-2 shrink-0" style={{ borderColor: C.line }}>
          <span className="text-xs font-bold text-slate-700">جدول استخلاص مستحقات العائلة:</span>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => setShowAddRegistrationModal(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-white border border-slate-300 text-slate-800 hover:bg-slate-100 transition shadow-sm"
            >
              <PlusCircle size={14} className="text-emerald-600" /> إضافة معلوم ترسيم
            </button>
            <button
              type="button"
              onClick={() => setShowAddClubModal(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-white border border-slate-300 text-slate-800 hover:bg-slate-100 transition shadow-sm"
            >
              <Award size={14} className="text-indigo-600" /> إضافة نادي
            </button>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="flex-1 overflow-auto p-5 space-y-6">
          {/* Main Grid Table */}
          <div className="overflow-x-auto border rounded-2xl shadow-sm" style={{ borderColor: C.line }}>
            <table className="w-full text-xs text-right border-collapse">
              <thead>
                <tr className="border-b text-slate-700 font-bold" style={{ backgroundColor: C.sage }}>
                  <th className="p-3 w-10 text-center">اختيار</th>
                  <th className="p-3">التلميذ</th>
                  <th className="p-3">القسم والمستوى</th>
                  <th className="p-3">نوع البند والتفاصيل</th>
                  <th className="p-3 text-center">المطلبوب</th>
                  <th className="p-3 text-center">التخفيض</th>
                  <th className="p-3 text-center">المدفوع سابقاً</th>
                  <th className="p-3 text-center">المتبقي</th>
                  <th className="p-3 text-center w-32">المبلغ المراد استخلاصه</th>
                  <th className="p-3 text-center w-24">نوع الدفع</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {family.students.map((st) => {
                  const allStudentFees = [
                    ...st.unpaid_fees,
                    ...(customFeeItems[st.id] || [])
                  ];

                  if (allStudentFees.length === 0) {
                    return (
                      <tr key={st.id} className="bg-emerald-50/40">
                        <td className="p-3 text-center text-slate-400">—</td>
                        <td className="p-3 font-bold">{st.name}</td>
                        <td className="p-3 text-slate-600">{st.section_name}</td>
                        <td colSpan={7} className="p-3 text-emerald-700 font-bold">
                          لا توجد مستحقات غير مدفوعة لهذا التلميذ (مستوفى بالكامل)
                        </td>
                      </tr>
                    );
                  }

                  return allStudentFees.map((fee) => {
                    const key = fee.is_new ? `new_${fee.item_type}_${fee.id}` : `fee_${fee.id}`;
                    const itemState = selectedItems[key];
                    const isSelected = Boolean(itemState?.selected);
                    const isFull = itemState ? Math.abs(itemState.amount - fee.remaining_amount) < 0.001 : true;

                    return (
                      <tr
                        key={key}
                        className={`transition ${isSelected ? 'bg-emerald-50/70 font-semibold' : 'hover:bg-slate-50'}`}
                      >
                        <td className="p-3 text-center">
                          <button
                            type="button"
                            onClick={() => toggleItem(key, st.id, fee.id > 0 ? fee.id : 0, fee.remaining_amount, fee.gross_amount, (fee as any).newItemData)}
                            className="p-1 rounded hover:bg-slate-200 transition"
                          >
                            {isSelected ? (
                              <CheckSquare size={18} className="text-emerald-700" />
                            ) : (
                              <Square size={18} className="text-slate-400" />
                            )}
                          </button>
                        </td>

                        <td className="p-3 font-bold" style={{ color: C.ink }}>
                          {st.name}
                        </td>

                        <td className="p-3 text-slate-600">
                          {st.section_name}
                        </td>

                        <td className="p-3">
                          <div className="font-bold text-slate-800">{fee.description}</div>
                          {fee.month_name && (
                            <span className="text-[10px] text-slate-500">شهر: {fee.month_name}</span>
                          )}
                        </td>

                        <td className="p-3 text-center font-mono" dir="ltr">
                          {money(fee.gross_amount)}
                        </td>

                        <td className="p-3 text-center font-mono text-emerald-600" dir="ltr">
                          {money(fee.discount_amount)}
                        </td>

                        <td className="p-3 text-center font-mono text-slate-600" dir="ltr">
                          {money(fee.paid_amount)}
                        </td>

                        <td className="p-3 text-center font-mono font-bold text-red-600" dir="ltr">
                          {money(fee.remaining_amount)}
                        </td>

                        <td className="p-3 text-center">
                          {isSelected ? (
                            <input
                              type="number"
                              step="0.001"
                              min="0.01"
                              max={fee.remaining_amount}
                              value={itemState?.amount ?? fee.remaining_amount}
                              onChange={(e) => updateItemAmount(key, parseFloat(e.target.value) || 0, fee.remaining_amount)}
                              className="w-full text-xs font-bold px-2 py-1 border rounded-lg bg-white text-left font-mono shadow-inner"
                              style={{ borderColor: C.forest }}
                            />
                          ) : (
                            <span className="text-slate-400 font-mono">—</span>
                          )}
                        </td>

                        <td className="p-3 text-center">
                          {isSelected && (
                            <button
                              type="button"
                              onClick={() => toggleFullOrPartial(key, fee.remaining_amount)}
                              className={`px-2 py-0.5 rounded text-[10px] font-bold transition border ${
                                isFull
                                  ? 'bg-emerald-600 text-white border-emerald-600'
                                  : 'bg-amber-100 text-amber-800 border-amber-300'
                              }`}
                            >
                              {isFull ? 'كامل' : 'جزئي'}
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  });
                })}
              </tbody>
            </table>
          </div>

          {/* Operational Settings Bar */}
          <div className="border-t pt-4 space-y-3" style={{ borderColor: C.line }}>
            <h3 className="text-xs font-bold text-slate-700">بيانات العملية والوصل:</h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">تاريخ الاستخلاص</label>
                <input
                  type="date"
                  value={paymentDate}
                  onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full px-3 py-2 text-xs border rounded-xl"
                  style={{ borderColor: C.line }}
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">طريقة الدفع</label>
                <select
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                  className="w-full px-3 py-2 text-xs border rounded-xl bg-white"
                  style={{ borderColor: C.line }}
                >
                  <option value="cash">نقداً</option>
                  <option value="bank_transfer">تحويل بنكي</option>
                  <option value="check">شيك</option>
                  <option value="card">بطاقة</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 mb-1">المرجع (اختياري)</label>
                <input
                  type="text"
                  placeholder="رقم الشيك أو التحويل"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                  className="w-full px-3 py-2 text-xs border rounded-xl"
                  style={{ borderColor: C.line }}
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">ملاحظات العملية (اختياري)</label>
              <input
                type="text"
                placeholder="ملاحظات تشغيلية حول التحصيل الجماعي"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="w-full px-3 py-2 text-xs border rounded-xl"
                style={{ borderColor: C.line }}
              />
            </div>
          </div>

          {/* Modal Action Bar */}
          <div className="p-4 rounded-2xl bg-slate-900 text-white flex items-center justify-between flex-wrap gap-3">
            <div>
              <p className="text-xs text-slate-400">إجمالي المبلغ المراد استخلاصه بالجماعة:</p>
              <p className="text-2xl font-extrabold text-emerald-400" dir="ltr">
                {money(totalToPay)} د.ت
              </p>
            </div>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2.5 rounded-xl border border-slate-700 text-xs font-semibold hover:bg-slate-800 transition"
              >
                إلغاء
              </button>
              <button
                type="submit"
                disabled={saving || selectedList.length === 0}
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition shadow-lg disabled:opacity-50"
              >
                {saving ? (
                  <>
                    <Loader2 size={16} className="animate-spin" /> جاري الحفظ...
                  </>
                ) : (
                  <>
                    <DollarSign size={16} /> تأكيد الاستخلاص الجماعي وإصدار الوصل
                  </>
                )}
              </button>
            </div>
          </div>
        </form>
      </div>

      {/* Dialog: إضافة معلوم ترسيم */}
      {showAddRegistrationModal && (
        <div className="fixed inset-0 z-60 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white p-5 rounded-2xl max-w-md w-full space-y-4 shadow-xl">
            <h3 className="text-base font-bold text-slate-800">إضافة معلوم ترسيم لتلميذ</h3>
            <form onSubmit={handleAddRegistrationFee} className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">اختر التلميذ</label>
                <select
                  value={selectedStudentForReg}
                  onChange={(e) => setSelectedStudentForReg(Number(e.target.value))}
                  className="w-full p-2 text-xs border rounded-xl"
                >
                  {family.students.map((s) => (
                    <option key={s.id} value={s.id}>{s.name} ({s.section_name})</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">معلوم الترسيم (د.ت)</label>
                <input
                  type="number"
                  step="0.001"
                  value={regFeeAmount}
                  onChange={(e) => setRegFeeAmount(parseFloat(e.target.value) || 0)}
                  className="w-full p-2 text-xs border rounded-xl font-mono"
                  required
                />
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setShowAddRegistrationModal(false)}
                  className="px-3 py-1.5 text-xs rounded-xl border"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 text-xs rounded-xl bg-emerald-600 text-white font-bold"
                >
                  إضافة للجدول
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Dialog: إضافة نادي */}
      {showAddClubModal && (
        <div className="fixed inset-0 z-60 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white p-5 rounded-2xl max-w-md w-full space-y-4 shadow-xl">
            <h3 className="text-base font-bold text-slate-800">إضافة معلوم نادي لتلميذ</h3>
            <form onSubmit={handleAddClubFee} className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">اختر التلميذ</label>
                <select
                  value={selectedStudentForClub}
                  onChange={(e) => setSelectedStudentForClub(Number(e.target.value))}
                  className="w-full p-2 text-xs border rounded-xl"
                >
                  {family.students.map((s) => (
                    <option key={s.id} value={s.id}>{s.name} ({s.section_name})</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">اختر النادي</label>
                <select
                  value={selectedClubId}
                  onChange={(e) => setSelectedClubId(Number(e.target.value))}
                  className="w-full p-2 text-xs border rounded-xl"
                >
                  {(family.available_clubs || []).map((c) => (
                    <option key={c.id} value={c.id}>{c.name} ({c.monthly_fee} د.ت/شهر)</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-slate-600 mb-1">اختر الشهر المعني</label>
                <select
                  value={selectedClubMonth}
                  onChange={(e) => setSelectedClubMonth(e.target.value)}
                  className="w-full p-2 text-xs border rounded-xl"
                >
                  {MONTHS_LIST.map((m) => (
                    <option key={m} value={m}>{m}</option>
                  ))}
                </select>
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setShowAddClubModal(false)}
                  className="px-3 py-1.5 text-xs rounded-xl border"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 text-xs rounded-xl bg-indigo-600 text-white font-bold"
                >
                  إضافة للجدول
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
