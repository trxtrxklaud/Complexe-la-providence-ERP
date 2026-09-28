import React from 'react';
import { useLocation, Link, useNavigate } from 'react-router-dom';
import {
  Calendar,
  Sparkles,
  ChevronLeft,
  LogOut,
  User as UserIcon,
  Shield,
  Layers,
  Search,
  CheckCircle2,
} from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import { useMasterData } from '../contexts/MasterDataContext';

const ROUTE_LABELS: Record<string, string> = {
  students: 'التلاميذ',
  enroll: 'معالج التسجيل',
  search: 'البحث عن تلميذ',
  income: 'المداخيل',
  'by-date': 'المداخيل باليوم',
  'by-classroom': 'مداخيل الأقسام',
  'by-year': 'المداخيل السنوية',
  'unpaid-monthly': 'المتخلفون شهرياً',
  'payment-status': 'حالة السداد',
  expenses: 'المصاريف',
  create: 'تسجيل مصروف',
  daily: 'المصاريف اليومية',
  monthly: 'المصاريف الشهرية',
  treasury: 'الخزينة',
  daybook: 'دفتر الصندوق اليومي',
  withdrawals: 'سحوبات الخزينة',
  history: 'حركات الصندوق',
  collection: 'الاستخلاص',
  families: 'العائلات',
  historique: 'سجل المقبوضات',
  'my-collections': 'مقبوضاتي',
  employees: 'الموظفون والرواتب',
  clubs: 'النوادي',
  discounts: 'التخفيضات السنوية',
  'monthly-discounts': 'التخفيضات الشهرية',
  exemptions: 'الإعفاءات',
  users: 'المستخدمون',
  admin: 'الإدارة',
  'teacher-sections': 'أقسام المعلمين',
  'fee-types': 'أنواع الرسوم',
  classrooms: 'الأقسام المدرسية',
  roster: 'القوائم الاسمية',
};

export function Topbar() {
  const { user, logout } = useAuth();
  const { data: masterData } = useMasterData();
  const location = useLocation();
  const navigate = useNavigate();

  const activeYear = masterData?.academicYears?.find((y) => y.is_active);

  // Generate breadcrumbs from current path
  const pathSegments = location.pathname.split('/').filter(Boolean);

  const roleName = user?.role?.name_ar || user?.role?.name || (user?.is_admin ? 'مدير عام' : 'مستخدم');

  // Format today's Arabic date
  const todayFormatted = new Intl.DateTimeFormat('ar-TN', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date());

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b border-slate-200/75 bg-white/85 px-6 backdrop-blur-md transition-all duration-200 shadow-xs">
      {/* ── Right side: Breadcrumbs & Academic Year Badge ── */}
      <div className="flex items-center gap-4">
        {/* Breadcrumb Path */}
        <nav aria-label="Breadcrumb" className="flex items-center gap-1.5 text-sm font-semibold text-slate-500">
          <Link
            to="/"
            className="flex items-center gap-1.5 rounded-lg px-2 py-1 text-slate-600 transition hover:bg-slate-100 hover:text-[#1B4332]"
          >
            <Layers size={16} className="text-[#1B4332]" />
            <span>الرئيسية</span>
          </Link>

          {pathSegments.map((segment, idx) => {
            const isLast = idx === pathSegments.length - 1;
            const routeUrl = '/' + pathSegments.slice(0, idx + 1).join('/');
            const label = ROUTE_LABELS[segment] || segment;

            return (
              <React.Fragment key={routeUrl}>
                <ChevronLeft size={14} className="text-slate-300 rtl:rotate-0" />
                {isLast ? (
                  <span className="rounded-lg bg-emerald-50/80 px-2.5 py-1 font-bold text-[#1B4332]">
                    {label}
                  </span>
                ) : (
                  <Link
                    to={routeUrl}
                    className="rounded-lg px-2 py-1 text-slate-600 transition hover:bg-slate-100 hover:text-[#1B4332]"
                  >
                    {label}
                  </Link>
                )}
              </React.Fragment>
            );
          })}
        </nav>

        {/* Academic Year Pill */}
        {activeYear && (
          <div className="hidden lg:flex items-center gap-2 rounded-full border border-emerald-200/80 bg-gradient-to-r from-emerald-50 via-white to-emerald-50/50 px-3 py-1 text-xs font-bold text-emerald-900 shadow-2xs">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
              <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-600"></span>
            </span>
            <span className="text-emerald-700">السنة الدراسية:</span>
            <span className="font-extrabold text-emerald-950">{activeYear.name}</span>
          </div>
        )}
      </div>

      {/* ── Left side: Date, Quick Links & User Badge ── */}
      <div className="flex items-center gap-3.5">
        {/* Today's Date */}
        <div className="hidden xl:flex items-center gap-2 rounded-xl bg-slate-100/70 px-3 py-1.5 text-xs font-semibold text-slate-600">
          <Calendar size={14} className="text-[#C2A24E]" />
          <span>{todayFormatted}</span>
        </div>

        {/* User Card Pill */}
        <div className="flex items-center gap-3 rounded-2xl border border-slate-200/80 bg-gradient-to-r from-white to-slate-50/80 p-1.5 pl-3 shadow-2xs">
          <div className="relative flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-[#1B4332] to-[#2D6A4F] text-white font-bold text-sm shadow-xs ring-2 ring-[#C2A24E]/30">
            {user?.name ? user.name.slice(0, 1) : <UserIcon size={16} />}
            <span className="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500" />
          </div>

          <div className="text-right">
            <p className="text-xs font-black text-slate-900 leading-tight">
              {user?.name || user?.username || 'المستخدم'}
            </p>
            <p className="text-[10px] font-bold text-[#8A6D2B]">
              {roleName}
            </p>
          </div>

          <button
            type="button"
            onClick={handleLogout}
            title="تسجيل الخروج"
            className="mr-1 rounded-xl p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 active:scale-95"
          >
            <LogOut size={16} />
          </button>
        </div>
      </div>
    </header>
  );
}
