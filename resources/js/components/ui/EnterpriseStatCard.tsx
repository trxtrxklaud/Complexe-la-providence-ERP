import React, { type ComponentType } from 'react';

export type StatCardVariant = 'emerald' | 'amber' | 'rose' | 'blue' | 'violet' | 'brand' | 'neutral';

interface EnterpriseStatCardProps {
  label: string;
  value: string | number;
  subValue?: string | null;
  icon: ComponentType<{ size?: number; className?: string; strokeWidth?: number }>;
  variant?: StatCardVariant;
  onClick?: () => void;
  className?: string;
}

const VARIANT_STYLES: Record<
  StatCardVariant,
  {
    iconBg: string;
    iconColor: string;
    borderHover: string;
    badgeCls?: string;
  }
> = {
  emerald: {
    iconBg: 'bg-emerald-50 ring-1 ring-emerald-500/20',
    iconColor: 'text-emerald-600',
    borderHover: 'hover:border-emerald-300',
  },
  amber: {
    iconBg: 'bg-amber-50 ring-1 ring-amber-500/20',
    iconColor: 'text-amber-600',
    borderHover: 'hover:border-amber-300',
  },
  rose: {
    iconBg: 'bg-rose-50 ring-1 ring-rose-500/20',
    iconColor: 'text-rose-600',
    borderHover: 'hover:border-rose-300',
  },
  blue: {
    iconBg: 'bg-blue-50 ring-1 ring-blue-500/20',
    iconColor: 'text-blue-600',
    borderHover: 'hover:border-blue-300',
  },
  violet: {
    iconBg: 'bg-violet-50 ring-1 ring-violet-500/20',
    iconColor: 'text-violet-600',
    borderHover: 'hover:border-violet-300',
  },
  brand: {
    iconBg: 'bg-[#2E3B2A]/10 ring-1 ring-[#2E3B2A]/20',
    iconColor: 'text-[#2E3B2A]',
    borderHover: 'hover:border-[#2E3B2A]/40',
  },
  neutral: {
    iconBg: 'bg-slate-100 ring-1 ring-slate-200',
    iconColor: 'text-slate-600',
    borderHover: 'hover:border-slate-300',
  },
};

export function EnterpriseStatCard({
  label,
  value,
  subValue,
  icon: Icon,
  variant = 'brand',
  onClick,
  className = '',
}: EnterpriseStatCardProps) {
  const styles = VARIANT_STYLES[variant] || VARIANT_STYLES.brand;

  return (
    <div
      onClick={onClick}
      className={`enterprise-kpi-card flex items-center justify-between transition-all duration-200 ${
        styles.borderHover
      } ${onClick ? 'cursor-pointer' : ''} ${className}`}
    >
      <div className="space-y-1">
        <p className="text-xs font-bold uppercase tracking-wider text-slate-500 md:text-sm">
          {label}
        </p>
        <div className="flex items-baseline gap-2.5">
          <span className="text-2xl font-black tracking-tight text-slate-900 md:text-3xl tabular-nums">
            {value}
          </span>
          {subValue && (
            <span className="text-xs font-semibold text-slate-500 md:text-sm">
              {subValue}
            </span>
          )}
        </div>
      </div>

      <div
        className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl shadow-xs transition-transform duration-200 group-hover:scale-110 ${styles.iconBg} ${styles.iconColor}`}
      >
        <Icon size={22} strokeWidth={2.2} />
      </div>
    </div>
  );
}
