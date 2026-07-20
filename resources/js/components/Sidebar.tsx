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
    <div
      className="w-64 text-white flex flex-col min-h-screen shadow-xl"
      style={{ background: 'linear-gradient(178deg, #2E3B2A 0%, #26311F 100%)' }}
    >
      <div className="p-6 border-b border-white/10">
        <h1 className="text-xl font-bold text-center tracking-wide">
          العناية <span className="text-[#81C784]">ERP</span>
        </h1>
      </div>

      <nav className="flex-1 p-4 space-y-2">
        <NavLink
          to="/"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
              isActive
                ? 'bg-white text-[#2E3B2A] font-medium shadow'
                : 'text-white/70 hover:bg-white/10 hover:text-white'
            }`
          }
        >
          <LayoutDashboard size={20} />
          <span>لوحة القيادة</span>
        </NavLink>

        <NavLink
          to="/students"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
              isActive || location.pathname.includes('/students')
                ? 'bg-white text-[#2E3B2A] font-medium shadow'
                : 'text-white/70 hover:bg-white/10 hover:text-white'
            }`
          }
        >
          <GraduationCap size={20} />
          <span>التلاميذ</span>
        </NavLink>

        <NavLink
          to="/users"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
              isActive || location.pathname.includes('/users')
                ? 'bg-white text-[#2E3B2A] font-medium shadow'
                : 'text-white/70 hover:bg-white/10 hover:text-white'
            }`
          }
        >
          <Users size={20} />
          <span>إدارة المستخدمين</span>
        </NavLink>

        <NavLink
          to="/settings"
          className={({ isActive }) =>
            `flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
              isActive
                ? 'bg-white text-[#2E3B2A] font-medium shadow'
                : 'text-white/70 hover:bg-white/10 hover:text-white'
            }`
          }
        >
          <Settings size={20} />
          <span>الإعدادات</span>
        </NavLink>
      </nav>

      <div className="p-4 border-t border-white/10">
        <button
          onClick={handleLogout}
          className="flex items-center gap-3 px-4 py-3 w-full rounded-xl text-white/70 hover:bg-white/10 hover:text-red-300 transition-colors"
        >
          <LogOut size={20} />
          <span className="font-medium">تسجيل الخروج</span>
        </button>
      </div>
    </div>
  );
}
