import { Outlet } from 'react-router-dom';
import { Landmark, History, Banknote, TrendingUp } from 'lucide-react';
import { SectionTabs } from '../../components/SectionTabs';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677' };

/**
 * موديول الخزينة — سجل الحركات والسحوبات وكشف الدخل الصافي.
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
            <p className="text-sm" style={{ color: C.muted }}>حركات الخزينة والسحوبات والكشوف المالية</p>
          </div>
        </div>
        <SectionTabs
          tabs={[
            { to: 'history', label: 'سجل الحركات', icon: History },
            { to: 'withdrawals', label: 'السحوبات', icon: Banknote },
            { to: 'net-income', label: 'الدخل الصافي', icon: TrendingUp },
          ]}
        />
      </div>
      <Outlet />
    </div>
  );
}
