import React, { useState } from 'react';
import { Printer, X, Ban, FileText, UserCheck, ShieldCheck } from 'lucide-react';

const METHOD_LABELS: Record<string, string> = {
  cash: 'نقداً',
  bank_transfer: 'تحويل بنكي',
  check: 'شيك',
  card: 'بطاقة',
};

export interface ReceiptItem {
  description?: string;
  fee_type_name?: string;
  name_ar?: string;
  name?: string;
  amount: number | string;
  is_prior_year?: boolean;
}

export interface ReceiptData {
  payment_id: number | string;
  payment_date?: string;
  created_at?: string;
  method?: string;
  method_label?: string;
  reference?: string | null;
  notes?: string | null;
  months?: string[];
  months_label?: string | string[];
  items?: ReceiptItem[];
  discount?: number | string;
  total?: number | string;
  amount?: number | string;
  prior_total?: number | string;
  student_name?: string;
  student_code?: string;
  section_name?: string;
  guardian_name?: string;
  academic_year?: string;
  user_name?: string;
  remaining_amount?: number | string;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
}

interface Props {
  receipt: ReceiptData;
  cashierName?: string;
  onClose: () => void;
  onDelete?: () => void;
}

function monthsText(receipt: ReceiptData): string {
  if (Array.isArray(receipt.months_label)) return receipt.months_label.join(' / ');
  if (receipt.months_label) return String(receipt.months_label);
  return (receipt.months || []).join(' / ');
}

function itemLabel(item: ReceiptItem): string {
  return item.description || item.fee_type_name || item.name_ar || item.name || 'بند';
}

function PriorTag({ item }: { item: ReceiptItem }) {
  if (!item.is_prior_year) return null;
  return (
    <span style={{ fontSize: 10, color: '#92400E', background: '#FEF3C7', padding: '1px 5px', borderRadius: 3, marginRight: 5, fontWeight: 700 }}>
      متخلد سابق
    </span>
  );
}

function money(value: number | string | undefined): string {
  return Number(value ?? 0).toFixed(2);
}

export type ReceiptViewMode = 'both' | 'guardian' | 'admin';

/**
 * Payment receipt modal.
 * Renders Guardian copy (without financial amounts) and/or Administrative copy (with full financial details).
 */
