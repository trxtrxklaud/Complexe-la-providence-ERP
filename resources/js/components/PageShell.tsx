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
    <section>
      <div className="flex items-center gap-3 mb-4">
        {Icon ? (
          <div
            className="w-10 h-10 rounded-2xl flex items-center justify-center"
            style={{ backgroundColor: C.sage }}
          >
            <Icon size={20} style={{ color: C.forest }} />
          </div>
        ) : null}
        <div>
          <h2 className="text-lg font-bold" style={{ color: C.ink }}>{title}</h2>
          {subtitle ? <p className="text-sm" style={{ color: C.muted }}>{subtitle}</p> : null}
        </div>
      </div>

      {children ?? (
        <div
          className="rounded-2xl bg-white p-10 text-center"
          style={{ border: `1px dashed ${C.line}` }}
        >
          <p className="text-sm font-medium" style={{ color: C.muted }}>
            {note ?? 'هذا القسم جاهز هيكلياً — سيُبنى محتواه بالتفصيل في المرحلة الثانية.'}
          </p>
        </div>
      )}
    </section>
  );
}
