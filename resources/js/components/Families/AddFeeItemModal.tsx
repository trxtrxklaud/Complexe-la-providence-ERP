import React, { useState } from 'react';
import { X, PlusCircle, Award, DollarSign, Loader2, AlertCircle } from 'lucide-react';
import {
  type FamilyFullDetails,
  collectFamilyPayment
} from '../../api/families';
import type { ReceiptData } from '../../pages/Payments/ReceiptModal';
import { money } from '../../lib/format';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };

interface Props {
  family: FamilyFullDetails;
  onClose: () => void;
  onSuccess: (receipt: ReceiptData) => void;
}

const MONTHS_LIST = [
  'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر', 'جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان'
];

export function AddFeeItemModal({ family, onClose, onSuccess }: Props) {
  const [selectedStudentId, setSelectedStudentId] = useState<number>(family.students[0]?.id || 0);
  const [itemType, setItemType] = useState<'registration' | 'club'>('registration');

  // Registration states
  const [regAmount, setRegAmount] = useState<number>(50);

  // Club states
  const [selectedClubId, setSelectedClubId] = useState<number>(family.available_clubs?.[0]?.id || 0);
  const [selectedClubMonth, setSelectedClubMonth] = useState<string>('أكتوبر');
  const [clubAmount, setClubAmount] = useState<number>(
    family.available_clubs?.[0]?.monthly_fee || 30
  );

  // Payment states
  const [paymentDate, setPaymentDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [method, setMethod] = useState<string>('cash');
  const [reference, setReference] = useState<string>('');
  const [notes, setNotes] = useState<string>('');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const selectedStudent = family.students.find((s) => s.id === Number(selectedStudentId));

  const handleClubChange = (clubId: number) => {
    setSelectedClubId(clubId);
    const club = family.available_clubs?.find((c) => c.id === clubId);
    if (club) {
      setClubAmount(Number(club.monthly_fee) || 30);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedStudent || !selectedStudent.enrollment_id) {
      setError('التلميذ المحدد غير مسجل بالسنة الحالية');
      return;
    }

    setSaving(true);
    setError(null);

    let newItemPayload: any = null;
    let payAmount = 0;

    if (itemType === 'registration') {
      payAmount = regAmount;
      newItemPayload = {
        student_id: selectedStudent.id,
        enrollment_id: selectedStudent.enrollment_id,
        type: 'registration',
        description: `معلوم الترسيم — ${selectedStudent.name}`,
        amount_due: regAmount,
      };
    } else {
      const club = family.available_clubs?.find((c) => c.id === Number(selectedClubId));
      payAmount = clubAmount;
      newItemPayload = {
        student_id: selectedStudent.id,
        enrollment_id: selectedStudent.enrollment_id,
        club_id: selectedClubId,
        type: 'club',
        description: `معلوم نادي ${club?.name || ''} (${selectedClubMonth}) — ${selectedStudent.name}`,
        month_name: selectedClubMonth,
        amount_due: clubAmount,
      };
    }

    if (payAmount <= 0) {
      setError('مبلغ البند يجب أن يكون أكبر من صفر');
      setSaving(false);
      return;
    }

    try {
      const res = await collectFamilyPayment(family.id, {
        allocations: [
          {
            student_id: selectedStudent.id,
            student_fee_id: 0,
            amount: payAmount,
            new_item: newItemPayload,
          },
        ],
        payment_date: paymentDate,
        method,
        reference: reference || null,
        notes: notes ? `إضافة بند جديد - ${notes}` : 'إضافة بند جديد واستخلاصه',
      });

      onSuccess(res.receipt);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'فشل إضافة البند الجديد');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 no-print" dir="rtl">
      <div className="bg-white rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b shrink-0 bg-indigo-900 text-white">
          <div>
            <h2 className="text-base font-bold flex items-center gap-2">
              <PlusCircle size={18} className="text-indigo-300" />
              إضافة بند جديد — عائلة {family.guardian_name}
            </h2>
            <p className="text-xs text-indigo-200">
              إنشاء واستخلاص معلوم ترسيم جديد أو اشتراك نادي لتلميذ في هذه العائلة
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-xl hover:bg-white/10 transition text-white"
          >
            <X size={18} />
          </button>
        </div>

        {error && (
          <div className="m-4 p-3 rounded-xl bg-red-50 text-red-700 text-xs flex items-center gap-2 border border-red-200 shrink-0">
            <AlertCircle size={16} /> {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="p-5 space-y-4 text-xs overflow-auto">
          {/* Select Student */}
          <div>
            <label className="block font-bold text-slate-700 mb-1">اختر التلميذ المعني</label>
            <select
              value={selectedStudentId}
              onChange={(e) => setSelectedStudentId(Number(e.target.value))}
              className="w-full p-2.5 text-xs border rounded-xl bg-slate-50 font-medium"
            >
              {family.students.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.section_name})
                </option>
              ))}
            </select>
          </div>

          {/* Type Selector Toggle */}
          <div>
            <label className="block font-bold text-slate-700 mb-1.5">نوع البند الجديد</label>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => setItemType('registration')}
                className={`flex items-center justify-center gap-2 p-2.5 rounded-xl border text-xs font-bold transition ${
                  itemType === 'registration'
                    ? 'bg-emerald-50 border-emerald-600 text-emerald-900 shadow-sm'
                    : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                }`}
              >
                <PlusCircle size={15} className="text-emerald-600" /> معلوم ترسيم
              </button>

              <button
                type="button"
                onClick={() => setItemType('club')}
                className={`flex items-center justify-center gap-2 p-2.5 rounded-xl border text-xs font-bold transition ${
                  itemType === 'club'
                    ? 'bg-indigo-50 border-indigo-600 text-indigo-900 shadow-sm'
                    : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                }`}
              >
                <Award size={15} className="text-indigo-600" /> معلوم نادي
              </button>
            </div>
          </div>

          {/* Type Specific Fields */}
          {itemType === 'registration' ? (
            <div className="p-3.5 rounded-xl bg-slate-50 border space-y-2 border-slate-200">
              <label className="block font-semibold text-slate-700">قيمة معلوم الترسيم (د.ت)</label>
              <input
                type="number"
                step="0.001"
                min="1"
                value={regAmount}
                onChange={(e) => setRegAmount(parseFloat(e.target.value) || 0)}
                className="w-full p-2 border rounded-lg bg-white font-mono font-bold text-slate-900"
                required
              />
            </div>
          ) : (
            <div className="p-3.5 rounded-xl bg-slate-50 border space-y-3 border-slate-200">
              <div>
                <label className="block font-semibold text-slate-700 mb-1">اختر النادي</label>
                <select
                  value={selectedClubId}
                  onChange={(e) => handleClubChange(Number(e.target.value))}
                  className="w-full p-2 border rounded-lg bg-white"
                >
                  {(family.available_clubs || []).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({money(c.monthly_fee)} د.ت/شهر)
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block font-semibold text-slate-700 mb-1">اختر الشهر المعني</label>
                <select
                  value={selectedClubMonth}
                  onChange={(e) => setSelectedClubMonth(e.target.value)}
                  className="w-full p-2 border rounded-lg bg-white"
                >
                  {MONTHS_LIST.map((m) => (
                    <option key={m} value={m}>
                      {m}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block font-semibold text-slate-700 mb-1">مبلغ معلوم النادي (د.ت)</label>
                <input
                  type="number"
                  step="0.001"
                  min="1"
                  value={clubAmount}
                  onChange={(e) => setClubAmount(parseFloat(e.target.value) || 0)}
                  className="w-full p-2 border rounded-lg bg-white font-mono font-bold text-slate-900"
                  required
                />
              </div>
            </div>
          )}

          {/* Operational Settings */}
          <div className="space-y-2 pt-2 border-t border-slate-200">
            <h4 className="font-bold text-slate-700">بيانات استخلاص البند:</h4>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="block text-slate-600 mb-1">تاريخ الاستخلاص</label>
                <input
                  type="date"
                  value={paymentDate}
                  onChange={(e) => setPaymentDate(e.target.value)}
                  className="w-full p-2 border rounded-lg"
                  required
                />
              </div>
              <div>
                <label className="block text-slate-600 mb-1">طريقة الدفع</label>
                <select
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                  className="w-full p-2 border rounded-lg bg-white"
                >
                  <option value="cash">نقداً</option>
                  <option value="bank_transfer">تحويل بنكي</option>
                  <option value="check">شيك</option>
                  <option value="card">بطاقة</option>
                </select>
              </div>
            </div>
          </div>

          {/* Action Bar */}
          <div className="flex justify-end gap-2 pt-3 border-t">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-xl border text-xs font-semibold hover:bg-slate-100"
            >
              إلغاء
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-1.5 px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold transition shadow-md disabled:opacity-50"
            >
              {saving ? (
                <>
                  <Loader2 size={15} className="animate-spin" /> جاري الحفظ...
                </>
              ) : (
                <>
                  <DollarSign size={15} /> إضافة البند وإصدار الوصل
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
