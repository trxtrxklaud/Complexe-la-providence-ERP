import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import { NavLink, useNavigate, useLocation } from 'react-router-dom';
import {
    LayoutDashboard, Users, LogOut, GraduationCap, Tags, Layers,
    ClipboardList, History, Wallet, Receipt, Landmark, TrendingUp, BadgePercent, Award, HeartHandshake,
    Users2, PanelLeftClose, PanelLeftOpen,
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

    // طيّ الشريط إلى شريط أيقونات — حالة عرض محضة، محفوظة بين الجلسات.
    const [collapsed, setCollapsed] = useState<boolean>(
        () => typeof localStorage !== 'undefined' && localStorage.getItem('sidebar:collapsed') === '1',
    );
    // تلميح عائم يظهر عند الطيّ فقط، مرسوم عبر portal ليتجاوز قصّ الشريط.
    const [tip, setTip] = useState<{ label: string; x: number; y: number } | null>(null);

    const toggleCollapsed = () => {
        setTip(null);
        setCollapsed((prev) => {
            const next = !prev;
            try {
                localStorage.setItem('sidebar:collapsed', next ? '1' : '0');
            } catch {
                /* قد يكون التخزين معطّلاً (خصوصية/Termux) — لا يضرّ. */
            }
            return next;
        });
    };

    const showTip = (e: React.MouseEvent | React.FocusEvent) => {
        if (!collapsed) return;
        const link = (e.target as HTMLElement).closest('a');
        if (!link) { setTip(null); return; }
        const label = (link.textContent || '').trim();
        if (!label) { setTip(null); return; }
        const r = link.getBoundingClientRect();
        setTip((prev) => (prev && prev.label === label ? prev : { label, x: r.left - 10, y: r.top + r.height / 2 }));
    };
    const hideTip = () => setTip(null);

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
            data-collapsed={collapsed ? 'true' : 'false'}
            className="app-sidebar no-print text-white flex flex-col min-h-screen shadow-xl transition-[width] duration-300 ease-in-out relative overflow-hidden"
            style={{
                width: collapsed ? 76 : 256,
                background: 'linear-gradient(180deg, #2E3B2A 0%, #26311F 55%, #1E241B 100%)',
            }}
        >
            <div aria-hidden="true" className="absolute inset-0 pointer-events-none" style={{ background: `url('https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?auto=format&fit=crop&w=800&q=80') center/cover no-repeat`, opacity: 0.09 }} />
            <div aria-hidden="true" className="absolute inset-0 pointer-events-none" style={{ background: `linear-gradient(180deg, rgba(46,59,42,0.12) 0%, rgba(30,36,27,0.32) 100%)` }} />
            <div aria-hidden="true" className="absolute inset-0 pointer-events-none opacity-[0.035]" style={{ backgroundImage: `url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")` }} />
            <div className="relative z-10 flex flex-col min-h-screen w-full">
            {/* زرّ الطيّ — يوقّع الشريط بصريًا دون المساس بأي رابط أو صلاحية. */}
            <div className={`flex items-center px-2 pt-2 ${collapsed ? 'justify-center' : 'justify-end'}`}>
                <button
                    type="button"
                    onClick={toggleCollapsed}
                    aria-label={collapsed ? 'توسيع القائمة' : 'طيّ القائمة'}
                    aria-expanded={!collapsed}
                    className="rounded-lg p-2 text-white/60 transition-colors hover:bg-white/10 hover:text-white"
                >
                    {collapsed ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}
                </button>
            </div>

            {/* الشعار — لوحة عاجية تُبقي هوية المدرسة ظاهرة دومًا فوق القائمة. */}
            <div className={`border-b border-white/10 flex flex-col items-center gap-3 ${collapsed ? 'px-2 pb-4 pt-1' : 'px-5 pb-5 pt-1'}`}>
                <div
                    data-logo-plaque
                    className={`rounded-2xl bg-white shadow-lg ring-1 ring-brass/25 overflow-hidden flex items-center justify-center ${collapsed ? 'p-1 w-14 h-14' : 'p-1 w-full h-20'}`}
                >
                    <img
                        src="/image/logo.jpg"
                        alt="مدرسة العناية — Complexe La Providence"
                        className="w-full h-full object-contain"
                        onError={(e) => {
                            const plaque = e.currentTarget.closest('[data-logo-plaque]');
                            if (plaque) (plaque as HTMLElement).style.display = 'none';
                        }}
                    />
                </div>
                {!collapsed && (
                    <h1 className="font-display text-lg font-bold text-center tracking-wide">
                        العناية <span className="text-sage">ERP</span>
                    </h1>
                )}
            </div>

            {/* User info */}
            {user && (
                <div className="side-userinfo px-5 py-4 border-b border-white/10">
                    <p className="text-white/90 text-sm font-medium truncate">
                        {user.first_name} {user.last_name}
                    </p>
                    <p className="text-white/50 text-xs mt-0.5 truncate">
                        {user.role?.display_name ?? 'غير محدد'}
                    </p>
                </div>
            )}

            {/* Nav */}
            <nav
                className="flex-1 p-4 space-y-2 overflow-y-auto"
                onMouseMove={showTip}
                onMouseLeave={hideTip}
                onFocusCapture={showTip}
                onBlurCapture={hideTip}
            >
                <NavLink to="/" end className={({ isActive }) => linkClass(isActive)}>
                    <LayoutDashboard size={20} />
                    <span>لوحة القيادة</span>
                </NavLink>

                {hasPermission('manage_students') && (
                    <>
                        <NavLink
                            to="/students"
                            className={() => linkClass(startsWith('/students') && !startsWith('/students/bulk-gender'))}
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
                        <NavLink
                            to="/students/bulk-gender"
                            className={({ isActive }) => linkClass(isActive || startsWith('/students/bulk-gender'))}
                        >
                            <Users2 size={20} />
                            <span>تحديد الجنس</span>
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

                {hasPermission('manage_payments') && (
                    <NavLink
                        to="/old-debt-collect"
                        className={({ isActive }) => linkClass(isActive || startsWith('/old-debt-collect'))}
                    >
                        <Wallet size={20} />
                        <span>استخلاص الديون القديمة</span>
                    </NavLink>
                )}

                {/* الأرصدة الافتتاحية — ديون التلاميذ ومستحقات الإطارات القديمة.
                    الإدخال لا يحرّك مالاً (manage_treasury) والتقارير قراءة (view_reports). */}
                {canAny('manage_treasury', 'view_reports') && (
                    <NavLink
                        to="/opening-balances"
                        className={({ isActive }) => linkClass(isActive || startsWith('/opening-balances'))}
                    >
                        <Landmark size={20} />
                        <span>الأرصدة الافتتاحية</span>
                    </NavLink>
                )}

                {hasPermission('view_reports') && (
                    <>
                        <NavLink
                            to="/reports/old-debts"
                            className={({ isActive }) => linkClass(isActive || startsWith('/reports/old-debts'))}
                        >
                            <Receipt size={20} />
                            <span>كشف الديون القديمة</span>
                        </NavLink>
                        <NavLink
                            to="/reports/employee-liabilities"
                            className={({ isActive }) => linkClass(isActive || startsWith('/reports/employee-liabilities'))}
                        >
                            <Receipt size={20} />
                            <span>مستحقات الإطارات القديمة</span>
                        </NavLink>
                    </>
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
                        to="/discounts"
                        className={({ isActive }) => linkClass(isActive || startsWith('/discounts'))}
                    >
                        <BadgePercent size={20} />
                        <span>التخفيضات</span>
                    </NavLink>
                )}

                {canAny('waive_fees', 'manage_students', 'manage_payments') && (
                    <NavLink
                        to="/exemptions"
                        className={({ isActive }) => linkClass(isActive || startsWith('/exemptions'))}
                    >
                        <HeartHandshake size={20} />
                        <span>إدارة الإعفاءات</span>
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
                    title={collapsed ? 'تسجيل الخروج' : undefined}
                    className="side-logout flex items-center gap-3 px-4 py-3 w-full rounded-xl text-white/70 hover:bg-white/10 hover:text-red-300 transition-colors"
                >
                    <LogOut size={20} />
                    <span className="font-medium">تسجيل الخروج</span>
                </button>
            </div>

            {/* تلميح الوضع المطويّ — يقرأ نصّ الرابط كما هو، بلا تكرار للسلاسل. */}
            {collapsed && tip && createPortal(
                <div
                    style={{ position: 'fixed', left: tip.x, top: tip.y, transform: 'translate(-100%, -50%)', zIndex: 60 }}
                    className="no-print pointer-events-none rounded-lg bg-[#1E241B] px-2.5 py-1 text-xs font-medium text-white shadow-lg"
                >
                    {tip.label}
                </div>,
                document.body,
            )}
            </div>
        </aside>
    );
}
