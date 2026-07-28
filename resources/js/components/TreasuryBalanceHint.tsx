import { useEffect, useState } from 'react';
import { AlertCircle, Landmark } from 'lucide-react';
import { fetchTreasuryBalance } from '../api/treasury';

/**
 * تذكير برصيد الخزينة قبل أي عملية صرف.
 *
 * قرار تشغيلي مقصود: هذا تحذير وليس منعاً. المدرسة قد تبدأ السنة برصيد صفر،
 * وقد تصرف في اليوم الواحد أكثر ممّا دخل فيه. منع العملية سيدفع القابض إلى الالتفاف
 * على النظام بتسجيل خاطئ، وذلك أسوأ من رصيد سالب صادق. المهمّ أن يبقى الحساب دقيقاً.
 *
 * المكوّن لا يعدّل أي رقم ولا يعطّل أي زرّ؛ وظيفته الإخبار فقط.
 */

const C = {
  forest: '#3B4A36', muted: '#7C8677', line: '#EDF1E8',
  ink: '#1F261C', error: '#A03434', errorBg: '#FDECEC', bg: '#F4F6F1',
};

type Props = {
  /** المبلغ الذي سيخرج من الخزينة فعلياً. */
  amount: number;
  /** يُعاد جلب الرصيد كلّما تغيّرت هذه القيمة (فتح النافذة مثلاً). */
  refreshKey?: unknown;
};

export function TreasuryBalanceHint({ amount, refreshKey }: Props) {
  const [balance, setBalance] = useState<number | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setFailed(false);

    fetchTreasuryBalance()
      .then((summary) => {
        if (!cancelled) setBalance(Number(summary.balance ?? 0));
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => { cancelled = true; };
  }, [refreshKey]);

  if (failed) {
    return (
      <p className="text-xs" style={{ color: C.muted }}>
        تعذّر جلب رصيد الخزينة. العملية ممكنة، والتسجيل سيبقى صحيحاً.
      </p>
    );
  }

  if (balance === null) {
    return (
      <p className="text-xs" style={{ color: C.muted }}>جارٍ جلب رصيد الخزينة…</p>
    );
  }

  const out = Number.isFinite(amount) && amount > 0 ? amount : 0;
  const projected = balance - out;
  const negative = projected < 0;

  return (
    <div
      className="rounded-xl border p-3 text-xs space-y-1"
      style={{
        borderColor: negative ? C.error : C.line,
        background: negative ? C.errorBg : C.bg,
      }}
    >
      <div className="flex items-center justify-between">
        <span className="flex items-center gap-1" style={{ color: C.muted }}>
          <Landmark size={14} /> رصيد الخزينة الآن
        </span>
        <strong style={{ color: balance < 0 ? C.error : C.ink }}>{balance.toFixed(2)}</strong>
      </div>

      <div className="flex items-center justify-between">
        <span style={{ color: C.muted }}>بعد هذه العملية</span>
        <strong style={{ color: negative ? C.error : C.forest }}>{projected.toFixed(2)}</strong>
      </div>

      {negative && (
        <p className="flex items-start gap-1 pt-1" style={{ color: C.error }}>
          <AlertCircle size={14} className="shrink-0 mt-0.5" />
          <span>
            هذا الصرف يتجاوز ما في الخزينة، وسيصبح الرصيد سالباً. العملية مسموحة
            وستُسجَّل بمبلغها الصحيح كاملاً.
          </span>
        </p>
      )}
    </div>
  );
}

export default TreasuryBalanceHint;
