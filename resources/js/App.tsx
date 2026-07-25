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

                    {/* Fallback */}

                    <Route path="/fee-types" element={
                        <ProtectedRoute permission="manage_users">
                            <Layout><FeeTypesPage /></Layout>
                        </ProtectedRoute>
                    } />


                    <Route path="/collection" element={
                        <ProtectedRoute>
                            <Layout><CollectionPage /></Layout>
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
