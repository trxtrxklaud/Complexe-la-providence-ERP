import React from 'react';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
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

function Layout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen" style={{ backgroundColor: '#E9EEE3' }}>
      <Sidebar />
      <main className="flex-1 overflow-x-hidden">
        {children}
      </main>
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
              <Layout>
                <Dashboard />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/students" element={
            <ProtectedRoute>
              <Layout>
                <StudentsDashboard />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/students/enroll" element={
            <ProtectedRoute>
              <Layout>
                <EnrollWizard />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/students/enroll/new" element={
            <ProtectedRoute>
              <Layout>
                <NewStudentWizard />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/students/enroll/old" element={
            <ProtectedRoute>
              <Layout>
                <OldStudentReenroll />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/users" element={
            <ProtectedRoute permission="users.view">
              <Layout>
                <UsersList />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/users/add" element={
            <ProtectedRoute permission="users.create">
              <Layout>
                <UserForm />
              </Layout>
            </ProtectedRoute>
          } />

          <Route path="/users/edit/:id" element={
            <ProtectedRoute permission="users.edit">
              <Layout>
                <UserForm />
              </Layout>
            </ProtectedRoute>
          } />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
