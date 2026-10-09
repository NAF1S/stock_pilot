import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { apiGet, apiPost } from './api';
import { getCsrfCookie } from '../bootstrap';

export interface OrgInfo {
    id: number;
    name: string;
    currency: string;
    timezone: string;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'manager' | 'staff';
    organization?: OrgInfo;
}

export interface RegisterData {
    name: string;
    email: string;
    password: string;
    organization_name: string;
    currency?: string;
    timezone?: string;
}

interface AuthContextValue {
    user: AuthUser | null;
    loading: boolean;
    login: (email: string, password: string) => Promise<void>;
    register: (data: RegisterData) => Promise<void>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        try {
            await getCsrfCookie();
            const { user: me } = await apiGet<{ user: AuthUser }>('/auth/me');
            setUser(me);
        } catch {
            setUser(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const login = useCallback(async (email: string, password: string) => {
        await getCsrfCookie();
        const { user: me } = await apiPost<{ user: AuthUser }>('/auth/login', { email, password });
        setUser(me);
    }, []);

    const register = useCallback(async (data: RegisterData) => {
        await getCsrfCookie();
        const { user: me } = await apiPost<{ user: AuthUser }>('/auth/register', data);
        setUser(me);
    }, []);

    const logout = useCallback(async () => {
        await apiPost('/auth/logout');
        setUser(null);
    }, []);

    return (
        <AuthContext.Provider value={{ user, loading, login, register, logout, refresh }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth(): AuthContextValue {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used within AuthProvider');
    return ctx;
}

export function can(role: AuthUser['role'], ...roles: AuthUser['role'][]): boolean {
    return roles.includes(role);
}
