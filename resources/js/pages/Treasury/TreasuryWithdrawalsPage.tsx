import { Banknote } from 'lucide-react';
import { PageShell } from '../../components/PageShell';

export function TreasuryWithdrawalsPage() {
  return (
    <div className="px-6 pb-6 max-w-6xl mx-auto">
      <PageShell
        title="سحوبات الخزينة"
        subtitle="تسجيل ومتابعة السحوبات النقدية من الخزينة"
        icon={Banknote}
      />
    </div>
  );
}
