import { useState } from 'react';
import { Printer, X, Ban, UserCheck, ShieldCheck } from 'lucide-react';

const METHOD_LABELS: Record<string, string> = {
  cash: 'نقداً',
  bank_transfer: 'تحويل بنكي',
  check: 'شيك',
  card: 'بطاقة',
};

const TEAL = '#2a9d8f';
const NAVY = '#1a3a5c';
const GOLD = '#c8a96e';

export interface ReceiptItem {
  description?: string;
  fee_type_name?: string;
  name_ar?: string;
  name?: string;
  amount: number | string;
  is_prior_year?: boolean;
}

export interface SiblingReceiptItem {
  student_id?: number;
  student_name: string;
  student_code?: string;
  level_section?: string;
  payment_id?: number;
  receipt_number?: string;
  months?: string[];
  amount: number | string;
  items?: ReceiptItem[];
}

export interface ReceiptData {
  payment_id?: number | string;
  receipt_number?: string;
  family_receipt_number?: string;
  is_family_receipt?: boolean;
  payment_date?: string;
  created_at?: string;
  method?: string;
  method_label?: string;
  reference?: string | null;
  notes?: string | null;
  months?: string[];
  months_label?: string | string[];
  items?: ReceiptItem[];
  siblings?: SiblingReceiptItem[];
  discount?: number | string;
  total?: number | string;
  amount?: number | string;
  prior_total?: number | string;
  student_name?: string;
  student_code?: string;
  section_name?: string;
  guardian_name?: string;
  guardian_phone?: string;
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

function money(value: number | string | undefined): string {
  return Number(value ?? 0).toFixed(2);
}

export type ReceiptViewMode = 'both' | 'guardian' | 'admin';

/**
 * Payment receipt modal.
 * Renders Guardian copy (without financial amounts) and/or Administrative copy (with full financial details).
 * Supports both single-student and multi-sibling family receipts.
 */
export function ReceiptModal({ receipt, cashierName, onClose, onDelete }: Props) {
  const [viewMode, setViewMode] = useState<ReceiptViewMode>('both');
  const total = receipt.total ?? receipt.amount;
  const method = receipt.method_label || METHOD_LABELS[String(receipt.method)] || receipt.method || '—';
  const cashier = receipt.user_name || cashierName || '—';

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-3 no-print-parent overflow-y-auto"
      onClick={(e) => e.target === e.currentTarget && onClose()}
      dir="rtl"
    >
      {/* Dedicated Print Stylesheet to hide page backdrop and isolate receipt */}
      <style>{`
        @media print {
          body * {
            visibility: hidden !important;
          }
          #receipt-print,
          #receipt-print * {
            visibility: visible !important;
          }
          #receipt-print {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            margin: 0 !important;
            padding: 6mm !important;
            box-sizing: border-box !important;
            background: #ffffff !important;
            box-shadow: none !important;
            border: none !important;
            z-index: 999999 !important;
          }
          .no-print,
          .no-print-parent {
            background: transparent !important;
          }
          a, a[href]::after { display: none !important; content: none !important; }
          * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
          html { -webkit-print-color-adjust: exact; }
          @page { margin: 0; size: A4 portrait; }
        }
      `}</style>

      <div className="bg-white rounded-2xl w-full max-w-3xl max-h-[95vh] overflow-auto shadow-xl border border-slate-100">
        {/* Header Control Bar */}
        <div
          className="no-print flex flex-wrap items-center justify-between gap-2 p-3 border-b bg-slate-50"
          style={{ borderColor: '#EDF1E8' }}
        >
          <div className="flex items-center gap-3">
            <div className="font-bold text-slate-800">
              {receipt.is_family_receipt ? 'وصل الاستخلاص العائلي' : 'وصل الاستخلاص'}
            </div>
            {/* Mode selection buttons */}
            <div className="flex items-center gap-1 bg-slate-200/70 p-1 rounded-xl text-xs font-semibold">
              <button
                type="button"
                onClick={() => setViewMode('both')}
                className={`px-2.5 py-1 rounded-lg transition ${
                  viewMode === 'both' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                الكل (نسختان)
              </button>
              <button
                type="button"
                onClick={() => setViewMode('guardian')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg transition ${
                  viewMode === 'guardian' ? 'bg-[#2a9d8f] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                <UserCheck size={13} />
                وصل الولي فقط
              </button>
              <button
                type="button"
                onClick={() => setViewMode('admin')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg transition ${
                  viewMode === 'admin' ? 'bg-[#2a9d8f] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900'
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
              className="flex items-center gap-2 px-4 py-2 rounded-xl text-white text-sm font-bold shadow-xs transition"
              style={{ background: TEAL }}
            >
              <Printer size={16} /> طباعة
            </button>
            {onDelete && (
              <button
                type="button"
                onClick={onDelete}
                className="flex items-center gap-2 px-3 py-2 rounded-xl border text-sm hover:bg-red-50 transition"
                style={{ borderColor: '#FCA5A5', color: '#DC2626' }}
              >
                <Ban size={16} /> إلغاء الدفعة
              </button>
            )}
            <button
              type="button"
              onClick={onClose}
              className="px-3 py-2 rounded-xl border text-sm text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition"
              style={{ borderColor: '#EDF1E8' }}
            >
              <X size={16} />
            </button>
          </div>
        </div>

        {/* Receipt Output Container */}
        <div
          id="receipt-print"
          style={{
            direction: 'rtl',
            color: '#111',
            fontFamily: "'Cairo', sans-serif",
            padding: '12px',
            position: 'relative',
          }}
        >
          <style>{`
            @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap');
          `}</style>

          {/* زخرفة: دائرتان متداخلتان تيل فاتح شفاف */}
          <div style={{ position: 'absolute', top: -40, left: -40, width: 180, height: 180, borderRadius: '50%', background: 'rgba(42,157,143,0.10)', pointerEvents: 'none' }} />
          <div style={{ position: 'absolute', top: -10, left: 30, width: 100, height: 100, borderRadius: '50%', background: 'rgba(42,157,143,0.12)', pointerEvents: 'none' }} />

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
  const siblings = receipt.siblings || [];
  const isFamily = Boolean(receipt.is_family_receipt || siblings.length > 0);
  const remaining = receipt.remaining_amount !== undefined ? Number(receipt.remaining_amount) : null;
  const isCancelled = Boolean(receipt.cancelled_at || (receipt as any).is_cancelled || (receipt as any).status === 'cancelled');

  const receiptNumber = receipt.family_receipt_number || receipt.receipt_number || (receipt.payment_id ? `#${receipt.payment_id}` : '—');

  return (
    <div
      style={{
        minHeight: '120mm',
        border: isCancelled ? '2px solid #DC2626' : `1px solid ${TEAL}`,
        padding: '10px 14px',
        boxSizing: 'border-box',
        overflow: 'hidden',
        backgroundColor: isCancelled ? '#FFF8F8' : '#ffffff',
        borderRadius: '6px',
        position: 'relative',
      }}
    >
      {/* Header with School Title, Phone Numbers, and Receipt Number */}
      <div style={{ textAlign: 'center', fontWeight: 800, fontSize: 22, color: GOLD, letterSpacing: 1, lineHeight: 1.3 }}>
        Complexe La Providence
      </div>
      <div style={{ textAlign: 'center', fontSize: 11, color: '#7d93a8', marginTop: 2, direction: 'ltr' }}>
        Tel: 95420350 / 76624400
      </div>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 6, marginBottom: 8, borderBottom: `2px solid ${TEAL}`, paddingBottom: 4 }}>
        <div style={{ color: NAVY, fontWeight: 900, fontSize: 15 }}>
          {isFamily ? 'وصل استخلاص عائلي موحد' : 'وصل استخلاص'}{' '}
          <span style={{ fontSize: 11, fontWeight: 500, color: '#7d93a8' }}>({copyLabel})</span>
        </div>
        <div style={{ fontSize: 13, fontWeight: 800, color: '#1F261C' }}>
          رقم الوصل:{' '}
          <span style={{ color: '#DC2626', fontWeight: 900, fontSize: 15, letterSpacing: '0.5px' }}>
            {receiptNumber} {isCancelled ? '(ملغى)' : ''}
          </span>
        </div>
      </div>

      {/* Prominent Red Banner for Cancelled Receipts */}
      {isCancelled && (
        <div style={{ backgroundColor: '#FEE2E2', border: '2px solid #DC2626', color: '#DC2626', padding: '6px 12px', borderRadius: '6px', textAlign: 'center', fontWeight: 900, fontSize: 14, marginTop: 4, marginBottom: 8 }}>
          ⚠️ وصل ملغى {receipt.cancellation_reason ? `— السبب: ${receipt.cancellation_reason}` : ''}
        </div>
      )}

      {/* Family or Single Student Details Header */}
      {isFamily ? (
        <div style={{ fontSize: 12, lineHeight: 1.6, borderBottom: `1px solid ${TEAL}`, paddingBottom: '6px' }}>
          <div>الولي / العائلة: <b style={{ color: NAVY }}>{receipt.guardian_name || '—'}</b></div>
          {receipt.guardian_phone && <div>الهاتف: <span dir="ltr">{receipt.guardian_phone}</span></div>}
          <div>عدد الأبناء المستخلص لهم: <b style={{ color: NAVY }}>{siblings.length}</b></div>
          <div>تاريخ الاستخلاص: <b style={{ color: NAVY }}>{receipt.payment_date || '—'}</b></div>
        </div>
      ) : (
        <div style={{ fontSize: 12, lineHeight: 1.6, borderBottom: `1px solid ${TEAL}`, paddingBottom: '6px' }}>
          <div>
            التلميذ: <b style={{ color: NAVY }}>{receipt.student_name || '—'}</b>
            {receipt.student_code ? ` (${receipt.student_code})` : ''}
          </div>
          <div>القسم: <b style={{ color: NAVY }}>{receipt.section_name || '—'}</b></div>
          <div>الولي: <b style={{ color: NAVY }}>{receipt.guardian_name || '—'}</b></div>
          {receipt.academic_year && <div>السنة الدراسية: <b style={{ color: NAVY }}>{receipt.academic_year}</b></div>}
          {mText && <div>الأشهر: <b style={{ color: NAVY }}>{mText}</b></div>}
          <div>تاريخ الاستخلاص: <b style={{ color: NAVY }}>{receipt.payment_date || '—'}</b></div>
        </div>
      )}

      {/* Breakdown Section: Family Siblings Table vs Single Student List */}
      {isFamily ? (
        /* ===== تفاصيل الأبناء في الوصل العائلي ===== */
        <div style={{ marginTop: 8, minHeight: '35mm' }}>
          <div style={{ fontSize: 12, fontWeight: 700, marginBottom: 4, color: NAVY }}>
            تفاصيل استخلاص الأبناء:
          </div>

          <table style={{ width: '100%', fontSize: 11, borderCollapse: 'collapse', marginTop: 4 }}>
            <thead>
              <tr style={{ background: TEAL, color: '#ffffff' }}>
                <th style={{ textAlign: 'right', padding: '6px 8px', fontWeight: 700 }}>الابن(ة)</th>
                <th style={{ textAlign: 'right', padding: '6px 8px', fontWeight: 700 }}>المستوى / القسم</th>
                <th style={{ textAlign: 'right', padding: '6px 8px', fontWeight: 700 }}>الأشهر والخدمات</th>
                {!isGuardian && <th style={{ textAlign: 'left', padding: '6px 8px', fontWeight: 700 }}>المبلغ</th>}
              </tr>
            </thead>
            <tbody>
              {siblings.map((sib, i) => (
                <tr key={i} style={{ borderBottom: '1px solid #e0f0ee' }}>
                  <td style={{ padding: '5px 8px', fontWeight: 700, color: NAVY }}>
                    {sib.student_name}
                    {sib.student_code && <span style={{ fontSize: 10, color: '#7d93a8', fontWeight: 'normal' }}> ({sib.student_code})</span>}
                  </td>
                  <td style={{ padding: '5px 8px', color: '#555' }}>{sib.level_section || '—'}</td>
                  <td style={{ padding: '5px 8px' }}>
                    <div className="space-y-0.5">
                      {sib.months && sib.months.length > 0 && (
                        <div>معلوم دراسي: <b>{sib.months.join(' / ')}</b></div>
                      )}
                      {sib.items && sib.items
                        .filter(it => {
                          const desc = it.description ?? '';
                          // أظهر فقط البنود التي ليست معلوم تمدرس شهري (محسوب في sib.months)
                          const isTuition = sib.months && sib.months.length > 0 &&
                            (desc.includes('معلوم التمدرس') || desc.includes('معلوم دراسي شهر'));
                          return !isTuition;
                        })
                        .map((it, idx) => (
                          <div key={idx} style={{ color: '#555' }}>{it.description}</div>
                        ))
                      }
                      {!sib.months?.length && !sib.items?.length && <span>معلوم دراسي</span>}
                    </div>
                  </td>
                  {!isGuardian && (
                    <td style={{ textAlign: 'left', padding: '5px 8px', fontWeight: 700, color: NAVY }} dir="ltr">
                      {money(sib.amount)} DT
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>

          {!isGuardian && (
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8, borderTop: `2px solid ${NAVY}`, paddingTop: 6 }}>
              <span style={{ fontWeight: 800, fontSize: 13, color: NAVY }}>المبلغ الإجمالي للاستخلاص العائلي:</span>
              <span style={{ fontWeight: 900, fontSize: 16, color: TEAL }}>
                {money(total)} د.ت
              </span>
            </div>
          )}
        </div>
      ) : (
        /* ===== تفاصيل التلميذ الفردي ===== */
        isGuardian ? (
          /* وصل الولي الفردي: بدون مبالغ */
          <div style={{ marginTop: 8, minHeight: '35mm' }}>
            <div style={{ fontSize: 12, fontWeight: 700, marginBottom: 4, color: NAVY }}>
              البنود والخدمات المستخلصة:
            </div>
            <ul style={{ margin: 0, paddingRight: 18, fontSize: 12, lineHeight: 1.5 }}>
              {items.map((item, i) => (
                <li key={i} style={{ color: '#222' }}>
                  {itemLabel(item)}
                </li>
              ))}
            </ul>

            {remaining !== null && (
              <div style={{ marginTop: 8, padding: '5px 8px', borderRadius: '4px', backgroundColor: remaining > 0 ? '#FDF2F2' : '#F4F7F3', border: `1px solid ${remaining > 0 ? '#FCA5A5' : '#C8E6C9'}`, fontSize: 11 }}>
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
          /* الوصل الإداري الفردي: التفاصيل المالية كاملة */
          <div style={{ marginTop: 6, minHeight: '35mm' }}>
            <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ background: TEAL, color: '#ffffff' }}>
                  <th style={{ textAlign: 'right', padding: '6px 8px' }}>البند</th>
                  <th style={{ textAlign: 'left', padding: '6px 8px' }}>المبلغ</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid #e0f0ee' }}>
                    <td style={{ padding: '5px 8px', color: NAVY }}>{itemLabel(item)}</td>
                    <td style={{ textAlign: 'left', padding: '5px 8px', fontWeight: 700 }}>{money(item.amount)} د.ت</td>
                  </tr>
                ))}
                {Number(receipt.discount || 0) > 0 && (
                  <tr style={{ color: '#059669' }}>
                    <td style={{ padding: '5px 8px' }}>خصم / تخفيض</td>
                    <td style={{ textAlign: 'left', padding: '5px 8px' }}>-{money(receipt.discount)} د.ت</td>
                  </tr>
                )}
                <tr style={{ borderTop: `2px solid ${NAVY}` }}>
                  <td style={{ fontWeight: 800, paddingTop: 4, color: NAVY }}>المبلغ الإجمالي المدفوع</td>
                  <td style={{ textAlign: 'left', fontWeight: 900, paddingTop: 4, fontSize: 14, color: TEAL }}>
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
        )
      )}

      {/* البيانات التشغيلية والتواقيع */}
      <div style={{ fontSize: 11, marginTop: 8, borderTop: `1px solid ${TEAL}`, paddingTop: 6 }}>
        الطريقة: <b>{method}</b> | رقم العملية: <b style={{ color: '#DC2626', fontWeight: 900 }}>{receiptNumber}</b>
        {receipt.reference ? ` | المرجع: ${receipt.reference}` : ''}
        <br />
        المسؤول / المحصل: <b style={{ color: NAVY }}>{cashier}</b>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, marginTop: 10, color: '#444' }}>
        <span>توقيع الولي: ______________</span>
        <span>توقيع الإدارة: ______________</span>
      </div>
    </div>
  );
}

export default ReceiptModal;