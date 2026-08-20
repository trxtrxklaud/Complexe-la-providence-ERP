import React, { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Sidebar } from './components/Sidebar';
import { Login } from './pages/Login';
import { AuthProvider } from './contexts/AuthContext';
import { ProtectedRoute } from './components/ProtectedRoute';
import { StudentsDashboard } from './pages/Students/StudentsDashboard';
import { EnrollWizard } from './pages/Students/EnrollWizard';
import Dashboard from './pages/Dashboard/Dashboard';
import { IncomeLayout } from './pages/Income/IncomeLayout';
import { ExpensesLayout } from './pages/Expenses/ExpensesLayout';
import { TreasuryLayout } from './pages/Treasury/TreasuryLayout';
import { NetIncomeLayout } from './pages/NetIncome/NetIncomeLayout';
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
} from './routeLoaders';

const UsersList = lazy(() => loadUsersList().then((module) => ({ default: module.UsersList })));
const DiscountsPage = lazy(() => import('./pages/Discounts/DiscountsPage').then((module) => ({ default: module.DiscountsPage })));
const MonthlyDiscountsPage = lazy(() => import('./pages/Discounts/MonthlyDiscountsPage').then((module) => ({ default: module.MonthlyDiscountsPage })));
const ExemptionsPage = lazy(() => import('./pages/Exemptions/ExemptionsPage').then((module) => ({ default: module.ExemptionsPage })));

const ClubFeesReportPage = lazy(() => import('./pages/Reports/ClubFeesReportPage'));
const ClubArrearsDashboardPage = lazy(() => import('./pages/Reports/ClubArrearsDashboardPage'));
const ClubsPage = lazy(() => import('./pages/Clubs/ClubsPage'));
const UserForm = lazy(() => import('./pages/Users/UserForm').then((module) => ({ default: module.UserForm })));
const NewStudentWizard = lazy(() => import('./pages/Students/NewStudentWizard').then((module) => ({ default: module.NewStudentWizard })));
const OldStudentReenroll = lazy(() => import('./pages/Students/OldStudentReenroll').then((module) => ({ default: module.OldStudentReenroll })));
const StudentSearchPage = lazy(() => import('./pages/Students/StudentSearchPage').then((module) => ({ default: module.StudentSearchPage })));
const StudentDetailsPage = lazy(() => import('./pages/Students/StudentDetailsPage').then((module) => ({ default: module.StudentDetailsPage })));
const StudentTransferPage = lazy(() => import('./pages/Students/StudentTransferPage').then((module) => ({ default: module.StudentTransferPage })));
const FeeTypesPage = lazy(() => loadFeeTypesPage().then((module) => ({ default: module.FeeTypesPage })));
const EmployeesPage = lazy(() => loadEmployeesPage().then((module) => ({ default: module.EmployeesPage })));
const CollectionPage = lazy(() => loadCollectionPage().then((module) => ({ default: module.CollectionPage })));
const FamiliesListPage = lazy(() => import('./pages/Families/FamiliesListPage').then((m) => ({ default: m.FamiliesListPage })));
const FamilyDetailPage = lazy(() => import('./pages/Families/FamilyDetailPage').then((m) => ({ default: m.FamilyDetailPage })));
const HistoriquePage = lazy(() => loadHistoriquePage().then((module) => ({ default: module.HistoriquePage })));
const ClassroomsPage = lazy(() => loadClassroomsPage().then((module) => ({ default: module.ClassroomsPage })));
const RosterPage = lazy(() => loadRosterPage().then((module) => ({ default: module.RosterPage })));
const IncomeByDatePage = lazy(() => import('./pages/Income/IncomeByDatePage').then((module) => ({ default: module.IncomeByDatePage })));
const StudentRevenuePage = lazy(() => import('./pages/Income/StudentRevenuePage').then((module) => ({ default: module.StudentRevenuePage })));
const StudentDetailPage = lazy(() => import('./pages/Income/StudentDetailPage').then((module) => ({ default: module.StudentDetailPage })));
const RevenueByClassroomPage = lazy(() => import('./pages/Income/RevenueByClassroomPage').then((module) => ({ default: module.RevenueByClassroomPage })));
const ClassroomDetailPage = lazy(() => import('./pages/Income/ClassroomDetailPage').then((module) => ({ default: module.ClassroomDetailPage })));
const ClassroomRosterPage = lazy(() => import('./pages/Income/ClassroomRosterPage').then((module) => ({ default: module.ClassroomRosterPage })));
const RevenueByYearPage = lazy(() => import('./pages/Income/RevenueByYearPage').then((module) => ({ default: module.RevenueByYearPage })));
const UnpaidMonthlyReportPage = lazy(() => import('./pages/Income/UnpaidMonthlyReportPage').then((module) => ({ default: module.UnpaidMonthlyReportPage })));
const ExpenseCreatePage = lazy(() => loadExpenseCreatePage().then((module) => ({ default: module.ExpenseCreatePage })));
const ExpenseDailyReportPage = lazy(() => import('./pages/Expenses/ExpenseDailyReportPage').then((module) => ({ default: module.ExpenseDailyReportPage })));
const ExpenseMonthlyReportPage = lazy(() => import('./pages/Expenses/ExpenseMonthlyReportPage').then((module) => ({ default: module.ExpenseMonthlyReportPage })));
const ExpenseYearlyReportPage = lazy(() => import('./pages/Expenses/ExpenseYearlyReportPage').then((module) => ({ default: module.ExpenseYearlyReportPage })));
const TreasuryDaybookPage = lazy(() => loadTreasuryDaybookPage().then((module) => ({ default: module.TreasuryDaybookPage })));
const TreasuryHistoryPage = lazy(() => import('./pages/Treasury/TreasuryHistoryPage').then((module) => ({ default: module.TreasuryHistoryPage })));
const TreasuryWithdrawalsPage = lazy(() => import('./pages/Treasury/TreasuryWithdrawalsPage').then((module) => ({ default: module.TreasuryWithdrawalsPage })));
const NetIncomeDailyPage = lazy(() => loadNetIncomeDailyPage().then((module) => ({ default: module.NetIncomeDailyPage })));
const NetRevenueMonthlyPage = lazy(() => import('./pages/NetIncome/NetRevenueMonthlyPage').then((module) => ({ default: module.NetRevenueMonthlyPage })));
const NetRevenueYearlyPage = lazy(() => import('./pages/NetIncome/NetRevenueYearlyPage').then((module) => ({ default: module.NetRevenueYearlyPage })));

