import React, { type ReactNode } from 'react';

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'info' | 'purple' | 'neutral' | 'brand';

interface EnterpriseBadgeProps {
  children: ReactNode;
  variant?: BadgeVariant;
  dot?: boolean;
  className?: string;
}

const BADGE_STYLES: Record<BadgeVariant, { container: string; dot: string }> = {
  success: {
    container: 'bg-emerald-50 text-emerald-700 border border-emerald-200/80',
    dot: 'bg-emerald-500',
  },
  warning: {
    container: 'bg-amber-50 text-amber-800 border border-amber-200/80',
    dot: 'bg-amber-500',
  },
  danger: {
    container: 'bg-rose-50 text-rose-700 border border-rose-200/80',
    dot: 'bg-rose-500',
  },
  info: {
    container: 'bg-blue-50 text-blue-700 border border-blue-200/80',
    dot: 'bg-blue-500',
  },
  purple: {
    container: 'bg-purple-50 text-purple-700 border border-purple-200/80',
    dot: 'bg-purple-500',
  },
  neutral: {
    container: 'bg-slate-100 text-slate-700 border border-slate-200/80',
    dot: 'bg-slate-400',
  },
  brand: {
    container: 'bg-[#2E3B2A]/10 text-[#2E3B2A] border border-[#2E3B2A]/20',
    dot: 'bg-[#2E3B2A]',
  },
};

export function EnterpriseBadge({
  children,
  variant = 'neutral',
  dot = true,
  className = '',
}: EnterpriseBadgeProps) {
  const styles = BADGE_STYLES[variant] || BADGE_STYLES.neutral;

  return (
    <span className={`badge-pill ${styles.container} ${className}`}>
      {dot && (
        <span className={`h-1.5 w-1.5 rounded-full ${styles.dot}`} />
      )}
      <span>{children}</span>
    </span>
  );
}
