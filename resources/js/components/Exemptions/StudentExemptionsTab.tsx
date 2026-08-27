import React, { useEffect, useState } from 'react';
import {
  ShieldCheck,
  Plus,
  Loader2,
  AlertCircle,
  Ban,
  Calendar,
  Sparkles,
  HeartHandshake,
} from 'lucide-react';
import { useAuth } from '../../contexts/AuthContext';
import {
  fetchStudentExemptions,
  cancelMonthlyExemption,
  cancelClubExemption,
  type ExemptionItem,
  type GetExemptionsResponse,
} from '../../api/exemptions';
import { ExemptionBadge } from './ExemptionBadge';
import { ExemptionFormModal } from './ExemptionFormModal';

const C = {
  forest: '#3B4A36',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
};

interface StudentExemptionsTabProps {
  enrollmentId: number;
  studentName: string;
  clubSubscriptions?: Array<{
    id: number;
    club?: {
      id: number;
      name: string;
      monthly_fee?: number;
    };
  }>;
}

const MONTH_NAMES: Record<string, string> = {
  '01': 'جانفي', '02': 'فيفري', '03': 'مارس', '04': 'أفريل',
  '05': 'ماي', '06': 'جوان', '07': 'جويلية', '08': 'أوت',
  '09': 'سبتمبر', '10': 'أكتوبر', '11': 'نوفمبر', '12': 'ديسمبر',
};

function formatMonth(m: string) {
  const parts = (m || '').split('-');
  if (parts.length === 2) {
    return `${MONTH_NAMES[parts[1]] || parts[1]} ${parts[0]}`;
  }
  return m;
}