function RouteContentSkeleton() {
    return (
        <div className="p-6 md:p-8 animate-pulse" aria-label="جاري تحميل المحتوى" role="status">
            <div className="max-w-6xl mx-auto space-y-6">
                <div className="flex items-center gap-4">
                    <div className="h-12 w-12 rounded-2xl bg-[#DCE5D5]" />
                    <div className="space-y-2">
                        <div className="h-5 w-44 rounded bg-slate-300/70" />
                        <div className="h-3 w-64 rounded bg-slate-200" />
                    </div>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="h-28 rounded-2xl bg-white/80 border border-white" />
                    <div className="h-28 rounded-2xl bg-white/80 border border-white" />
                    <div className="h-28 rounded-2xl bg-white/80 border border-white" />
                </div>
                <div className="h-72 rounded-2xl bg-white/80 border border-white" />
            </div>
        </div>
    );
}

function Layout({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex min-h-screen" style={{ backgroundColor: '#E9EEE3' }}>
            <Sidebar />
            <main className="flex-1 overflow-x-hidden">
                <Suspense fallback={<RouteContentSkeleton />}>{children}</Suspense>
            </main>
        </div>
    );
}

/**
 * قاعدة واحدة تحكم كل ما يلي: حراسة الواجهة تطابق حراسة routes/api.php حرفاً.
 * الواجهة ليست طبقة أمان — الـ backend هو الحارس الحقيقي — لكن اختلافهما
 * يُنتج أسوأ تجربة: صفحة تُفتح ثم تمتلئ رسائل 403.
 */
