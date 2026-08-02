import React, { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Sidebar } from './components/Sidebar';
import { Login } from './pages/Login';
import { AuthProvider } from './contexts/AuthContext';
import { ProtectedRoute } from './components/ProtectedRoute';
import { StudentsDashboard } from './pages/Students/StudentsDashboard';
import { EnrollWizard } from './pages/Students/EnrollWizard';
import Dashboard from './pages/Dashboard/Dashboard';

const UsersList = lazy(() => import('./pages/Users/UsersList').then((module) => ({ default: module.UsersList })));
const UserForm = lazy(() => import('./pages/Users/UserForm').then((module) => ({ default: module.UserForm })));
const NewStudentWizard = lazy(() => import('./pages/Students/NewStudentWizard').then((module) => ({ default: module.NewStudentWizard })));
const OldStudentReenroll = lazy(() => import('./pages/Students/OldStudentReenroll').then((module) => ({ default: module.OldStudentReenroll })));
const FeeTypesPage = lazy(() => import('./pages/FeeTypes/FeeTypesPage').then((module) => ({ default: module.FeeTypesPage })));
const EmployeesPage = lazy(() => import('./pages/Employees/EmployeesPage').then((module) => ({ default: module.EmployeesPage })));
const CollectionPage = lazy(() => import('./pages/Payments/CollectionPage').then((module) => ({ default: module.CollectionPage })));
const HistoriquePage = lazy(() => import('./pages/Payments/HistoriquePage').then((module) => ({ default: module.HistoriquePage })));
const ClassroomsPage = lazy(() => import('./pages/Classrooms/ClassroomsPage').then((module) => ({ default: module.ClassroomsPage })));
const RosterPage = lazy(() => import('./pages/Classrooms/RosterPage').then((module) => ({ default: module.RosterPage })));
const IncomeLayout = lazy(() => import('./pages/Income/IncomeLayout').then((module) => ({ default: module.IncomeLayout })));
const IncomeByDatePage = lazy(() => import('./pages/Income/IncomeByDatePage').then((module) => ({ default: module.IncomeByDatePage })));
const StudentRevenuePage = lazy(() => import('./pages/Income/StudentRevenuePage').then((module) => ({ default: module.StudentRevenuePage })));
const StudentDetailPage = lazy(() => import('./pages/Income/StudentDetailPage').then((module) => ({ default: module.StudentDetailPage })));
const RevenueByClassroomPage = lazy(() => import('./pages/Income/RevenueByClassroomPage').then((module) => ({ default: module.RevenueByClassroomPage })));
const ClassroomDetailPage = lazy(() => import('./pages/Income/ClassroomDetailPage').then((module) => ({ default: module.ClassroomDetailPage })));
const RevenueByYearPage = lazy(() => import('./pages/Income/RevenueByYearPage').then((module) => ({ default: module.RevenueByYearPage })));
const ExpensesLayout = lazy(() => import('./pages/Expenses/ExpensesLayout').then((module) => ({ default: module.ExpensesLayout })));
const ExpenseCreatePage = lazy(() => import('./pages/Expenses/ExpenseCreatePage').then((module) => ({ default: module.ExpenseCreatePage })));
const ExpenseDailyReportPage = lazy(() => import('./pages/Expenses/ExpenseDailyReportPage').then((module) => ({ default: module.ExpenseDailyReportPage })));
const ExpenseMonthlyReportPage = lazy(() => import('./pages/Expenses/ExpenseMonthlyReportPage').then((module) => ({ default: module.ExpenseMonthlyReportPage })));
const ExpenseYearlyReportPage = lazy(() => import('./pages/Expenses/ExpenseYearlyReportPage').then((module) => ({ default: module.ExpenseYearlyReportPage })));
const TreasuryLayout = lazy(() => import('./pages/Treasury/TreasuryLayout').then((module) => ({ default: module.TreasuryLayout })));
const TreasuryDaybookPage = lazy(() => import('./pages/Treasury/TreasuryDaybookPage').then((module) => ({ default: module.TreasuryDaybookPage })));
const TreasuryHistoryPage = lazy(() => import('./pages/Treasury/TreasuryHistoryPage').then((module) => ({ default: module.TreasuryHistoryPage })));
const TreasuryWithdrawalsPage = lazy(() => import('./pages/Treasury/TreasuryWithdrawalsPage').then((module) => ({ default: module.TreasuryWithdrawalsPage })));
const NetIncomeLayout = lazy(() => import('./pages/NetIncome/NetIncomeLayout').then((module) => ({ default: module.NetIncomeLayout })));
const NetIncomeDailyPage = lazy(() => import('./pages/NetIncome/NetIncomeDailyPage').then((module) => ({ default: module.NetIncomeDailyPage })));
const NetRevenueMonthlyPage = lazy(() => import('./pages/NetIncome/NetRevenueMonthlyPage').then((module) => ({ default: module.NetRevenueMonthlyPage })));
const NetRevenueYearlyPage = lazy(() => import('./pages/NetIncome/NetRevenueYearlyPage').then((module) => ({ default: module.NetRevenueYearlyPage })));

function Layout({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex min-h-screen" style={{ backgroundColor: '#E9EEE3' }}>
            <Sidebar />
            <main className="flex-1 overflow-x-hidden">{children}</main>
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
                <Suspense fallback={<div className="p-6 text-center text-slate-500">جاري تحميل الصفحة...</div>}>
                    <Routes>
                    <Route path="/login" element={<Login />} />

                    <Route path="/" element={
                        <ProtectedRoute>
                            <Layout><Dashboard /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* التلاميذ — كل مسارات /students في الـ backend تحت manage_students.
                        صلاحية enroll_student موجودة في قاعدة البيانات لكنها لا تحرس أي مسار،
                        فكان من يملكها وحدها يرى معالج التسجيل ثم يُرفَض عند الحفظ. */}
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
                        {/* صفحة قسم واحد — حفر من جدول الأقسام */}
                        <Route path="by-classroom/:sectionId" element={<ClassroomDetailPage />} />
                        <Route path="by-year" element={<RevenueByYearPage />} />
                    </Route>

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
                        السجلّ والسحوبات manage_treasury، أمّا الكشف اليومي وهو التبويب الافتراضي
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
                </Suspense>
            </BrowserRouter>
        </AuthProvider>
    );
}
