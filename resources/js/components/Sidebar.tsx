import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import { NavLink, useNavigate, useLocation } from 'react-router-dom';
import {
    LayoutDashboard, Users, LogOut, GraduationCap, Tags, Layers,
    ClipboardList, History, Wallet, Receipt, Landmark, TrendingUp, BadgePercent, Award, HeartHandshake,
    Users2, PanelLeftClose, PanelLeftOpen, CreditCard, ShieldCheck,
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

interface SectionHeaderProps {
    title: string;
    collapsed: boolean;
}

function SectionHeader({ title, collapsed }: SectionHeaderProps) {
    if (collapsed) {
        return (
            <div className="py-2 flex items-center justify-center my-1">
                <div className="w-8 h-[1px] bg-gradient-to-r from-transparent via-white/20 to-transparent" />
            </div>
        );
    }
    return (
        <div className="pt-5 pb-2 px-3 mt-1">
            <div className="flex items-center gap-2">
                <span className="w-1.5 h-3.5 rounded-full bg-[#E5D7A7] shadow-sm shadow-[#E5D7A7]/50" />
                <span className="text-[13px] font-extrabold text-[#F7F4E9] tracking-wider uppercase drop-shadow-sm select-none">
                    {title}
                </span>
                <div className="flex-1 h-[1px] bg-gradient-to-l from-transparent via-white/15 to-white/5 mr-2" />
            </div>
        </div>
    );
}

interface NavItemProps {
    to: string;
    label: string;
    icon: React.ReactNode;
    isActive?: boolean;
    onMouseEnter?: () => void;
    onFocus?: () => void;
    end?: boolean;
    collapsed: boolean;
}

function NavItem({ to, label, icon, isActive, onMouseEnter, onFocus, end, collapsed }: NavItemProps) {
    return (
        <NavLink
            to={to}
            end={end}
            onMouseEnter={onMouseEnter}
            onFocus={onFocus}
            className={({ isActive: routerActive }) => {
                const active = isActive !== undefined ? isActive : routerActive;
                if (collapsed) {
                    return `group relative flex items-center justify-center w-11 h-11 mx-auto my-1 rounded-xl transition-all duration-200 ${
                        active
                            ? 'bg-gradient-to-br from-[#F5EDD8] to-[#FFF9EC] text-[#1B2616] font-bold shadow-lg shadow-black/30 ring-1 ring-white/40'
                            : 'text-[#E5E0CF]/75 hover:bg-white/[0.12] hover:text-white active:scale-95'
                    }`;
                }
                return `group relative flex items-center gap-3.5 px-3.5 py-2.5 my-1 rounded-xl text-[14.5px] transition-all duration-200 ${
                    active
                        ? 'bg-gradient-to-l from-[#F5EDD8] via-[#FAF4E5] to-[#FFFFFF] text-[#1A2315] font-extrabold shadow-md shadow-black/20 ring-1 ring-white/35'
                        : 'text-[#E7E2D1]/85 font-bold hover:bg-white/[0.09] hover:text-[#FFFFFF] hover:translate-x-[-2px] active:scale-[0.99]'
                }`;
            }}
        >
            {({ isActive: routerActive }) => {
                const active = isActive !== undefined ? isActive : routerActive;
                return (
                    <>
                        {/* RTL Active Accent Indicator Bar */}
                        {active && !collapsed && (
                            <span className="absolute right-0 top-2 bottom-2 w-1.5 rounded-l-full bg-[#1B4332] shadow-sm" />
                        )}
                        <span
                            className={`flex items-center justify-center transition-transform duration-200 group-hover:scale-105 ${
                                collapsed
                                    ? ''
                                    : active
                                    ? 'p-1.5 rounded-lg bg-[#1A2315]/10 text-[#1B4332]'
                                    : 'p-1.5 rounded-lg text-[#E7E2D1]/70 group-hover:text-white group-hover:bg-white/5'
                            }`}
                        >
                            {icon}
                        </span>
                        {!collapsed && (
                            <span className="truncate tracking-tight flex-1">
                                {label}
                            </span>
                        )}
                    </>
                );
            }}
        </NavLink>
    );
}

export function Sidebar() {
    const { logout, user, hasPermission, isCashier } = useAuth();
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

    const startsWith = (prefix: string) => location.pathname.startsWith(prefix);
    const prefetch = (loader: () => Promise<unknown>) => () => { void loader(); };

    /** يكفي امتلاك واحدة — مطابق لـ anyOf في ProtectedRoute. */
    const canAny = (...names: string[]) => names.some((name) => hasPermission(name));

    // القائمة تعكس ما يستطيع المستخدم فتحه فعلاً.
    const showSetup = hasPermission('manage_users');
    const showFinance = canAny('manage_payments', 'manage_expenses', 'manage_treasury', 'view_reports');
    const showHr = canAny('manage_employees', 'manage_salaries');

    return (
        <aside
            data-collapsed={collapsed ? 'true' : 'false'}
            className="app-sidebar no-print text-white flex flex-col min-h-screen shadow-2xl transition-[width] duration-300 ease-in-out relative overflow-hidden border-l border-white/10 select-none"
            style={{
                width: collapsed ? 78 : 268,
                background: 'linear-gradient(180deg, #243021 0%, #1D261A 50%, #151D13 100%)',
            }}
        >
            {/* Background Texture & Glow */}
            <div aria-hidden="true" className="absolute inset-0 pointer-events-none" style={{ background: `url('https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?auto=format&fit=crop&w=800&q=80') center/cover no-repeat`, opacity: 0.07 }} />
            <div aria-hidden="true" className="absolute inset-0 pointer-events-none" style={{ background: `linear-gradient(180deg, rgba(36,48,33,0.2) 0%, rgba(21,29,19,0.5) 100%)` }} />
            <div aria-hidden="true" className="absolute inset-0 pointer-events-none opacity-[0.03]" style={{ backgroundImage: `url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")` }} />

            <div className="relative z-10 flex flex-col min-h-screen w-full">
                {/* زرّ الطيّ */}
                <div className={`flex items-center px-3 pt-2.5 ${collapsed ? 'justify-center' : 'justify-end'}`}>
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        aria-label={collapsed ? 'توسيع القائمة' : 'طيّ القائمة'}
                        aria-expanded={!collapsed}
                        className="rounded-xl p-2 text-white/60 transition-all hover:bg-white/10 hover:text-white active:scale-95"
                    >
                        {collapsed ? <PanelLeftOpen size={19} /> : <PanelLeftClose size={19} />}
                    </button>
                </div>

                {/* الشعار — لوحة ملكية بحواف فخمة */}
                <div className={`border-b border-white/10 flex flex-col items-center gap-2.5 ${collapsed ? 'px-2 pb-3.5 pt-1' : 'px-4 pb-4 pt-1'}`}>
                    <div
                        data-logo-plaque
                        className={`relative rounded-2xl p-1 shadow-xl border transition-all duration-300 hover:scale-[1.02] flex items-center justify-center ${collapsed ? 'w-13 h-13' : 'w-full h-22'}`}
                        style={{
                            background: 'linear-gradient(135deg, #1B4332 0%, #2D6A4F 50%, #065F46 100%)',
                            borderColor: '#E6DCB8',
                            boxShadow: '0 8px 25px -4px rgba(27, 67, 50, 0.45), 0 0 12px 1px rgba(245, 158, 11, 0.2)',
                        }}
                    >
                        <div className="w-full h-full rounded-[18px] overflow-hidden bg-white/95 p-1.5 flex items-center justify-center shadow-inner">
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
                    </div>
                    {!collapsed && (
                        <h1 className="font-display text-xl font-extrabold text-center tracking-wide text-[#F5EDD8] drop-shadow-sm">
                            العناية <span className="text-[#D3E0C8] font-black">ERP</span>
                        </h1>
                    )}
                </div>

                {/* User info status */}
                {user && (
                    <div className={`border-b border-white/10 ${collapsed ? 'px-2 py-2 flex justify-center' : 'px-4 py-2.5'}`}>
                        <div className="flex items-center gap-2 text-xs text-white/70">
                            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse shadow-sm shadow-emerald-400/80" />
                            {!collapsed && (
                                <span className="font-bold text-white/80 truncate">
                                    {user.first_name ? `${user.first_name} ${user.last_name || ''}` : 'متصل الآن'}
                                </span>
                            )}
                        </div>
                    </div>
                )}

                {/* Navigation Links */}
                <nav
                    className="flex-1 px-3 py-2 space-y-1 overflow-y-auto"
                    onMouseMove={showTip}
                    onMouseLeave={hideTip}
                    onFocusCapture={showTip}
                    onBlurCapture={hideTip}
                >
                    <NavItem
                        to="/"
                        end
                        label="لوحة القيادة"
                        icon={<LayoutDashboard size={20} />}
                        collapsed={collapsed}
                    />

                    {hasPermission('manage_students') && (
                        <>
                            <NavItem
                                to={isCashier ? '/students/enroll' : '/students'}
                                isActive={startsWith('/students') && !startsWith('/students/bulk-gender')}
                                label="التلاميذ"
                                icon={<GraduationCap size={20} />}
                                collapsed={collapsed}
                            />
                            <NavItem
                                to="/families"
                                isActive={startsWith('/families')}
                                label="العائلات"
                                icon={<Users size={20} />}
                                collapsed={collapsed}
                            />
                            {!isCashier && (
                                <NavItem
                                    to="/students/bulk-gender"
                                    isActive={startsWith('/students/bulk-gender')}
                                    label="تحديد الجنس"
                                    icon={<Users2 size={20} />}
                                    collapsed={collapsed}
                                />
                            )}
                        </>
                    )}

                    {/* ─── الإعداد ─── */}
                    {showSetup && (
                        <>
                            <SectionHeader title="الإعداد والنظام" collapsed={collapsed} />

                            <NavItem
                                to="/classrooms"
                                onMouseEnter={prefetch(loadClassroomsPage)}
                                onFocus={prefetch(loadClassroomsPage)}
                                isActive={startsWith('/classrooms')}
                                label="المستويات والأقسام"
                                icon={<Layers size={20} />}
                                collapsed={collapsed}
                            />

                            <NavItem
                                to="/rosters"
                                onMouseEnter={prefetch(loadRosterPage)}
                                onFocus={prefetch(loadRosterPage)}
                                isActive={startsWith('/rosters')}
                                label="قوائم الأقسام"
                                icon={<ClipboardList size={20} />}
                                collapsed={collapsed}
                            />

                            <NavItem
                                to="/users"
                                onMouseEnter={prefetch(loadUsersList)}
                                onFocus={prefetch(loadUsersList)}
                                isActive={startsWith('/users')}
                                label="إدارة المستخدمين"
                                icon={<ShieldCheck size={20} />}
                                collapsed={collapsed}
                            />

                            <NavItem
                                to="/audit-logs"
                                isActive={startsWith('/audit-logs')}
                                label="سجل العمليات"
                                icon={<ClipboardList size={20} />}
                                collapsed={collapsed}
                            />
                        </>
                    )}

                    {/* ─── الأقسام المالية ─── */}
                    {showFinance && (
                        <>
                            <SectionHeader title="المالية والاستخلاص" collapsed={collapsed} />

                            {/* الاستخلاص */}
                            {hasPermission('manage_payments') && (
                                <NavItem
                                    to="/collection"
                                    onMouseEnter={prefetch(loadCollectionPage)}
                                    onFocus={prefetch(loadCollectionPage)}
                                    isActive={startsWith('/collection')}
                                    label="الاستخلاص"
                                    icon={<CreditCard size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* أنواع المعاليم */}
                            {hasPermission('manage_users') && (
                                <NavItem
                                    to="/fee-types"
                                    onMouseEnter={prefetch(loadFeeTypesPage)}
                                    onFocus={prefetch(loadFeeTypesPage)}
                                    isActive={startsWith('/fee-types')}
                                    label="أنواع المعاليم"
                                    icon={<Tags size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* المداخيل */}
                            {hasPermission('view_reports') && (
                                <NavItem
                                    to="/income"
                                    isActive={startsWith('/income') && !startsWith('/income/unpaid-monthly')}
                                    label="المداخيل"
                                    icon={<Wallet size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* ما تم استخلاصه */}
                            {hasPermission('manage_payments') && (
                                <NavItem
                                    to="/my-collections"
                                    isActive={startsWith('/my-collections')}
                                    label="ما تم استخلاصه"
                                    icon={<ClipboardList size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* متخلدات النوادي */}
                            {canAny('manage_payments', 'view_reports') && !isCashier && (
                                <NavItem
                                    to="/reports/club-arrears"
                                    isActive={startsWith('/reports/club-arrears')}
                                    label="Dashboard متخلدات النوادي"
                                    icon={<Wallet size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* معلوم النوادي */}
                            {canAny('manage_payments', 'view_reports') && !isCashier && (
                                <NavItem
                                    to="/reports/club-fees"
                                    isActive={startsWith('/reports/club-fees')}
                                    label="معلوم النوادي"
                                    icon={<Award size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* إدارة النوادي */}
                            {hasPermission('manage_students') && !isCashier && (
                                <NavItem
                                    to="/clubs"
                                    isActive={startsWith('/clubs')}
                                    label="إدارة النوادي"
                                    icon={<Users size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* المتخلفون شهريًا */}
                            {hasPermission('view_reports') && (
                                <NavItem
                                    to="/income/unpaid-monthly"
                                    isActive={startsWith('/income/unpaid-monthly')}
                                    label="المتخلفون شهريًا"
                                    icon={<ClipboardList size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* المصاريف */}
                            {canAny('manage_expenses', 'view_reports') && (
                                <NavItem
                                    to="/expenses"
                                    onMouseEnter={prefetch(loadExpenseCreatePage)}
                                    onFocus={prefetch(loadExpenseCreatePage)}
                                    isActive={startsWith('/expenses')}
                                    label="المصاريف"
                                    icon={<Receipt size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* الخزينة */}
                            {canAny('manage_treasury', 'view_reports') && (
                                <NavItem
                                    to="/treasury"
                                    onMouseEnter={prefetch(loadTreasuryDaybookPage)}
                                    onFocus={prefetch(loadTreasuryDaybookPage)}
                                    isActive={startsWith('/treasury')}
                                    label="الخزينة"
                                    icon={<Landmark size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* استخلاص الديون القديمة */}
                            {hasPermission('manage_payments') && !isCashier && (
                                <NavItem
                                    to="/old-debt-collect"
                                    isActive={startsWith('/old-debt-collect')}
                                    label="استخلاص الديون القديمة"
                                    icon={<Wallet size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* الأرصدة الافتتاحية */}
                            {canAny('manage_treasury', 'view_reports') && (
                                <NavItem
                                    to="/opening-balances"
                                    isActive={startsWith('/opening-balances')}
                                    label="الأرصدة الافتتاحية"
                                    icon={<Landmark size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* كشف الديون القديمة */}
                            {hasPermission('view_reports') && (
                                <NavItem
                                    to="/reports/old-debts"
                                    isActive={startsWith('/reports/old-debts')}
                                    label="كشف الديون القديمة"
                                    icon={<Receipt size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* الدخل الصافي */}
                            {hasPermission('view_reports') && (
                                <NavItem
                                    to="/net-income"
                                    onMouseEnter={prefetch(loadNetIncomeDailyPage)}
                                    onFocus={prefetch(loadNetIncomeDailyPage)}
                                    isActive={startsWith('/net-income')}
                                    label="الدخل الصافي"
                                    icon={<TrendingUp size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* الوصولات الملغاة */}
                            {hasPermission('manage_payments') && !isCashier && (
                                <NavItem
                                    to="/historique"
                                    onMouseEnter={prefetch(loadHistoriquePage)}
                                    onFocus={prefetch(loadHistoriquePage)}
                                    isActive={startsWith('/historique')}
                                    label="الوصولات الملغاة"
                                    icon={<History size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* التخفيضات */}
                            {hasPermission('waive_fees') && (
                                <NavItem
                                    to="/discounts"
                                    isActive={startsWith('/discounts')}
                                    label="التخفيضات"
                                    icon={<BadgePercent size={20} />}
                                    collapsed={collapsed}
                                />
                            )}

                            {/* إدارة الإعفاءات */}
                            {canAny('waive_fees', 'manage_students', 'manage_payments') && !isCashier && (
                                <NavItem
                                    to="/exemptions"
                                    isActive={startsWith('/exemptions')}
                                    label="إدارة الإعفاءات"
                                    icon={<HeartHandshake size={20} />}
                                    collapsed={collapsed}
                                />
                            )}
                        </>
                    )}

                    {/* ─── الموارد البشرية ─── */}
                    {showHr && (
                        <>
                            <SectionHeader title="الموارد البشرية" collapsed={collapsed} />

                            <NavItem
                                to="/employees"
                                onMouseEnter={prefetch(loadEmployeesPage)}
                                onFocus={prefetch(loadEmployeesPage)}
                                isActive={startsWith('/employees')}
                                label="الإطارات"
                                icon={<Users size={20} />}
                                collapsed={collapsed}
                            />
                        </>
                    )}
                </nav>

                {/* Logout Footer */}
                <div className="p-3 border-t border-white/10">
                    <button
                        onClick={handleLogout}
                        title={collapsed ? 'تسجيل الخروج' : undefined}
                        className={`side-logout group flex items-center ${collapsed ? 'justify-center w-11 h-11 mx-auto' : 'gap-3 px-3.5 py-2.5 w-full'} rounded-xl text-white/70 hover:bg-rose-500/15 hover:text-rose-200 transition-all active:scale-95`}
                    >
                        <LogOut size={20} className="text-white/60 group-hover:text-rose-300 transition-colors" />
                        {!collapsed && <span className="font-bold text-[14px]">تسجيل الخروج</span>}
                    </button>
                </div>

                {/* تلميح الوضع المطويّ */}
                {collapsed && tip && createPortal(
                    <div
                        style={{ position: 'fixed', left: tip.x, top: tip.y, transform: 'translate(-100%, -50%)', zIndex: 70 }}
                        className="no-print pointer-events-none rounded-xl bg-[#151D13] border border-white/20 px-3 py-1.5 text-xs font-bold text-[#F5EDD8] shadow-2xl backdrop-blur-md"
                    >
                        {tip.label}
                    </div>,
                    document.body,
                )}
            </div>
        </aside>
    );
}
