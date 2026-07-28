import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Sidebar } from './components/Sidebar';
import { UsersList } from './pages/Users/UsersList';
import { UserForm } from './pages/Users/UserForm';
import { Login } from './pages/Login';
import { AuthProvider } from './contexts/AuthContext';
import { ProtectedRoute } from './components/ProtectedRoute';
import { StudentsDashboard } from './pages/Students/StudentsDashboard';
import { EnrollWizard } from './pages/Students/EnrollWizard';
import { NewStudentWizard } from './pages/Students/NewStudentWizard';
import { OldStudentReenroll } from './pages/Students/OldStudentReenroll';
import Dashboard from './pages/Dashboard/Dashboard';
import { FeeTypesPage } from './pages/FeeTypes/FeeTypesPage';
import { EmployeesPage } from './pages/Employees/EmployeesPage';
import { CollectionPage } from './pages/Payments/CollectionPage';
import { HistoriquePage } from './pages/Payments/HistoriquePage';
import { ClassroomsPage } from './pages/Classrooms/ClassroomsPage';
import { RosterPage } from './pages/Classrooms/RosterPage';
// ─── الأقسام المالية ───
import { IncomeLayout } from './pages/Income/IncomeLayout';
import { IncomeByDatePage } from './pages/Income/IncomeByDatePage';
import { StudentRevenuePage } from './pages/Income/StudentRevenuePage';
import { StudentDetailPage } from './pages/Income/StudentDetailPage';
import { RevenueByClassroomPage } from './pages/Income/RevenueByClassroomPage';
import { ClassroomDetailPage } from './pages/Income/ClassroomDetailPage';
import { RevenueByYearPage } from './pages/Income/RevenueByYearPage';
import { ExpensesLayout } from './pages/Expenses/ExpensesLayout';
import { ExpenseCreatePage } from './pages/Expenses/ExpenseCreatePage';
import { ExpenseDailyReportPage } from './pages/Expenses/ExpenseDailyReportPage';
import { ExpenseMonthlyReportPage } from './pages/Expenses/ExpenseMonthlyReportPage';
import { ExpenseYearlyReportPage } from './pages/Expenses/ExpenseYearlyReportPage';
import { TreasuryLayout } from './pages/Treasury/TreasuryLayout';
import { TreasuryDaybookPage } from './pages/Treasury/TreasuryDaybookPage';
import { TreasuryHistoryPage } from './pages/Treasury/TreasuryHistoryPage';
import { TreasuryWithdrawalsPage } from './pages/Treasury/TreasuryWithdrawalsPage';
// ─── الدخل الصافي — موديول مستقل ───
import { NetIncomeLayout } from './pages/NetIncome/NetIncomeLayout';
import { NetIncomeDailyPage } from './pages/NetIncome/NetIncomeDailyPage';
import { NetRevenueMonthlyPage } from './pages/NetIncome/NetRevenueMonthlyPage';
import { NetRevenueYearlyPage } from './pages/NetIncome/NetRevenueYearlyPage';

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
            </BrowserRouter>
        </AuthProvider>
    );
}
