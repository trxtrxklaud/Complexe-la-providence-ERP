import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };

interface PageShellProps {
  title: string;
  subtitle?: string;
  icon?: LucideIcon;
  /** محتوى حقيقي إن وُجد؛ وإلا يُعرض قالب المرحلة الثانية. */
  children?: ReactNode;
  note?: string;
}

/**
 * قالب صفحة موحّد للمرحلة الأولى (hierarchy واضحة وقابلة للتوسع).
 */
export function PageShell({ title, subtitle, icon: Icon, children, note }: PageShellProps) {
  return (
    <section className="space-y-6">
      <div className="flex items-center gap-3.5 mb-6">
        {Icon ? (
          <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#2E3B2A] to-[#1E271B] text-[#C2A24E] shadow-sm ring-1 ring-black/5 flex items-center justify-center shrink-0">
            <Icon size={24} strokeWidth={2.2} />
          </div>
        ) : null}
        <div>
          <h2 className="text-2xl font-extrabold tracking-tight text-slate-900">{title}</h2>
          {subtitle ? <p className="text-sm font-medium text-slate-500 mt-0.5">{subtitle}</p> : null}
        </div>
      </div>

      {children ?? (
        <div className="enterprise-card p-12 text-center">
          <p className="text-base font-semibold text-slate-500">
            {note ?? 'هذا القسم جاهز هيكلياً — سيُبنى محتواه بالتفصيل في المرحلة القادمة.'}
          </p>
        </div>
      )}
    </section>
  );
}
