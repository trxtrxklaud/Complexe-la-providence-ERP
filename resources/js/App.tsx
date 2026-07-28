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

                    {/* Students — view لا يحتاج permission، enroll يحتاج */}
                    <Route path="/students" element={
                        <ProtectedRoute>
                            <Layout><StudentsDashboard /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/enroll" element={
                        <ProtectedRoute permission="enroll_student">
                            <Layout><EnrollWizard /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/enroll/new" element={
                        <ProtectedRoute permission="enroll_student">
                            <Layout><NewStudentWizard /></Layout>
                        </ProtectedRoute>
                    } />
                    <Route path="/students/enroll/old" element={
                        <ProtectedRoute permission="enroll_student">
                            <Layout><OldStudentReenroll /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* بنية المدرسة وقوائم الأقسام */}
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

                    {/* Users — كلها تحت manage_users مطابقةً للـ backend */}
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

                    <Route path="/fee-types" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><FeeTypesPage /></Layout>
                        </ProtectedRoute>
                    } />

                    {/* ═══ المداخيل (موديول بـ routes متداخلة) ═══ */}
                    <Route path="/income" element={
                        <ProtectedRoute>
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

                    {/* ═══ المصاريف ═══ */}
                    <Route path="/expenses" element={
                        <ProtectedRoute>
                            <Layout><ExpensesLayout /></Layout>
                        </ProtectedRoute>
                    }>
                        <Route index element={<Navigate to="create" replace />} />
                        <Route path="create" element={<ExpenseCreatePage />} />
                        <Route path="daily" element={<ExpenseDailyReportPage />} />
                        <Route path="monthly" element={<ExpenseMonthlyReportPage />} />
                        <Route path="yearly" element={<ExpenseYearlyReportPage />} />
                    </Route>

                    {/* ═══ الخزينة ═══ */}
                    <Route path="/treasury" element={
                        <ProtectedRoute>
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

                    {/* ═══ الدخل الصافي ═══ */}
                    <Route path="/net-income" element={
                        <ProtectedRoute>
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

                    {/* Historique — سجل الوصولات الملغاة */}
                    <Route path="/historique" element={
                        <ProtectedRoute>
                            <Layout><HistoriquePage /></Layout>
                        </ProtectedRoute>
                    } />

                    <Route path="/employees" element={
                        <ProtectedRoute>
                            <Layout><EmployeesPage /></Layout>
                        </ProtectedRoute>
                    } />

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </BrowserRouter>
        </AuthProvider>
    );
}
