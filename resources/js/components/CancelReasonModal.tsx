import { useState } from 'react';
import { Ban, X } from 'lucide-react';

const C = { forest: '#3B4A36', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8', error: '#A03434' };

interface CancelReasonModalProps {
  title: string;
  description?: string;
  busy?: boolean;
  onConfirm: (reason: string) => void;
  onClose: () => void;
}

/**
 * نافذة إلغاء موثّق.
 *
 * كل المستندات المالية تُلغى بسبب مكتوب ولا تُحذف، فالسبب شرط في الخادم
 * (min:3) — وهذه النافذة تفرضه في الواجهة أيضاً قبل إرسال الطلب.
 */
export function CancelReasonModal({ title, description, busy, onConfirm, onClose }: CancelReasonModalProps) {
  const [reason, setReason] = useState('');
  const valid = reason.trim().length >= 3;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
      <div className="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <Ban size={18} color={C.error} />
            <h3 className="font-bold" style={{ color: C.ink }}>{title}</h3>
          </div>
          <button onClick={onClose} type="button"><X size={18} color={C.muted} /></button>
        </div>

        {description ? <p className="text-sm mb-3" style={{ color: C.muted }}>{description}</p> : null}

        <label htmlFor="cancel-reason-input" className="block text-xs mb-1.5" style={{ color: C.muted }}>سبب الإلغاء (إجباري)</label>
        <textarea
          id="cancel-reason-input"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          autoFocus
          className="w-full px-3 py-2.5 rounded-xl text-sm"
          style={{ border: '1px solid ' + C.line, color: C.ink }}
          placeholder="مثال: خطأ في المبلغ، أو مستند مكرّر"
        />

        <div className="flex gap-3 mt-4">
          <button
            type="button"
            onClick={() => onConfirm(reason.trim())}
            disabled={!valid || busy}
            className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: C.error }}
          >
            {busy ? 'جارٍ الإلغاء…' : 'تأكيد الإلغاء'}
          </button>
          <button
            type="button"
            onClick={onClose}
            className="px-5 py-2.5 rounded-xl text-sm"
            style={{ border: '1px solid ' + C.line, color: C.muted }}
          >
            رجوع
          </button>
        </div>
      </div>
    </div>
  );
}

export default CancelReasonModal;
