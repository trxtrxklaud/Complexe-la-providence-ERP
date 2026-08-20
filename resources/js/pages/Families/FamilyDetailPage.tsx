import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import {
  ArrowRight, UserCheck, Phone, MapPin, DollarSign, AlertCircle, Loader2,
  CheckCircle2, PlusCircle, Calendar, Award, Shield
} from 'lucide-react';
import { fetchFamilyDetails, type FamilyFullDetails } from '../../api/families';
import { paymentsApi } from '../../api/payments';
import { FamilyCollectionModal } from '../../components/Families/FamilyCollectionModal';
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
    setActiveReceipt(receipt);
    loadDetails();
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

  const remainingDebt = Number(family.remaining_debt ?? family.family_remaining_debt ?? 0);
  const totalDue = Number(family.total_due ?? family.family_total_due ?? 0);
  const totalPaid = Number(family.total_paid ?? family.family_total_paid ?? 0);
  const hasRemainingDebt = remainingDebt > 0;

  async function handleCancelPayment(): Promise<void> {
    if (!activeReceipt) return;
    const reason = window.prompt('سبب إلغاء المقبوض (إلزامي):');
    if (reason === null) return;
    if (reason.trim().length < 3) {
      alert('يرجى كتابة سبب الإلغاء (3 أحرف على الأقل)');
      return;
    }
    try {
      // الوصل العائلي: إلغاء كل دفعات الأبناء
      if (activeReceipt.is_family_receipt && activeReceipt.siblings && activeReceipt.siblings.length > 0) {
        const paymentIds = activeReceipt.siblings
          .map((s) => s.payment_id)
          .filter((id): id is number => typeof id === 'number');
        for (const pid of paymentIds) {
          await paymentsApi.cancel(pid, reason.trim());
        }
      } else if (activeReceipt.payment_id) {
        // وصل فردي: إلغاء دفعة واحدة
        await paymentsApi.cancel(Number(activeReceipt.payment_id), reason.trim());
      }
      setActiveReceipt(null);
      await loadDetails();
      alert('تم إلغاء المقبوض بنجاح وتحديث الحساب.');
    } catch (e: unknown) {
      alert((e as Error).message || 'تعذر إلغاء المقبوض');
    }
  }

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
              بيانات الولي، الأبناء المسجلين، والاستخلاص العائلي
            </p>
          </div>
        </div>

        {/* Action Button */}
        <button
          type="button"
          onClick={() => setShowCollectModal(true)}
          className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold text-white shadow-lg transition hover:bg-emerald-800"
          style={{ backgroundColor: C.forest }}
        >
          <DollarSign size={16} /> استخلاص العائلة الموحد
        </button>
      </div>

      {/* Guardian & Financial Summary Card */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="bg-white p-5 rounded-2xl border shadow-xs space-y-2 md:col-span-2" style={{ borderColor: C.line }}>
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
            {family.mother_name && (
              <div>
                <span className="text-slate-500 block">اسم الأم:</span>
                <span className="font-bold">{family.mother_name}</span>
              </div>
            )}
            {family.mother_phone && (
              <div>
                <span className="text-slate-500 block">رقم هاتف الأم:</span>
                <span className="font-mono font-bold flex items-center gap-1" dir="ltr">
                  <Phone size={13} className="text-slate-400" /> {family.mother_phone}
                </span>
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
            <p className={`text-2xl font-extrabold mt-1 ${hasRemainingDebt ? 'text-red-400' : 'text-emerald-400'}`} dir="ltr">
              {money(remainingDebt)} د.ت
            </p>
          </div>
          <div className="pt-3 border-t border-slate-800 text-xs flex justify-between text-slate-300">
            <span>المطلوب: {money(totalDue)} د.ت</span>
            <span>المدفوع: {money(totalPaid)} د.ت</span>
          </div>
        </div>
      </div>

      {/* Children List Grid */}
      <div className="space-y-4">
        <h2 className="text-lg font-bold" style={{ color: C.forest }}>
          أبناء العائلة المسجلين ({family.students_count})
        </h2>

        <div className="grid grid-cols-1 gap-5">
          {family.students.map((st) => {
            const hasStudentDebt = Number(st.remaining_debt || 0) > 0;

            return (
              <div key={st.id} className="bg-white p-5 rounded-2xl border shadow-xs space-y-4" style={{ borderColor: C.line }}>
                {/* Child Header */}
                <div className="flex items-center justify-between flex-wrap gap-2 pb-3 border-b" style={{ borderColor: C.line }}>
                  <div>
                    <h3 className="text-base font-bold" style={{ color: C.ink }}>
                      {st.full_name || st.name} {st.student_code && <span className="text-xs font-mono text-slate-400">({st.student_code})</span>}
                    </h3>
                    <p className="text-xs text-slate-500">
                      المستوى: <b>{st.level_name}</b> | القسم: <b>{st.section_name}</b> | المعلوم الأساسي: <b>{money(st.base_monthly_fee)} د.ت</b>
                    </p>
                  </div>
                  <div className="text-left">
                    <span className="text-xs text-slate-500 block">المتبقي بالذمة:</span>
                    <span className={`text-sm font-bold ${hasStudentDebt ? 'text-red-600' : 'text-emerald-600'}`} dir="ltr">
                      {money(st.remaining_debt)} د.ت
                    </span>
                  </div>
                </div>

                {/* 10-Month Grid Preview */}
                <div className="space-y-1.5">
                  <span className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                    <Calendar size={13} className="text-slate-400" />
                    معاليم الأشهر الدراسية (سبتمبر - جوان):
                  </span>

                  <div className="grid grid-cols-2 sm:grid-cols-5 md:grid-cols-10 gap-2">
                    {(st.months_grid || []).map((m) => {
                      const isPaid = m.status === 'paid';
                      const isWaived = m.status === 'waived';

                      return (
                        <div
                          key={m.month}
                          className={`p-2 rounded-xl border text-center text-xs flex flex-col items-center justify-between ${
                            isPaid
                              ? 'bg-emerald-50 border-emerald-200 text-emerald-900 font-bold'
                              : isWaived
                              ? 'bg-slate-50 border-slate-200 text-slate-400'
                              : 'bg-white border-slate-200 text-slate-700'
                          }`}
                        >
                          <span className="text-[11px] font-bold">{m.name_ar}</span>
                          <span
                            className={`text-[10px] mt-1 px-1.5 py-0.5 rounded-md ${
                              isPaid ? 'bg-emerald-100 text-emerald-800' : isWaived ? 'bg-slate-200 text-slate-600' : 'bg-slate-100 text-slate-600'
                            }`}
                          >
                            {isPaid ? 'مدفوع ✓' : isWaived ? 'معفى' : `${money(m.net_amount)} د.ت`}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Subscribed Clubs */}
                {st.clubs && st.clubs.length > 0 && (
                  <div className="space-y-1.5 pt-2 border-t border-slate-100">
                    <span className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                      <Award size={13} className="text-amber-500" />
                      النوادي المشترك بها:
                    </span>
                    <div className="flex flex-wrap gap-2">
                      {st.clubs.map((c) => (
                        <span key={c.club_id} className="px-3 py-1 rounded-xl bg-amber-50 text-amber-900 border border-amber-200 text-xs font-medium">
                          {c.club_name} ({money(c.monthly_fee)} د.ت)
                        </span>
                      ))}
                    </div>
                  </div>
                )}

                {/* Arrears */}
                {st.arrears && st.arrears.length > 0 && (
                  <div className="space-y-1.5 pt-2 border-t border-slate-100">
                    <span className="text-xs font-bold text-amber-800 flex items-center gap-1.5">
                      <AlertCircle size={13} />
                      المتخلدات السابقة:
                    </span>
                    <div className="space-y-1.5">
                      {st.arrears.map((arr) => (
                        <div key={arr.student_fee_id} className="p-2 rounded-xl bg-amber-50/50 border border-amber-200 flex justify-between text-xs">
                          <span>{arr.description}</span>
                          <span className="font-bold text-red-600">{money(arr.remaining_amount)} د.ت متبقٍ</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Family Collection Modal */}
      {showCollectModal && (
        <FamilyCollectionModal
          family={family}
          onClose={() => setShowCollectModal(false)}
          onSuccess={handleCollectionSuccess}
        />
      )}

      {/* Unified Receipt Modal Output */}
      {activeReceipt && (
        <ReceiptModal
          receipt={activeReceipt}
          onClose={() => setActiveReceipt(null)}
          onDelete={handleCancelPayment}
        />
      )}
    </div>
  );
}

export default FamilyDetailPage;
