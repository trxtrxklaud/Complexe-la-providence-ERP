import { Outlet } from 'react-router-dom';
import { Receipt, PlusCircle, CalendarDays, CalendarRange, CalendarClock } from 'lucide-react';
import { SectionTabs } from '../../components/SectionTabs';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677' };

/**
 * موديول المصاريف — إنشاء مصروف + تقارير يومية/شهرية/سنوية.
 */
export function ExpensesLayout() {
  return (
    <div dir="rtl">
      <div className="px-6 pt-6 max-w-6xl mx-auto">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
            <Receipt size={22} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-xl font-bold" style={{ color: C.ink }}>المصاريف</h1>
            <p className="text-sm" style={{ color: C.muted }}>تسجيل المصاريف ومتابعتها عبر تقارير دورية</p>
          </div>
        </div>
        <SectionTabs
          tabs={[
            { to: 'create', label: 'إنشاء مصروف', icon: PlusCircle },
            { to: 'daily', label: 'التقرير اليومي', icon: CalendarDays },
            { to: 'monthly', label: 'التقرير الشهري', icon: CalendarRange },
            { to: 'yearly', label: 'التقرير السنوي', icon: CalendarClock },
          ]}
        />
      </div>
      <Outlet />
    </div>
  );
}
