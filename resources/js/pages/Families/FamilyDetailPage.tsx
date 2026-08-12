import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowRight, UserCheck, Phone, MapPin, DollarSign, AlertCircle, Loader2, CheckCircle2, PlusCircle } from 'lucide-react';
import { fetchFamilyDetails, type FamilyFullDetails } from '../../api/families';
import { FamilyCollectionModal } from '../../components/Families/FamilyCollectionModal';
import { AddFeeItemModal } from '../../components/Families/AddFeeItemModal';
import { ReceiptModal, type ReceiptData } from '../Payments/ReceiptModal';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };

function money(v: number): string {
  return (v || 0).toFixed(2);
}

export function FamilyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [family, setFamily] = useState<FamilyFullDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showCollectModal, setShowCollectModal] = useState(false);
  const [showAddFeeModal, setShowAddFeeModal] = useState(false);
  const [activeReceipt, setActiveReceipt] = useState<ReceiptData | null>(null);

  const loadDetails = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(null);
    try {
      const data = await fetchFamilyDetails(id);
      setFamily(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'تعذّر تحميل بيانات العائلة');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    loadDetails();
  }, [loadDetails]);

  const handleCollectionSuccess = (receipt: ReceiptData) => {
    setShowCollectModal(false);
    setShowAddFeeModal(false);
    setActiveReceipt(receipt);
    loadDetails(); // إعادة تحميل بيانات العائلة والأرصدة
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <Loader2 className="animate-spin text-slate-400" size={32} />
      </div>
    );
  }

  if (error || !family) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4" dir="rtl">
        <button
          type="button"
          onClick={() => navigate('/families')}
          className="flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-slate-900"
        >
          <ArrowRight size={16} /> العودة لقائمة العائلات
        </button>
        <div className="p-4 rounded-xl bg-red-50 text-red-700 text-sm flex items-center gap-2 border border-red-200">
          <AlertCircle size={18} /> {error || 'العائلة غير موجودة'}
        </div>
      </div>
    );
  }

  const totalUnpaidItemsCount = family.students.reduce((acc, st) => acc + (st.unpaid_fees?.length || 0), 0);
  const hasUnpaidFees = family.family_remaining_debt > 0 || totalUnpaidItemsCount > 0;

  return (
    <div dir="rtl" className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Top Header & Breadcrumb */}
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => navigate('/families')}
            className="p-2.5 rounded-xl border border-slate-200 hover:bg-slate-100 transition"
            title="العودة"
          >
            <ArrowRight size={18} />
          </button>
          <div>
            <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
              ملف عائلة {family.guardian_name}
            </h1>
            <p className="text-xs" style={{ color: C.muted }}>
              بيانات الولي، الأبناء المسجلين، والخدمات المستحقة
            </p>
          </div>
        </div>

        {/* Action Buttons based on Family Status */}
        {hasUnpaidFees ? (
          /* الحالة 1: العائلة لديها مستحقات غير مدفوعة -> زر استخلاص جماعي */
          <button
            type="button"
            onClick={() => setShowCollectModal(true)}
            className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold text-white shadow-lg transition hover:bg-emerald-800"
            style={{ backgroundColor: C.forest }}
          >
            <DollarSign size={16} /> استخلاص جماعي للأبناء
          </button>
        ) : (
          /* الحالة 2: العائلة ليس لديها مستحقات -> زر منفصل صريح لإضافة بند جديد */
          <div className="flex items-center gap-3 flex-wrap">
            <div className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs font-bold shadow-sm">
              <CheckCircle2 size={16} className="text-emerald-600 shrink-0" />
              <span>لا توجد مستحقات غير مدفوعة</span>
            </div>

            <button
              type="button"
              onClick={() => setShowAddFeeModal(true)}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-700 hover:bg-indigo-600 shadow-md transition"
            >
              <PlusCircle size={16} /> إضافة بند جديد (ترسيم / نادي)
            </button>
          </div>
        )}
      </div>

      {/* Fully Paid Notice Banner */}
      {!hasUnpaidFees && (
        <div className="p-4 rounded-2xl bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-center justify-between flex-wrap gap-3 text-xs sm:text-sm font-bold shadow-sm">
          <div className="flex items-center gap-3">
            <CheckCircle2 size={22} className="text-emerald-600 shrink-0" />
            <div>
              <p className="text-sm font-extrabold text-emerald-900">جميع مستحقات العائلة مدفوعة بالكامل</p>
              <p className="text-xs text-emerald-700 font-medium mt-0.5">
                لا توجد مستحقات غير مدفوعة لهذه العائلة (المتبقي بالذمة: 0.00 د.ت). يمكنك إضافة بند جديد (ترسيم أو نادي) بالضغط على الزر المخصص.
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => setShowAddFeeModal(true)}
            className="px-3.5 py-1.5 rounded-xl bg-emerald-700 text-white text-xs font-bold hover:bg-emerald-800 transition shrink-0"
          >
            + إضافة بند جديد
          </button>
        </div>
      )}

      {/* Guardian & Financial Summary Card */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="bg-white p-5 rounded-2xl border shadow-sm space-y-2 md:col-span-2" style={{ borderColor: C.line }}>
          <h2 className="text-base font-bold flex items-center gap-2" style={{ color: C.ink }}>
            <UserCheck size={18} style={{ color: C.forest }} /> بيانات الولي
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs pt-2">
            <div>
              <span className="text-slate-500 block">اسم ولقب الولي:</span>
              <span className="font-bold text-sm" style={{ color: C.ink }}>{family.guardian_name}</span>
            </div>
            <div>
              <span className="text-slate-500 block">رقم الهاتف الأساسي:</span>
              <span className="font-mono font-bold flex items-center gap-1" dir="ltr">
                <Phone size={13} className="text-slate-400" /> {family.phone || '—'}
              </span>
            </div>
            {family.mother_phone && (
              <div>
                <span className="text-slate-500 block">رقم هاتف الأم:</span>
                <span className="font-mono font-bold" dir="ltr">{family.mother_phone}</span>
              </div>
            )}
            <div>
              <span className="text-slate-500 block">العنوان:</span>
              <span className="font-medium flex items-center gap-1">
                <MapPin size={13} className="text-slate-400" /> {family.address || '—'}
              </span>
            </div>
          </div>
        </div>

        {/* Financial Totals Widget */}
        <div className="bg-slate-900 text-white p-5 rounded-2xl shadow-md flex flex-col justify-between">
          <div>
            <span className="text-xs text-slate-400">إجمالي المتبقي بالذمة للعائلة:</span>
            <p className={`text-2xl font-extrabold mt-1 ${hasUnpaidFees ? 'text-red-400' : 'text-emerald-400'}`} dir="ltr">
              {money(family.family_remaining_debt)} د.ت
            </p>
          </div>
          <div className="pt-3 border-t border-slate-800 text-xs flex justify-between text-slate-300">
            <span>المطلوب: {money(family.family_total_due)} د.ت</span>
            <span>المدفوع: {money(family.family_total_paid)} د.ت</span>
          </div>
        </div>
      </div>

      {/* Children List */}
      <div className="space-y-4">
        <h2 className="text-lg font-bold" style={{ color: C.forest }}>
          أبناء العائلة المسجلين ({family.students_count})
        </h2>

        <div className="grid grid-cols-1 gap-4">
          {family.students.map((st) => {
            const hasUnpaid = st.unpaid_fees.length > 0;

            return (
              <div key={st.id} className="bg-white p-5 rounded-2xl border shadow-sm space-y-4" style={{ borderColor: C.line }}>
                <div className="flex items-center justify-between flex-wrap gap-2 pb-3 border-b" style={{ borderColor: C.line }}>
                  <div>
                    <h3 className="text-base font-bold" style={{ color: C.ink }}>
                      {st.name} <span className="text-xs font-mono text-slate-400">({st.student_code})</span>
                    </h3>
                    <p className="text-xs text-slate-500">
                      القسم: <b>{st.section_name}</b> | السنة الدراسية: {st.academic_year}
                    </p>
                  </div>
                  <div className="text-left">
                    <span className="text-xs text-slate-500 block">المتبقي بالذمة للابن:</span>
                    <span className={`text-sm font-bold ${hasUnpaid ? 'text-red-600' : 'text-emerald-600'}`} dir="ltr">
                      {money(st.remaining_debt)} د.ت
                    </span>
                  </div>
                </div>

                {/* Unpaid Fees List */}
                <div>
                  <h4 className="text-xs font-bold mb-2 text-slate-700">البنود المستحقة وغير المدفوعة بالكامل:</h4>
                  {!hasUnpaid ? (
                    <p className="text-xs text-emerald-600 font-semibold bg-emerald-50 p-2.5 rounded-xl border border-emerald-100 flex items-center gap-2">
                      <CheckCircle2 size={16} /> جميع المستحقات لهذا الابن مدفوعة بالكامل.
                    </p>
                  ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5">
                      {st.unpaid_fees.map((fee) => (
                        <div key={fee.id} className="p-3 rounded-xl border bg-slate-50/70 text-xs space-y-1" style={{ borderColor: C.line }}>
                          <p className="font-bold text-slate-800">{fee.description}</p>
                          <div className="flex justify-between text-slate-600 text-[11px]">
                            <span>المطلوب: {money(fee.gross_amount)}</span>
                            <span>المدفوع: {money(fee.paid_amount)}</span>
                          </div>
                          <div className="flex justify-between font-bold pt-1 border-t border-slate-200">
                            <span className="text-slate-700">المتبقي:</span>
                            <span className="text-red-600" dir="ltr">{money(fee.remaining_amount)} د.ت</span>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Mode 1: Collective Collection Modal for Unpaid Fees */}
      {showCollectModal && (
        <FamilyCollectionModal
          family={family}
          onClose={() => setShowCollectModal(false)}
          onSuccess={handleCollectionSuccess}
        />
      )}

      {/* Mode 2: Dedicated Add Fee Item Modal for Fully Paid Family */}
      {showAddFeeModal && (
        <AddFeeItemModal
          family={family}
          onClose={() => setShowAddFeeModal(false)}
          onSuccess={handleCollectionSuccess}
        />
      )}

      {/* Receipt Modal Output */}
      {activeReceipt && (
        <ReceiptModal
          receipt={activeReceipt}
          onClose={() => setActiveReceipt(null)}
        />
      )}
    </div>
  );
}

export default FamilyDetailPage;
