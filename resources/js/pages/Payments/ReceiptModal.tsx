import React from 'react';
import { Printer, X, Trash2 } from 'lucide-react';

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
  student_name?: string;
  student_code?: string;
  section_name?: string;
  guardian_name?: string;
  academic_year?: string;
  user_name?: string;
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

function money(value: number | string | undefined): string {
  return Number(value ?? 0).toFixed(2);
}

/**
 * Payment receipt modal.
 * Renders two identical halves (guardian copy + administration copy) on a
 * single A4 portrait sheet, separated by a cut line.
 */
export function ReceiptModal({ receipt, cashierName, onClose, onDelete }: Props) {
  const total = receipt.total ?? receipt.amount;
  const method = receipt.method_label || METHOD_LABELS[String(receipt.method)] || receipt.method || '—';
  const cashier = receipt.user_name || cashierName || '—';

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 no-print-parent"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="bg-white rounded-2xl w-full max-w-3xl max-h-[95vh] overflow-auto shadow-xl">
        <div
          className="no-print flex items-center justify-between gap-2 p-3 border-b"
          style={{ borderColor: '#EDF1E8' }}
        >
          <div className="font-bold">بيانات العملية</div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => window.print()}
              className="flex items-center gap-2 px-3 py-2 rounded-xl text-white text-sm font-bold"
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
                <Trash2 size={16} /> حذف
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

        <div
          id="receipt-print"
          style={{ direction: 'rtl', color: '#111', fontFamily: 'Tahoma, Arial, sans-serif' }}
        >
          <ReceiptHalf
            receipt={receipt}
            copyLabel="نسخة الولي"
            method={method}
            cashier={cashier}
            total={total}
          />

          <div style={{ textAlign: 'center', fontSize: 10, color: '#888', margin: '2mm 0' }}>
            ✂ - - - - - - - - - - - - - - - - - - - -
          </div>

          <ReceiptHalf
            receipt={receipt}
            copyLabel="نسخة الإدارة"
            method={method}
            cashier={cashier}
            total={total}
          />
        </div>
      </div>
    </div>
  );
}

interface HalfProps {
  receipt: ReceiptData;
  copyLabel: string;
  method: string;
  cashier: string;
  total: number | string | undefined;
}

function ReceiptHalf({ receipt, copyLabel, method, cashier, total }: HalfProps) {
  return (
    <div
      style={{
        height: '135mm',
        border: '1px solid #bbb',
        padding: '8px 12px',
        boxSizing: 'border-box',
        overflow: 'hidden',
      }}
    >
      <div style={{ textAlign: 'center', fontWeight: 800, fontSize: 15 }}>Complexe La Providence</div>
      <div style={{ textAlign: 'center', color: '#DC2626', fontWeight: 900, fontSize: 16 }}>خلاص</div>
      <div style={{ textAlign: 'center', fontSize: 12, marginBottom: 6 }}>{copyLabel}</div>

      <div style={{ fontSize: 12, lineHeight: 1.6 }}>
        <div>
          التلميذ: <b>{receipt.student_name || '—'}</b>
          {receipt.student_code ? ` (${receipt.student_code})` : ''}
        </div>
        <div>القسم: {receipt.section_name || '—'}</div>
        <div>الولي: {receipt.guardian_name || '—'}</div>
        {receipt.academic_year && <div>السنة الدراسية: {receipt.academic_year}</div>}
        <div>الأشهر: {monthsText(receipt)}</div>
        <div>التاريخ: {receipt.payment_date || '—'}</div>
      </div>

      <table style={{ width: '100%', fontSize: 12, marginTop: 6, borderCollapse: 'collapse' }}>
        <tbody>
          {(receipt.items || []).map((item, i) => (
            <tr key={i}>
              <td style={{ padding: '2px 0' }}>{itemLabel(item)}</td>
              <td style={{ textAlign: 'left' }}>{money(item.amount)}</td>
            </tr>
          ))}
          {Number(receipt.discount || 0) > 0 && (
            <tr>
              <td>تخفيض</td>
              <td style={{ textAlign: 'left' }}>-{money(receipt.discount)}</td>
            </tr>
          )}
          <tr>
            <td style={{ fontWeight: 800, paddingTop: 4 }}>المبلغ المدفوع</td>
            <td style={{ textAlign: 'left', fontWeight: 800 }}>{money(total)} د.ت</td>
          </tr>
        </tbody>
      </table>

      <div style={{ fontSize: 11, marginTop: 6 }}>
        الطريقة: {method} | رقم الدفعة: {receipt.payment_id}
        {receipt.reference ? ` | المرجع: ${receipt.reference}` : ''}
        <br />
        المستخدم: {cashier}
      </div>

      <div
        style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, marginTop: 10, color: '#555' }}
      >
        <span>توقيع الولي: ______________</span>
        <span>توقيع المحصّل: ______________</span>
      </div>
    </div>
  );
}

export default ReceiptModal;