export function ReceiptModal({ receipt, cashierName, onClose, onDelete }: Props) {
  const [viewMode, setViewMode] = useState<ReceiptViewMode>('both');
  const total = receipt.total ?? receipt.amount;
  const method = receipt.method_label || METHOD_LABELS[String(receipt.method)] || receipt.method || '—';
  const cashier = receipt.user_name || cashierName || '—';

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 no-print-parent"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="bg-white rounded-2xl w-full max-w-3xl max-h-[95vh] overflow-auto shadow-xl">
        {/* Header Control Bar */}
        <div
          className="no-print flex flex-wrap items-center justify-between gap-2 p-3 border-b"
          style={{ borderColor: '#EDF1E8' }}
        >
          <div className="flex items-center gap-3">
            <div className="font-bold text-slate-800">وصل الاستخلاص</div>
            {/* Mode selection buttons */}
            <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-xl text-xs font-semibold">
              <button
                type="button"
                onClick={() => setViewMode('both')}
                className={`px-2.5 py-1 rounded-lg transition ${
                  viewMode === 'both' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                الكل (نسختان)
              </button>
              <button
                type="button"
                onClick={() => setViewMode('guardian')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg transition ${
                  viewMode === 'guardian' ? 'bg-[#3B4A36] text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <UserCheck size={13} />
                وصل الولي فقط
              </button>
              <button
                type="button"
                onClick={() => setViewMode('admin')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg transition ${
                  viewMode === 'admin' ? 'bg-[#3B4A36] text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <ShieldCheck size={13} />
                الوصل الإداري فقط
              </button>
            </div>
          </div>

          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => window.print()}
              className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-white text-sm font-bold shadow-sm"
              style={{ background: '#3B4A36' }}
            >
              <Printer size={16} /> طباعة
            </button>
            {onDelete && (
              <button
                type="button"
                onClick={onDelete}
                className="flex items-center gap-2 px-3 py-2 rounded-xl border text-sm"
                style={{ borderColor: '#FCA5A5', color: '#DC2626' }}
              >
                <Ban size={16} /> إلغاء الدفعة
              </button>
            )}
            <button
              type="button"
              onClick={onClose}
              className="px-3 py-2 rounded-xl border text-sm"
              style={{ borderColor: '#EDF1E8' }}
            >
              <X size={16} />
            </button>
          </div>
        </div>

        {/* Receipt Output Container */}
        <div
          id="receipt-print"
          style={{ direction: 'rtl', color: '#111', fontFamily: 'Tahoma, Arial, sans-serif', padding: '12px' }}
        >
          {(viewMode === 'both' || viewMode === 'guardian') && (
            <ReceiptHalf
              receipt={receipt}
              copyLabel="نسخة الولي"
              isGuardian={true}
              method={method}
              cashier={cashier}
              total={total}
            />
          )}

          {viewMode === 'both' && (
            <div style={{ textAlign: 'center', fontSize: 10, color: '#888', margin: '3mm 0' }}>
              ✂ - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            </div>
          )}

          {(viewMode === 'both' || viewMode === 'admin') && (
            <ReceiptHalf
              receipt={receipt}
              copyLabel="نسخة الإدارة"
              isGuardian={false}
              method={method}
              cashier={cashier}
              total={total}
            />
          )}
        </div>
      </div>
    </div>
  );
}

interface HalfProps {
  receipt: ReceiptData;
  copyLabel: string;
  isGuardian: boolean;
  method: string;
  cashier: string;
  total: number | string | undefined;
}

function ReceiptHalf({ receipt, copyLabel, isGuardian, method, cashier, total }: HalfProps) {
  const mText = monthsText(receipt);
  const items = receipt.items || [];
  const remaining = receipt.remaining_amount !== undefined ? Number(receipt.remaining_amount) : null;
  const isCancelled = Boolean(receipt.cancelled_at || (receipt as any).is_cancelled || (receipt as any).status === 'cancelled');

  return (
    <div
      style={{
        minHeight: '130mm',
        border: isCancelled ? '2px solid #DC2626' : '1px solid #bbb',
        padding: '10px 14px',
        boxSizing: 'border-box',
        overflow: 'hidden',
        backgroundColor: isCancelled ? '#FFF8F8' : '#ffffff',
        borderRadius: '6px',
        position: 'relative',
      }}
    >
      {/* Header with School Title, Phone Numbers, and Red Receipt Number */}
      <div style={{ textAlign: 'center', fontWeight: 800, fontSize: 16, color: '#1F261C' }}>
        Complexe La Providence
      </div>
      <div style={{ textAlign: 'center', fontSize: 11, color: '#444', marginTop: 1, direction: 'ltr' }}>
        Tel: 95420350 / 76624400
      </div>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 6, marginBottom: 8, borderBottom: '1.5px solid #2E3B2A', paddingBottom: 4 }}>
        <div style={{ color: '#2E3B2A', fontWeight: 900, fontSize: 15 }}>
          وصل استخلاص <span style={{ fontSize: 11, fontWeight: 500, color: '#666' }}>({copyLabel})</span>
        </div>
        <div style={{ fontSize: 13, fontWeight: 800, color: '#1F261C' }}>
          رقم الوصل:{' '}
          <span style={{ color: '#DC2626', fontWeight: 900, fontSize: 16, letterSpacing: '0.5px' }}>
            #{receipt.payment_id} {isCancelled ? '(ملغى)' : ''}
          </span>
        </div>
      </div>

      {/* Prominent Red Banner for Cancelled Receipts */}
      {isCancelled && (
        <div style={{ backgroundColor: '#FEE2E2', border: '2px solid #DC2626', color: '#DC2626', padding: '6px 12px', borderRadius: '6px', textAlign: 'center', fontWeight: 900, fontSize: 14, marginTop: 4, marginBottom: 8 }}>
          ⚠️ وصل ملغى {receipt.cancellation_reason ? `— السبب: ${receipt.cancellation_reason}` : ''}
        </div>
      )}

      <div style={{ fontSize: 12, lineHeight: 1.6, borderBottom: '1px solid #eee', paddingBottom: '6px' }}>
        <div>
          التلميذ: <b>{receipt.student_name || '—'}</b>
          {receipt.student_code ? ` (${receipt.student_code})` : ''}
        </div>
        <div>القسم: {receipt.section_name || '—'}</div>
        <div>الولي: {receipt.guardian_name || '—'}</div>
        {receipt.academic_year && <div>السنة الدراسية: {receipt.academic_year}</div>}
        {mText && <div>الأشهر: <b>{mText}</b></div>}
        <div>تاريخ الاستخلاص: {receipt.payment_date || '—'}</div>
      </div>

      {/* Conditional Content based on Receipt Type */}
      {isGuardian ? (
        /* ===== وصل الولي: بدون مبالغ أو أسعار كلياً ===== */
        <div style={{ marginTop: 8, minHeight: '40mm' }}>
          <div style={{ fontSize: 12, fontWeight: 700, marginBottom: 4, color: '#3B4A36' }}>
            البنود والخدمات المستخلصة:
          </div>
          <ul style={{ margin: 0, paddingRight: 18, fontSize: 12, lineHeight: 1.5 }}>
            {items.map((item, i) => (
              <li key={i} style={{ color: '#222' }}>
                {itemLabel(item)}
              </li>
            ))}
          </ul>

          {/* حالة المتخلد بالذمة (إن وجدت بيانات) */}
          {remaining !== null && (
            <div style={{ marginTop: 10, padding: '6px 8px', borderRadius: '4px', backgroundColor: remaining > 0 ? '#FDF2F2' : '#F4F7F3', border: `1px solid ${remaining > 0 ? '#FCA5A5' : '#C8E6C9'}`, fontSize: 11 }}>
              <b>حالة المتخلد بالذمة:</b>{' '}
              {remaining > 0 ? (
                <span style={{ color: '#DC2626', fontWeight: 700 }}>يوجد مبلغ متبقي بالذمة</span>
              ) : (
                <span style={{ color: '#2E7D32', fontWeight: 700 }}>مستوفى بالكامل (لا متخلد بالذمة)</span>
              )}
            </div>
          )}
        </div>
      ) : (
        /* ===== الوصل الإداري: التفاصيل المالية كاملة ===== */
        <div style={{ marginTop: 6, minHeight: '40mm' }}>
          <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ borderBottom: '1px solid #ccc', color: '#555' }}>
                <th style={{ textAlign: 'right', padding: '3px 0' }}>البند</th>
                <th style={{ textAlign: 'left', padding: '3px 0' }}>المبلغ</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i} style={{ borderBottom: '1px solid #f0f0f0' }}>
                  <td style={{ padding: '3px 0' }}>{itemLabel(item)}</td>
                  <td style={{ textAlign: 'left', padding: '3px 0' }}>{money(item.amount)} د.ت</td>
                </tr>
              ))}
              {Number(receipt.discount || 0) > 0 && (
                <tr style={{ color: '#059669' }}>
                  <td style={{ padding: '3px 0' }}>خصم / تخفيض</td>
                  <td style={{ textAlign: 'left', padding: '3px 0' }}>-{money(receipt.discount)} د.ت</td>
                </tr>
              )}
              <tr style={{ borderTop: '1.5px solid #222' }}>
                <td style={{ fontWeight: 800, paddingTop: 4 }}>المبلغ الإجمالي المدفوع</td>
                <td style={{ textAlign: 'left', fontWeight: 900, paddingTop: 4, fontSize: 13 }}>
                  {money(total)} د.ت
                </td>
              </tr>
            </tbody>
          </table>

          {remaining !== null && (
            <div style={{ marginTop: 6, fontSize: 11, color: remaining > 0 ? '#DC2626' : '#2E7D32', fontWeight: 700 }}>
              المتخلد بالذمة المتبقي: {money(remaining)} د.ت
            </div>
          )}
        </div>
      )}

      {/* البيانات التشغيلية والتوايع */}
      <div style={{ fontSize: 11, marginTop: 8, borderTop: '1px solid #eee', paddingTop: 6 }}>
        الطريقة: <b>{method}</b> | رقم الوصل: <b style={{ color: '#DC2626', fontWeight: 900 }}>#{receipt.payment_id}</b>
        {receipt.reference ? ` | المرجع: ${receipt.reference}` : ''}
        <br />
        المسؤول / المحصل: {cashier}
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, marginTop: 12, color: '#444' }}>
        <span>توقيع الولي: ______________</span>
        <span>توقيع الإدارة: ______________</span>
      </div>
    </div>
  );
}

export default ReceiptModal;
