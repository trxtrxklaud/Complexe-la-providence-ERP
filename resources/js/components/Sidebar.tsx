import { NavLink, useNavigate, useLocation } from 'react-router-dom';
import { LayoutDashboard, Users, Settings, LogOut, GraduationCap } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';

export function Sidebar() {
  const { logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="w-64 bg-slate-900 text-white flex flex-col min-h-screen shadow-xl">
      <div className="p-6 border-b border-slate-800">
        <h1 className="text-xl font-bold text-center tracking-wide">
          العناية <span className="text-blue-500">ERP</span>
        </h1>
      </div>

      <nav className="flex-1 p-4 space-y-2">
        <NavLink
          to="/"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
              isActive ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
            }`
          }
        >
          <LayoutDashboard size={20} />
          <span className="font-medium">لوحة القيادة</span>
        </NavLink>

        <NavLink
          to="/students"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
              isActive || location.pathname.includes('/students') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
            }`
          }
        >
          <GraduationCap size={20} />
          <span className="font-medium">التلاميذ</span>
        </NavLink>

        <NavLink
          to="/users"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
              isActive || location.pathname.includes('/users') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
            }`
          }
        >
          <Users size={20} />
          <span className="font-medium">إدارة المستخدمين</span>
        </NavLink>
        
        <NavLink
          to="/settings"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
              isActive ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
            }`
          }
        >
          <Settings size={20} />
          <span className="font-medium">الإعدادات</span>
        </NavLink>
      </nav>

      <div className="p-4 border-t border-slate-800">
        <button 
          onClick={handleLogout}
          className="flex items-center gap-3 px-4 py-3 w-full rounded-lg text-slate-300 hover:bg-slate-800 hover:text-red-400 transition-colors"
        >
          <LogOut size={20} />
          <span className="font-medium">تسجيل الخروج</span>
        </button>
      </div>
    </div>
  );
}
