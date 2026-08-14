import { NavLink, useNavigate, useLocation } from 'react-router-dom';
import {
    LayoutDashboard, Users, LogOut, GraduationCap, Tags, Layers,
    ClipboardList, History, Wallet, Receipt, Landmark, TrendingUp, BadgePercent, Award,
} from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import {
    loadClassroomsPage,
    loadCollectionPage,
    loadEmployeesPage,
    loadExpenseCreatePage,
    loadFeeTypesPage,
    loadHistoriquePage,
    loadNetIncomeDailyPage,
    loadRosterPage,
    loadTreasuryDaybookPage,
    loadUsersList,
} from '../routeLoaders';

export function Sidebar() {
    const { logout, user, hasPermission } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    const handleLogout = async () => {
        try {
            // Don't block UI if logout API is slow on mobile/Termux
            await Promise.race([
                logout(),
                new Promise((resolve) => setTimeout(resolve, 1500)),
            ]);
        } finally {
            navigate('/login');
        }
    };

    const linkClass = (active: boolean) =>
        `flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
            active
                ? 'bg-white text-[#2E3B2A] font-medium shadow'
                : 'text-white/70 hover:bg-white/10 hover:text-white'
        }`;

    const startsWith = (prefix: string) => location.pathname.startsWith(prefix);
    const prefetch = (loader: () => Promise<unknown>) => () => { void loader(); };

    /** يكفي امتلاك واحدة — مطابق لـ anyOf في ProtectedRoute. */
    const canAny = (...names: string[]) => names.some((name) => hasPermission(name));

    // القائمة تعكس ما يستطيع المستخدم فتحه فعلاً. رابط يقود إلى رسالة منع
    // ليس ثغرة أمنية — الحارس الحقيقي هو routes/api.php — لكنه يُفقد الثقة.
    const showSetup = hasPermission('manage_users');
    const showFinance = canAny('manage_payments', 'manage_expenses', 'manage_treasury', 'view_reports');
    const showHr = canAny('manage_employees', 'manage_salaries');

    return (
        <aside
            className="no-print w-64 text-white flex flex-col min-h-screen shadow-xl"
            style={{ background: 'linear-gradient(178deg, #2E3B2A 0%, #26311F 100%)' }}
        >
            {/* Logo */}
            <div className="p-6 border-b border-white/10">
                <h1 className="text-xl font-bold text-center tracking-wide">
                    العناية <span className="text-[#81C784]">ERP</span>
                </h1>
            </div>

            {/* User info */}
            {user && (
                <div className="px-5 py-4 border-b border-white/10">
                    <p className="text-white/90 text-sm font-medium truncate">
                        {user.first_name} {user.last_name}
                    </p>
                    <p className="text-white/50 text-xs mt-0.5 truncate">
                        {user.role?.display_name ?? 'غير محدد'}
                    </p>
                </div>
            )}

            {/* Nav */}
            <nav className="flex-1 p-4 space-y-2 overflow-y-auto">
                <NavLink to="/" end className={({ isActive }) => linkClass(isActive)}>
                    <LayoutDashboard size={20} />
                    <span>لوحة القيادة</span>
                </NavLink>

                {hasPermission('manage_students') && (
                    <>
                        <NavLink
                            to="/students"
                            className={({ isActive }) => linkClass(isActive || startsWith('/students'))}
                        >
                            <GraduationCap size={20} />
                            <span>التلاميذ</span>
                        </NavLink>
                        <NavLink
                            to="/families"
                            className={({ isActive }) => linkClass(isActive || startsWith('/families'))}
                        >
                            <Users size={20} />
                            <span>العائلات</span>
                        </NavLink>
                    </>
                )}

                {/* ─── الإعداد ─── */}
                {showSetup && (
                    <>
                        <div className="pt-3 mt-2 border-t border-white/10" />
                        <p className="px-4 pb-1 text-[11px] font-semibold tracking-wider text-white/40">الإعداد</p>

                        <NavLink
                            to="/classrooms"
                            onMouseEnter={prefetch(loadClassroomsPage)}
                            onFocus={prefetch(loadClassroomsPage)}
                            className={({ isActive }) => linkClass(isActive || startsWith('/classrooms'))}
                        >
                            <Layers size={20} />
                            <span>المستويات والأقسام</span>
                        </NavLink>

                        <NavLink
                            to="/rosters"
                            onMouseEnter={prefetch(loadRosterPage)}
                            onFocus={prefetch(loadRosterPage)}
                            className={({ isActive }) => linkClass(isActive || startsWith('/rosters'))}
                        >
                            <ClipboardList size={20} />
                            <span>قوائم الأقسام</span>
                        </NavLink>

                        <NavLink
                            to="/users"
                            onMouseEnter={prefetch(loadUsersList)}
                            onFocus={prefetch(loadUsersList)}
                            className={({ isActive }) => linkClass(isActive || startsWith('/users'))}
                        >
                            <Users size={20} />
                            <span>إدارة المستخدمين</span>
                        </NavLink>
                    </>
                )}

                {/* ─── الأقسام المالية ─── */}
                {showFinance && (
                    <>
                        <div className="pt-3 mt-2 border-t border-white/10" />
                        <p className="px-4 pb-1 text-[11px] font-semibold tracking-wider text-white/40">المالية</p>
                    </>
                )}

                {/* أنواع المعاليم — القراءة للاستخلاص، والتعديل لإدارة المستخدمين */}
                {hasPermission('manage_users') && (
                    <NavLink
                        to="/fee-types"
                        onMouseEnter={prefetch(loadFeeTypesPage)}
                        onFocus={prefetch(loadFeeTypesPage)}
                        className={({ isActive }) => linkClass(isActive || startsWith('/fee-types'))}
                    >
                        <Tags size={20} />
                        <span>أنواع المعاليم</span>
                    </NavLink>
                )}

                {canAny('manage_payments', 'view_reports') && (
                    <NavLink
                        to="/income"
                        onMouseEnter={prefetch(loadCollectionPage)}
                        onFocus={prefetch(loadCollectionPage)}
                        className={() => linkClass(startsWith('/income') && !startsWith('/income/unpaid-monthly'))}
                    >
                        <Wallet size={20} />
                        <span>المداخيل</span>
                    </NavLink>
                )}

                {canAny('manage_payments', 'view_reports') && (
                    <NavLink
                        to="/reports/club-arrears"
                        className={({ isActive }) => linkClass(isActive || startsWith('/reports/club-arrears'))}
                    >
                        <Wallet size={20} />
                        <span>Dashboard متخلدات النوادي</span>
                    </NavLink>
                )}

                {canAny('manage_payments', 'view_reports') && (
                    <NavLink
                        to="/reports/club-fees"
                        className={({ isActive }) => linkClass(isActive || startsWith('/reports/club-fees'))}
                    >
                        <Award size={20} />
                        <span>معلوم النوادي</span>
                    </NavLink>
                )}

                {hasPermission('manage_students') && (
                    <NavLink
                        to="/clubs"
                        className={({ isActive }) => linkClass(isActive || startsWith('/clubs'))}
                    >
                        <Users size={20} />
                        <span>إدارة النوادي</span>
                    </NavLink>
                )}

                {hasPermission('view_reports') && (
                    <NavLink
                        to="/income/unpaid-monthly"
                        className={() => linkClass(startsWith('/income/unpaid-monthly'))}
                    >
                        <ClipboardList size={20} />
                        <span>المتخلفون شهريًا</span>
                    </NavLink>
                )}

                {canAny('manage_expenses', 'view_reports') && (
                    <NavLink
                        to="/expenses"
                        onMouseEnter={prefetch(loadExpenseCreatePage)}
                        onFocus={prefetch(loadExpenseCreatePage)}
                        className={({ isActive }) => linkClass(isActive || startsWith('/expenses'))}
                    >
                        <Receipt size={20} />
                        <span>المصاريف</span>
                    </NavLink>
                )}

                {canAny('manage_treasury', 'view_reports') && (
                    <NavLink
                        to="/treasury"
                        onMouseEnter={prefetch(loadTreasuryDaybookPage)}
                        onFocus={prefetch(loadTreasuryDaybookPage)}
                        className={({ isActive }) => linkClass(isActive || startsWith('/treasury'))}
                    >
                        <Landmark size={20} />
                        <span>الخزينة</span>
                    </NavLink>
                )}

                {/* الدخل الصافي — موديول مستقل لأنه يقرأ من كل المصادر لا من الخزينة وحدها */}
                {hasPermission('view_reports') && (
                    <NavLink
                        to="/net-income"
                        onMouseEnter={prefetch(loadNetIncomeDailyPage)}
                        onFocus={prefetch(loadNetIncomeDailyPage)}
                        className={({ isActive }) => linkClass(isActive || startsWith('/net-income'))}
                    >
                        <TrendingUp size={20} />
                        <span>الدخل الصافي</span>
                    </NavLink>
                )}

                {hasPermission('manage_payments') && (
                    <NavLink
                        to="/historique"
                        onMouseEnter={prefetch(loadHistoriquePage)}
                        onFocus={prefetch(loadHistoriquePage)}
                        className={({ isActive }) => linkClass(isActive || startsWith('/historique'))}
                    >
                        <History size={20} />
                        <span>الوصولات الملغاة</span>
                    </NavLink>
                )}

{hasPermission('waive_fees') && (
<NavLink
to='/discounts'
className={({ isActive }) => linkClass(isActive || startsWith('/discounts'))}
>
<BadgePercent size={20} />
<span>التخفيضات</span>
</NavLink>
)}

                {/* ─── الموارد البشرية ─── */}
                {showHr && (
                    <>
                        <div className="pt-3 mt-2 border-t border-white/10" />
                        <p className="px-4 pb-1 text-[11px] font-semibold tracking-wider text-white/40">الموارد البشرية</p>

                        <NavLink
                            to="/employees"
                            onMouseEnter={prefetch(loadEmployeesPage)}
                            onFocus={prefetch(loadEmployeesPage)}
                            className={({ isActive }) => linkClass(isActive || startsWith('/employees'))}
                        >
                            <Users size={20} />
                            <span>الإطارات</span>
                        </NavLink>
                    </>
                )}
            </nav>

            {/* Logout */}
            <div className="p-4 border-t border-white/10">
                <button
                    onClick={handleLogout}
                    className="flex items-center gap-3 px-4 py-3 w-full rounded-xl text-white/70 hover:bg-white/10 hover:text-red-300 transition-colors"
                >
                    <LogOut size={20} />
                    <span className="font-medium">تسجيل الخروج</span>
                </button>
            </div>
        </aside>
    );
}
