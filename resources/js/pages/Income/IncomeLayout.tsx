import { Outlet } from 'react-router-dom';
import { Wallet, CreditCard, CalendarDays, TrendingUp, Layers, CalendarRange } from 'lucide-react';
import { SectionTabs } from '../../components/SectionTabs';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677' };

/**
 * موديول المداخيل — تخطيط أب مع تبويب فرعي و Outlet للصفحات الداخلية.
 */
export function IncomeLayout() {
  return (
    <div dir="rtl">
      <div className="px-6 pt-6 max-w-6xl mx-auto">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
            <Wallet size={22} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-xl font-bold" style={{ color: C.ink }}>المداخيل</h1>
            <p className="text-sm" style={{ color: C.muted }}>إدارة مداخيل التلاميذ والاستخلاص والتقارير</p>
          </div>
        </div>
        <SectionTabs
          tabs={[
            { to: 'billing', label: 'الفوترة والاستخلاص', icon: CreditCard },
            { to: 'by-date', label: 'المداخيل حسب التاريخ', icon: CalendarDays },
            { to: 'revenue', label: 'مداخيل التلاميذ', icon: TrendingUp },
            { to: 'by-classroom', label: 'المداخيل حسب القسم', icon: Layers },
            { to: 'by-year', label: 'المداخيل حسب السنة', icon: CalendarRange },
          ]}
        />
      </div>
      <Outlet />
    </div>
  );
}
