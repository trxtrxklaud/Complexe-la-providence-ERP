import React, { type ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

interface EnterpriseHeaderProps {
  title: string;
  subtitle?: string;
  icon?: LucideIcon;
  badge?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
}

export function EnterpriseHeader({
  title,
  subtitle,
  icon: Icon,
  badge,
  actions,
  children,
}: EnterpriseHeaderProps) {
  return (
    <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div className="flex items-center gap-3.5">
        {Icon && (
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#2E3B2A] to-[#1E271B] text-[#C2A24E] shadow-sm ring-1 ring-black/5">
            <Icon size={24} strokeWidth={2.2} />
          </div>
        )}
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-2xl font-extrabold tracking-tight text-slate-900 md:text-3xl">
              {title}
            </h1>
            {badge && <div>{badge}</div>}
          </div>
          {subtitle && (
            <p className="mt-1 text-sm font-medium text-slate-500 md:text-base">
              {subtitle}
            </p>
          )}
        </div>
      </div>

      {actions && (
        <div className="flex flex-wrap items-center gap-2.5">
          {actions}
        </div>
      )}

      {children}
    </div>
  );
}
