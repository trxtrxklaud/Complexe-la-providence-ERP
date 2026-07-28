import { Outlet } from 'react-router-dom';
import { Landmark, History, Banknote, CalendarRange } from 'lucide-react';
import { SectionTabs } from '../../components/SectionTabs';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677' };

/**
 * موديول الخزينة — الكشف اليومي وسجل الحركات والسحوبات.
 *
 * كشف الدخل الصافي انتقل إلى موديول مستقل (/net-income) لأنه يقرأ من المداخيل
 * والمصاريف والأجور والسلف معاً، لا من الخزينة وحدها.
 *
 * أمّا «الكشف اليومي» فموضعه هنا لأنه حركة الدرج نفسه: ما دخل وما خرج
 * وما بقي فيه آخر كل يوم، وهو الكشف الذي يُطبع ويُوقّع.
 */
export function TreasuryLayout() {
  return (
    <div dir="rtl">
      <div className="px-6 pt-6 max-w-6xl mx-auto">
        <div className="flex items-center gap-3 mb-5">
          <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ backgroundColor: C.sage }}>
            <Landmark size={22} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-xl font-bold" style={{ color: C.ink }}>الخزينة</h1>
            <p className="text-sm" style={{ color: C.muted }}>الكشف اليومي وحركات الخزينة والسحوبات</p>
          </div>
        </div>
        <SectionTabs
          tabs={[
            { to: 'daybook', label: 'الكشف اليومي', icon: CalendarRange },
            { to: 'history', label: 'سجل الحركات', icon: History },
            { to: 'withdrawals', label: 'السحوبات', icon: Banknote },
          ]}
        />
      </div>
      <Outlet />
    </div>
  );
}