export function StudentExemptionsTab({
  enrollmentId,
  studentName,
  clubSubscriptions = [],
}: StudentExemptionsTabProps) {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('waive_fees');

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [data, setData] = useState<GetExemptionsResponse | null>(null);

  const [showModal, setShowModal] = useState(false);
  const [cancellingId, setCancellingId] = useState<number | null>(null);

  async function loadData() {
    if (!enrollmentId) return;
    setLoading(true);
    setError('');
    try {
      const res = await fetchStudentExemptions(enrollmentId);
      setData(res);
    } catch (e: any) {
      setError(e?.message || 'تعذر تحميل الإعفاءات');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
  }, [enrollmentId]);

  const activeItems = (data?.data || []).filter((it) => it.is_active);
  const inactiveItems = (data?.data || []).filter((it) => !it.is_active);

  async function handleCancel(item: ExemptionItem) {
    const reason = window.prompt('سبب إلغاء الإعفاء (إلزامي):');
    if (reason === null) return;
    if (reason.trim().length < 3) {
      alert('يرجى كتابة سبب واضح للإلغاء (3 أحرف على الأقل)');
      return;
    }

    setCancellingId(item.id);
    try {
      if (item.type === 'tuition') {
        await cancelMonthlyExemption(item.id, reason.trim());
      } else {
        await cancelClubExemption(item.id, reason.trim());
      }
      await loadData();
    } catch (e: any) {
      alert(e?.message || 'تعذر إلغاء الإعفاء');
    } finally {
      setCancellingId(null);
    }
  }

  return (
    <div className="space-y-6" dir="rtl">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-5 rounded-3xl border" style={{ borderColor: C.line }}>
        <div className="flex items-center gap-3">
          <div className="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-800 flex items-center justify-center">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <h2 className="text-base font-bold" style={{ color: C.ink }}>
              إدارة الإعفاءات والتخفيضات الشهرية
            </h2>
            <p className="text-xs" style={{ color: C.muted }}>
              إعفاءات التمدرس ومعاليم النوادي المدرسية المطبقة على هذا التسجيل
            </p>
          </div>
        </div>

        {canManage && (
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="px-4 py-2.5 rounded-2xl text-xs font-bold text-white transition flex items-center gap-1.5 shadow-sm"
            style={{ background: C.forest }}
          >
            <Plus className="w-4 h-4" />
            <span>إضافة إعفاء جديد</span>
          </button>
        )}
      </div>

      {error && (
        <div className="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
          <AlertCircle className="w-4 h-4 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {loading ? (
        <div className="bg-white rounded-3xl border p-12 flex flex-col items-center justify-center text-slate-400 gap-3" style={{ borderColor: C.line }}>
          <Loader2 className="w-8 h-8 animate-spin text-emerald-700" />
          <span className="text-xs">جارٍ جلب سجل الإعفاءات...</span>
        </div>
      ) : (
        <div className="space-y-6">
          {/* الإعفاءات السارية */}
          <div className="space-y-3">
            <div className="flex items-center gap-2 text-xs font-bold text-slate-700">
              <Sparkles className="w-4 h-4 text-emerald-700" />
              <span>الإعفاءات السارية الحالية ({activeItems.length})</span>
            </div>

            {activeItems.length === 0 ? (
              <div className="bg-white rounded-3xl border p-8 text-center" style={{ borderColor: C.line }}>
                <p className="text-xs font-medium text-slate-500">
                  لا توجد أي إعفاءات أو تخفيضات سارية لهذا التلميذ حالياً.
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {activeItems.map((item) => (
                  <div
                    key={`${item.type}-${item.id}`}
                    className="bg-white rounded-3xl border p-5 shadow-sm flex flex-col justify-between space-y-4"
                    style={{ borderColor: C.line }}
                  >
                    <div className="space-y-2">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-bold text-slate-900">
                          {item.type === 'club' ? `نادي ${item.club_name}` : 'المعلوم الدراسي الشهري'}
                        </span>
                        <ExemptionBadge
                          discountType={item.discount_type}
                          monthlyAmount={item.monthly_amount}
                          isCancelled={false}
                        />
                      </div>

                      <div className="text-xs text-slate-600 space-y-1">
                        <div className="flex items-center gap-1.5 text-slate-500">
                          <Calendar className="w-3.5 h-3.5" />
                          <span>
                            الفترة: من {formatMonth(item.start_month)} إلى {formatMonth(item.end_month)}
                          </span>
                        </div>
                        <div>
                          <span className="font-semibold text-slate-700">السبب:</span> {item.reason}
                        </div>
                        {item.notes && (
                          <div className="text-[11px] text-slate-400">
                            <span className="font-semibold">ملاحظات:</span> {item.notes}
                          </div>
                        )}
                      </div>
                    </div>

                    <div className="pt-3 border-t flex items-center justify-between text-[11px] text-slate-400" style={{ borderColor: C.line }}>
                      <span>سجل بواسطة: {item.created_by || 'النظام'}</span>
                      {canManage && (
                        <button
                          type="button"
                          disabled={cancellingId === item.id}
                          onClick={() => handleCancel(item)}
                          className="px-3 py-1 rounded-xl text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 transition font-bold flex items-center gap-1 disabled:opacity-50"
                        >
                          <Ban className="w-3 h-3" />
                          <span>إلغاء الإعفاء</span>
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* سجل الإعفاءات الملغاة */}
          {inactiveItems.length > 0 && (
            <div className="space-y-3 pt-4 border-t" style={{ borderColor: C.line }}>
              <div className="flex items-center gap-2 text-xs font-bold text-slate-500">
                <Ban className="w-4 h-4 text-slate-400" />
                <span>سجل الإعفاءات السابقة والملغاة ({inactiveItems.length})</span>
              </div>

              <div className="space-y-2">
                {inactiveItems.map((item) => (
                  <div
                    key={`${item.type}-${item.id}`}
                    className="bg-slate-50 rounded-2xl border p-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600"
                    style={{ borderColor: C.line }}
                  >
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="font-bold text-slate-700">
                          {item.type === 'club' ? `نادي ${item.club_name}` : 'المعلوم الدراسي الشهري'}
                        </span>
                        <ExemptionBadge discountType={item.discount_type} isCancelled={true} />
                      </div>
                      <div className="text-[11px] text-slate-500">
                        الفترة: {formatMonth(item.start_month)} ← {formatMonth(item.end_month)} | السبب الأصلي: {item.reason}
                      </div>
                      {item.cancellation_reason && (
                        <div className="text-[11px] text-rose-700">
                          سبب الإلغاء: {item.cancellation_reason} ({item.cancelled_by || '—'})
                        </div>
                      )}
                    </div>
                    <span className="text-[10px] text-slate-400">
                      ألغي بتاريخ: {item.cancelled_at ? item.cancelled_at.slice(0, 10) : '—'}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Modal */}
      {showModal && (
        <ExemptionFormModal
          enrollmentId={enrollmentId}
          studentName={studentName}
          clubSubscriptions={clubSubscriptions}
          onClose={() => setShowModal(false)}
          onSuccess={() => {
            setShowModal(false);
            loadData();
          }}
        />
      )}
    </div>
  );
}
