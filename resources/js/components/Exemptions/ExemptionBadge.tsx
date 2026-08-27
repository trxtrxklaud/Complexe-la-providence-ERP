import React from 'react';
import { ShieldCheck, HeartHandshake, BadgePercent, Ban } from 'lucide-react';

interface ExemptionBadgeProps {
  discountType?: 'full_waiver' | 'humanitarian_fixed' | 'normal_monthly' | string | null;
  monthlyAmount?: number | null;
  isCancelled?: boolean;
  size?: 'sm' | 'md';
  showAmount?: boolean;
  className?: string;
}

export function ExemptionBadge({
  discountType,
  monthlyAmount,
  isCancelled = false,
  size = 'md',
  showAmount = true,
  className = '',
}: ExemptionBadgeProps) {
  if (!discountType && !isCancelled) return null;

  if (isCancelled) {
    return (
      <span
        className={`inline-flex items-center gap-1 font-semibold rounded-lg border ${
          size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs'
        } bg-slate-100 text-slate-600 border-slate-200 line-through ${className}`}
      >
        <Ban className={size === 'sm' ? 'w-3 h-3' : 'w-3.5 h-3.5'} />
        <span>إعفاء ملغى</span>
      </span>
    );
  }

  if (discountType === 'full_waiver') {
    return (
      <span
        className={`inline-flex items-center gap-1 font-bold rounded-lg border ${
          size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs'
        } bg-emerald-50 text-emerald-800 border-emerald-300 ${className}`}
      >
        <ShieldCheck className={size === 'sm' ? 'w-3.5 h-3.5' : 'w-4 h-4'} />
        <span>معفى كلياً</span>
      </span>
    );
  }

  if (discountType === 'humanitarian_fixed') {
    return (
      <span
        className={`inline-flex items-center gap-1 font-semibold rounded-lg border ${
          size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs'
        } bg-amber-50 text-amber-900 border-amber-300 ${className}`}
      >
        <HeartHandshake className={size === 'sm' ? 'w-3.5 h-3.5' : 'w-4 h-4'} />
        <span>
          تخفيض إنساني
          {showAmount && monthlyAmount !== null && monthlyAmount !== undefined
            ? ` (${Number(monthlyAmount).toFixed(2)} د)`
            : ''}
        </span>
      </span>
    );
  }

  if (discountType === 'normal_monthly' || discountType === 'normal') {
    return (
      <span
        className={`inline-flex items-center gap-1 font-semibold rounded-lg border ${
          size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs'
        } bg-teal-50 text-teal-800 border-teal-300 ${className}`}
      >
        <BadgePercent className={size === 'sm' ? 'w-3.5 h-3.5' : 'w-4 h-4'} />
        <span>
          تخفيض شهري
          {showAmount && monthlyAmount !== null && monthlyAmount !== undefined
            ? ` (${Number(monthlyAmount).toFixed(2)} د)`
            : ''}
        </span>
      </span>
    );
  }

  return (
    <span
      className={`inline-flex items-center gap-1 font-semibold rounded-lg border ${
        size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-xs'
      } bg-blue-50 text-blue-800 border-blue-200 ${className}`}
    >
      <span>{discountType}</span>
    </span>
  );
}
