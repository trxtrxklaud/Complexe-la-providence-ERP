import { Outlet } from 'react-router-dom';
import { TrendingUp, CalendarDays, CalendarRange, CalendarClock } from 'lucide-react';
import { SectionTabs } from '../../components/SectionTabs';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677' };

/**
 * موديول الدخل الصافي — نفس بنية موديولي المداخيل والمصاريف:
 * تخطيط أب + تبويبات + Outlet، حتى يبقى المشروع مقروءاً لمطور جديد.
 */
export function NetIncomeLayout() {
  return (
    <div dir="rtl">
      <div className="px-6 pt-6 max-w-6xl mx-auto">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
            <TrendingUp size={22} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-xl font-bold" style={{ color: C.ink }}>الدخل الصافي</h1>
            <p className="text-sm" style={{ color: C.muted }}>مداخيل ناقص مصاريف — يومياً وشهرياً وسنوياً</p>
          </div>
        </div>
        <SectionTabs
          tabs={[
            { to: 'daily', label: 'الدخل اليومي', icon: CalendarDays },
            { to: 'monthly', label: 'الدخل الشهري', icon: CalendarRange },
            { to: 'yearly', label: 'الدخل السنوي', icon: CalendarClock },
          ]}
        />
      </div>
      <Outlet />
    </div>
  );
}