export default function App() {
    return (
        <AuthProvider>
            <BrowserRouter>
                <Routes>
                    <Route path="/login" element={<Login />} />

                    <Route path="/" element={
                        <ProtectedRoute>
                            <Layout><Dashboard /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* التلاميذ — كل مسارات /students في الـ backend تحت manage_students.
                        صلاحية enroll_student موجودة في قاعدة البيانات لكنها لا تحرس أي مسار،
                        فكان من يملكها وحدها يرى معالج التسجيل ثم يُرفض عند الحفظ. */}
                    <Route path="/students" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><StudentsDashboard /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/enroll" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><EnrollWizard /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/enroll/new" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><NewStudentWizard /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/enroll/old" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><OldStudentReenroll /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/search" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><StudentSearchPage /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/search/:studentId" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><StudentDetailsPage /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/transfer" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><StudentTransferPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* العائلات — التحصيل الجماعي */}
                    <Route path="/families" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><FamiliesListPage /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/families/:id" element={
                        <ProtectedRoute permission="manage_students">
                            <Layout><FamilyDetailPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* بنية المدرسة وقوائم الأقسام — manage_users في الـ backend */}
                    <Route path="/classrooms" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><ClassroomsPage /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/rosters" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><RosterPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* Users */}
                    <Route path="/users" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><UsersList /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/users/add" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><UserForm /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/users/edit/:id" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><UserForm /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* أنواع المعاليم — apiResource('/fee-types') داخل manage_payments لا manage_users */}
                    <Route path="/fee-types" element={
                        <ProtectedRoute permission="manage_payments">
                            <Layout><FeeTypesPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* ═══ المداخيل ═══
                        موديول مختلط: الفوترة تستدعي /payments/collect (manage_payments)
                        وبقية التبويبات تستدعي /reports/* (view_reports). */}
                    <Route path="/income" element={
                        <ProtectedRoute anyOf={['manage_payments', 'view_reports']}>
                            <Layout><IncomeLayout /></Layout>
                        </ProtectedRoute>
                    }>
                        <Route index element={<Navigate to="billing" replace />} />
                        <Route path="billing" element={<CollectionPage />} />
                        <Route path="by-date" element={<IncomeByDatePage />} />
                        <Route path="revenue" element={<StudentRevenuePage />} />
                        {/* صفحة تلميذ واحد — حفر من جدول مداخيل التلاميذ */}
                        <Route path="revenue/:studentId" element={<StudentDetailPage />} />
                        <Route path="by-classroom" element={<RevenueByClassroomPage />} />
                        {/* كشف القسم التفصيلي: قائمة منسدلة لكل الأقسام + جدول تلاميذ قابل للطباعة.
                            يُعلَن قبل by-classroom/:sectionId وإلا ابتلعه المسار ذو المعامِل. */}
                        <Route path="by-classroom/roster" element={<ClassroomRosterPage />} />
                        {/* صفحة قسم واحد — حفر من جدول الأقسام */}
                        <Route path="by-classroom/:sectionId" element={<ClassroomDetailPage />} />
                        <Route path="by-year" element={<RevenueByYearPage />} />
                        <Route path="unpaid-monthly" element={<ProtectedRoute permission="view_reports"><UnpaidMonthlyReportPage /></ProtectedRoute>} />
                    </Route>

                    <Route path="/reports/club-arrears" element={
                        <ProtectedRoute anyOf={['manage_payments', 'view_reports']}>
                            <Layout><ClubArrearsDashboardPage /></Layout>
                        </ProtectedRoute>
                    } />

                    <Route path="/reports/club-fees" element={
                        <ProtectedRoute anyOf={['manage_payments', 'view_reports']}>
                            <Layout><ClubFeesReportPage /></Layout>
                        </ProtectedRoute>
                    } />

                    <Route path="/clubs" element={
                        <ProtectedRoute anyOf={['manage_students', 'manage_payments']}>
                            <Layout><ClubsPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* ═══ المصاريف ═══
                        التسجيل manage_expenses والتقارير view_reports. */}
                    <Route path="/expenses" element={
                        <ProtectedRoute anyOf={['manage_expenses', 'view_reports']}>
                            <Layout><ExpensesLayout /></Layout>
                        </ProtectedRoute>
                    }>
                        <Route index element={<Navigate to="create" replace />} />
                        <Route path="create" element={<ExpenseCreatePage />} />
                        <Route path="daily" element={<ExpenseDailyReportPage />} />
                        <Route path="monthly" element={<ExpenseMonthlyReportPage />} />
                        <Route path="yearly" element={<ExpenseYearlyReportPage />} />
                    </Route>

                    {/* ═══ الخزينة ═══
                        السجل० والسحوبات manage_treasury، أمّا الكشف اليومي وهو التبويب الافتراضي
                        فمساره /reports/treasury-daybook تحت view_reports. */}
                    <Route path="/treasury" element={
                        <ProtectedRoute anyOf={['manage_treasury', 'view_reports']}>
                            <Layout><TreasuryLayout /></Layout>
                        </ProtectedRoute>
                    }>
                        {/* الكشف اليومي هو الواجهة الافتراضية: هو ما تفتحه الإدارة كل صباح */}
                        <Route index element={<Navigate to="daybook" replace />} />
                        <Route path="daybook" element={<TreasuryDaybookPage />} />
                        <Route path="history" element={<TreasuryHistoryPage />} />
                        <Route path="withdrawals" element={<TreasuryWithdrawalsPage />} />
                        {/* الكشف انتقل إلى موديول مستقل؛ يُحفظ المسار القديم حتى لا تنكسر روابط المستخدمين */}
                        <Route path="net-income" element={<Navigate to="/net-income/daily" replace />} />
                    </Route>

                    {/* ═══ الدخل الصافي — قراءة تقارير بحتة ═══ */}
                    <Route path="/net-income" element={
                        <ProtectedRoute permission="view_reports">
                            <Layout><NetIncomeLayout /></Layout>
                        </ProtectedRoute>
                    }>
                        <Route index element={<Navigate to="daily" replace />} />
                        <Route path="daily" element={<NetIncomeDailyPage />} />
                        <Route path="monthly" element={<NetRevenueMonthlyPage />} />
                        <Route path="yearly" element={<NetRevenueYearlyPage />} />
                    </Route>

                    {/* استخلاص مستقل (توافق خلفي): يُحوّل إلى تبويب الفوترة داخل المداخيل */}
                    <Route path="/collection" element={<Navigate to="/income/billing" replace />} />

                    {/* التخفيضات السنوية — الخادم يحرسها بصلاحية waive_fees حصراً */}
                    <Route path='/discounts' element={
                        <ProtectedRoute permission='waive_fees'>
                            <Layout><DiscountsPage /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path='/discounts/monthly' element={
                        <ProtectedRoute permission='waive_fees'>
                            <Layout><MonthlyDiscountsPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* إدارة الإعفاءات والتخفيضات للتلاميذ — موديول رئيسي مستقل */}
                    <Route path="/exemptions" element={
                        <ProtectedRoute anyOf={['waive_fees', 'manage_students', 'manage_payments']}>
                            <Layout><ExemptionsPage /></Layout>
                        </ProtectedRoute>
                    } />

{/* Historique — سجل الوصولات الملغاة، يقرأ من /payments */}
                    <Route path="/historique" element={
                        <ProtectedRoute permission="manage_payments">
                            <Layout><HistoriquePage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* الإطارات — بيانات الإطار manage_employees، وتبويب التسبقات والرواتب manage_salaries */}
                    <Route path="/employees" element={
                        <ProtectedRoute anyOf={['manage_employees', 'manage_salaries']}>
                            <Layout><EmployeesPage /></Layout>
                        </ProtectedRoute>
                    } />

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </BrowserRouter>
        </AuthProvider>
    );
}
