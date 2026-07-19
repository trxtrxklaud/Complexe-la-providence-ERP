import React from 'react';
import { BrowserRouter, Routes, Route, useNavigate } from 'react-router-dom';
import { Sidebar } from './components/Sidebar';
import { UsersList } from './pages/Users/UsersList';
import { UserForm } from './pages/Users/UserForm';
import { Login } from './pages/Login';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ProtectedRoute } from './components/ProtectedRoute';
import { StudentsDashboard } from './pages/Students/StudentsDashboard';

import { EnrollWizard } from './pages/Students/EnrollWizard';

function Dashboard() {
  const { user } = useAuth();
  return (
    <div className="p-8">
      <h1 className="text-2xl font-bold text-slate-800 mb-6">لوحة القيادة</h1>
      <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-100">
        <p className="text-slate-600">مرحباً بك في نظام إدارة مدرسة العناية، {user?.first_name}.</p>
      </div>
    </div>
  );
}

function Layout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen bg-slate-50 font-sans">
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
          
          <Route path="/" element={<ProtectedRoute><Layout><Dashboard /></Layout></ProtectedRoute>} />
          <Route path="/students" element={<ProtectedRoute><Layout><StudentsDashboard /></Layout></ProtectedRoute>} />
          <Route path="/students/enroll" element={<ProtectedRoute><Layout><EnrollWizard /></Layout></ProtectedRoute>} />
          <Route path="/users" element={<ProtectedRoute permission="users.view"><Layout><UsersList /></Layout></ProtectedRoute>} />
          <Route path="/users/add" element={<ProtectedRoute permission="users.create"><Layout><UserForm /></Layout></ProtectedRoute>} />
          <Route path="/users/edit/:id" element={<ProtectedRoute permission="users.edit"><Layout><UserForm /></Layout></ProtectedRoute>} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
