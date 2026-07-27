import { NavLink } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';

export interface SectionTab {
  to: string;
  label: string;
  icon?: LucideIcon;
  end?: boolean;
}

/**
 * شريط تبويب فرعي موحّد يُستخدم داخل أي موديول مالي.
 * المسارات نسبية (relative) فتُحلّ تلقائياً ضمن مسار الموديول الأب.
 */
export function SectionTabs({ tabs }: { tabs: SectionTab[] }) {
  return (
    <div
      className="flex flex-wrap gap-1 mb-6 border-b"
      style={{ borderColor: '#EDF1E8' }}
    >
      {tabs.map((t) => {
        const Icon = t.icon;
        return (
          <NavLink
            key={t.to}
            to={t.to}
            end={t.end}
            className={({ isActive }) =>
              `flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-t-xl border-b-2 -mb-px transition-colors ${
                isActive
                  ? 'text-[#3B4A36] border-[#3B4A36] bg-white'
                  : 'text-[#7C8677] border-transparent hover:text-[#3B4A36] hover:bg-white/60'
              }`
            }
          >
            {Icon ? <Icon size={16} /> : null}
            <span>{t.label}</span>
          </NavLink>
        );
      })}
    </div>
  );
}
