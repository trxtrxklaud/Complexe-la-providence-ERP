import React, { createContext, useContext, useState, useEffect } from 'react';
import { fetchCurrentUser, logout as apiLogout } from '../api/auth';
import { User } from '../types';

interface AuthContextType {
    user: User | null;
    loading: boolean;
    login: (token: string, user: User) => void;
    logout: () => Promise<void>;
    hasPermission: (permissionName: string) => boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const initAuth = async () => {
            const token = localStorage.getItem('token');
            if (token) {
                try {
                    const userData = await fetchCurrentUser();
                    setUser(userData);
                } catch (err) {
                    localStorage.removeItem('token');
                }
            }
            setLoading(false);
        };
        initAuth();
    }, []);

    const login = (token: string, userData: User) => {
        localStorage.setItem('token', token);
        setUser(userData);
    };

    const logout = async () => {
        try {
            await apiLogout();
        } catch (err) {
            console.error(err);
        } finally {
            localStorage.removeItem('token');
            setUser(null);
        }
    };

    const hasPermission = (permissionName: string): boolean => {
        if (!user?.role) return false;
        if (user.role.name === 'admin') return true;
        return user.role.permissions?.some(p => p.name === permissionName) ?? false;
    };

    return (
        <AuthContext.Provider value={{ user, loading, login, logout, hasPermission }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (context === undefined) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
}
