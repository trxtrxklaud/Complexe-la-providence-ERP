import { useEffect, useState, useCallback } from 'react';
import { History, Loader2, AlertCircle, RefreshCw, Printer } from 'lucide-react';
import { paymentsApi } from '../../api/payments';
import { ReceiptModal, type ReceiptData } from './ReceiptModal';
import type { Payment, PaginatedResponse, UserBrief } from '../../types';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };
const METHOD_LABELS: Record<string, string> = {
  cash: 'نقداً',
  bank_transfer: 'تحويل بنكي',
  check: 'شيك',
  card: 'بطاقة',
};

// اسم المنفّذ: العلاقة تُرجع كائناً {first_name,last_name}؛ نتعامل معه بمرونة.
function personName(v: number | UserBrief | null | undefined): string {
  if (v && typeof v === 'object') {
    return [v.first_name, v.last_name].filter(Boolean).join(' ') || '—';
  }
  return '—';
}

function fmtDateTime(s?: string | null): string {
  if (!s) return '—';
  const d = new Date(s);
  if (isNaN(d.getTime())) return String(s);
  return (
    d.toLocaleDateString('fr-FR') +
    ' ' +
    d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  );
}

function fmtDay(s?: string | null): string {
  if (!s) return '—';
  const d = new Date(s);
  if (isNaN(d.getTime())) return String(s);
  return d.toLocaleDateString('fr-FR');
}

export function HistoriquePage() {
  const [rows, setRows] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [receipt, setReceipt] = useState<ReceiptData | null>(null);

  const load = useCallback(async (p: number) => {
    setLoading(true);
    setError(null);
    try {
      const res: PaginatedResponse<Payment> = await paymentsApi.index({
        cancelled: true,
        per_page: 50,
        page: p,
      });
      setRows(res.data ?? []);
      setPage(res.current_page ?? p);
      setLastPage(res.last_page ?? 1);
      setTotal(res.total ?? 0);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'فشل جلب سجل الوصولات الملغاة');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load(1);
  }, [load]);

  const handlePrint = async (payment: Payment) => {
    try {
      const p: any = await paymentsApi.show(payment.id);
      setReceipt({
        payment_id: p.id,
        student_name: p.student ? `${p.student.first_name} ${p.student.last_name}` : '—',
        student_code: p.student?.student_code,
        payment_date: p.payment_date,
        method: p.method,
        amount: p.amount,
        total: p.amount,
        items: (p.payment_allocations || []).map((a: any) => ({ description: a.student_fee?.description || 'معلوم', amount: a.amount_allocated })),
        cancelled_at: p.cancelled_at,
        cancellation_reason: p.cancellation_reason,
      });
    } catch (e: any) {
      alert(e.message || 'تعذر تحميل الوصل');
    }
  };

  return (
    <div dir="rtl" className="p-6 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-3">
          <div
            className="w-11 h-11 rounded-2xl flex items-center justify-center"
            style={{ backgroundColor: C.sage }}
          >
            <History size={22} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-xl font-bold" style={{ color: C.ink }}>
              سجل الوصولات الملغاة
            </h1>
            <p className="text-sm" style={{ color: C.muted }}>
              Historique des reçus annulés — {total} عملية
            </p>
          </div>
        </div>
        <button
          onClick={() => load(page)}
          className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition"
          style={{ backgroundColor: C.sage, color: C.forest }}
        >
          <RefreshCw size={16} /> تحديث
        </button>
      </div>

      {error && (
        <div className="flex items-center gap-2 p-4 mb-4 rounded-xl bg-red-50 text-red-700 text-sm">
          <AlertCircle size={18} /> {error}
        </div>
      )}

      <div
        className="bg-white rounded-2xl shadow-sm overflow-x-auto"
        style={{ border: `1px solid ${C.line}` }}
      >
        {loading ? (
          <div className="flex items-center justify-center py-16" style={{ color: C.muted }}>
            <Loader2 className="animate-spin" size={24} />
          </div>
        ) : rows.length === 0 ? (
          <div className="text-center py-16" style={{ color: C.muted }}>
            لا توجد وصولات ملغاة بعد
          </div>
        ) : (
          <table className="w-full text-sm text-right">
            <thead>
              <tr style={{ backgroundColor: C.sage, color: C.forest }}>
                <th className="px-4 py-3 font-semibold">#</th>
                <th className="px-4 py-3 font-semibold">التلميذ</th>
                <th className="px-4 py-3 font-semibold">المبلغ</th>
                <th className="px-4 py-3 font-semibold">الطريقة</th>
                <th className="px-4 py-3 font-semibold">تاريخ الدفع</th>
                <th className="px-4 py-3 font-semibold">تاريخ الإلغاء</th>
                <th className="px-4 py-3 font-semibold">نفّذ الإلغاء</th>
                <th className="px-4 py-3 font-semibold">سبب الإلغاء</th>
                <th className="px-4 py-3 font-semibold">طباعة</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((p) => (
                <tr key={p.id} style={{ borderTop: `1px solid ${C.line}` }}>
                  <td className="px-4 py-3" style={{ color: C.muted }}>
                    {p.id}
                  </td>
                  <td className="px-4 py-3" style={{ color: C.ink }}>
                    {p.student ? `${p.student.first_name} ${p.student.last_name}` : '—'}
                    {p.student?.student_code ? (
                      <span className="block text-xs" style={{ color: C.muted }}>
                        {p.student.student_code}
                      </span>
                    ) : null}
                  </td>
                  <td className="px-4 py-3 font-semibold" style={{ color: C.ink }}>
                    {Number(p.amount).toLocaleString('fr-FR')}
                  </td>
                  <td className="px-4 py-3" style={{ color: C.muted }}>
                    {METHOD_LABELS[p.method] ?? p.method}
                  </td>
                  <td className="px-4 py-3" style={{ color: C.muted }}>
                    {fmtDay(p.payment_date)}
                  </td>
                  <td className="px-4 py-3" style={{ color: C.muted }}>
                    {fmtDateTime(p.cancelled_at)}
                  </td>
                  <td className="px-4 py-3" style={{ color: C.muted }}>
                    {personName(p.cancelled_by)}
                  </td>
                  <td className="px-4 py-3" style={{ color: C.ink }}>
                    <span className="inline-block px-2 py-1 rounded-lg bg-red-50 text-red-700 text-xs">
                      {p.cancellation_reason || '—'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <button type="button" onClick={() => handlePrint(p)} className="p-1.5 rounded-lg border hover:bg-slate-50" style={{ borderColor: C.line, color: C.forest }} title="طباعة">
                      <Printer size={14} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {lastPage > 1 && (
        <div className="flex items-center justify-center gap-3 mt-4">
          <button
            disabled={page <= 1}
            onClick={() => load(page - 1)}
            className="px-3 py-1.5 rounded-lg text-sm disabled:opacity-40"
            style={{ backgroundColor: C.sage, color: C.forest }}
          >
            السابق
          </button>
          <span className="text-sm" style={{ color: C.muted }}>
            {page} / {lastPage}
          </span>
          <button
            disabled={page >= lastPage}
            onClick={() => load(page + 1)}
            className="px-3 py-1.5 rounded-lg text-sm disabled:opacity-40"
            style={{ backgroundColor: C.sage, color: C.forest }}
          >
            التالي
          </button>
        </div>
      )}

      {receipt && (
        <ReceiptModal receipt={receipt} onClose={() => setReceipt(null)} />
      )}
    </div>
  );
}
