import { NavLink, useNavigate, useLocation } from 'react-router-dom';
import { LayoutDashboard, Users, LogOut, GraduationCap } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';

export function Sidebar() {
    const { logout, user, hasPermission } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    const linkClass = (active: boolean) =>
        `flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
            active
                ? 'bg-white text-[#2E3B2A] font-medium shadow'
                : 'text-white/70 hover:bg-white/10 hover:text-white'
        }`;

    return (
        <div
            className="w-64 text-white flex flex-col min-h-screen shadow-xl"
            style={{ background: 'linear-gradient(178deg, #2E3B2A 0%, #26311F 100%)' }}
        >
            {/* Logo */}
            <div className="p-6 border-b border-white/10">
                <h1 className="text-xl font-bold text-center tracking-wide">
                    العناية <span className="text-[#81C784]">ERP</span>
                </h1>
            </div>

            {/* User info */}
            {user && (
                <div className="px-5 py-4 border-b border-white/10">
                    <p className="text-white/90 text-sm font-medium truncate">
                        {user.first_name} {user.last_name}
                    </p>
                    <p className="text-white/50 text-xs mt-0.5 truncate">
                        {user.role?.display_name ?? 'غير محدد'}
                    </p>
                </div>
            )}

            {/* Nav */}
            <nav className="flex-1 p-4 space-y-2">
                <NavLink
                    to="/"
                    end
                    className={({ isActive }) => linkClass(isActive)}
                >
                    <LayoutDashboard size={20} />
                    <span>لوحة القيادة</span>
                </NavLink>

                <NavLink
                    to="/students"
                    className={({ isActive }) =>
                        linkClass(isActive || location.pathname.startsWith('/students'))
                    }
                >
                    <GraduationCap size={20} />
                    <span>التلاميذ</span>
                </NavLink>

                {/* يظهر فقط إذا كان للمستخدم صلاحية manage_users */}
                {hasPermission('manage_users') && (
                    <NavLink
                        to="/users"
                        className={({ isActive }) =>
                            linkClass(isActive || location.pathname.startsWith('/users'))
                        }
                    >
                        <Users size={20} />
                        <span>إدارة المستخدمين</span>
                    </NavLink>
                )}
            </nav>

            {/* Logout */}
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
