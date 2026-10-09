import React, { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { cn } from '../lib/cn';

const nav = [
    { to: '/', label: 'Dashboard', icon: '▦', end: true },
    { to: '/items', label: 'Items', icon: '📦' },
    { to: '/products', label: 'Products & Recipes', icon: '🧩' },
    { to: '/orders', label: 'Orders', icon: '🧾' },
    { to: '/movements', label: 'Movements', icon: '📈' },
    { to: '/activity', label: 'Activity', icon: '📜' },
    { to: '/reports', label: 'Reports', icon: '📊' },
    { to: '/stores', label: 'Stores', icon: '🔌' },
    { to: '/settings', label: 'Settings', icon: '⚙️' },
    { to: '/users', label: 'Team', icon: '👥', adminOnly: true },
];

export default function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [mobileOpen, setMobileOpen] = useState(false);

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    const navItems = nav.filter((item) => !item.adminOnly || user?.role === 'admin');

    return (
        <div className="flex min-h-screen flex-col md:flex-row">
            {/* Mobile Top Header */}
            <header className="sticky top-0 z-30 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-2.5 md:hidden">
                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => setMobileOpen(true)}
                        className="rounded-md p-1.5 text-gray-600 hover:bg-gray-100 focus:outline-none"
                        aria-label="Open navigation menu"
                    >
                        <span className="text-xl leading-none">☰</span>
                    </button>
                    <div>
                        <div className="text-base font-bold leading-tight text-indigo-700">StockPilot</div>
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            <span className="max-w-[130px] truncate font-medium">{user?.organization?.name || 'Workspace'}</span>
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold capitalize text-indigo-700">
                        {user?.role}
                    </span>
                </div>
            </header>

            {/* Mobile Drawer Backdrop & Panel */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 md:hidden">
                    <div
                        className="fixed inset-0 bg-black/40 transition-opacity"
                        onClick={() => setMobileOpen(false)}
                    />
                    <div className="fixed inset-y-0 left-0 flex w-72 flex-col bg-white shadow-xl">
                        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                            <div>
                                <div className="text-base font-bold text-indigo-700">StockPilot</div>
                                <div className="text-xs text-gray-400">{user?.organization?.name}</div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setMobileOpen(false)}
                                className="rounded p-1 text-gray-400 hover:text-gray-600"
                                aria-label="Close menu"
                            >
                                ✕
                            </button>
                        </div>
                        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
                            {navItems.map((item) => (
                                <NavLink
                                    key={item.to}
                                    to={item.to}
                                    end={item.end}
                                    onClick={() => setMobileOpen(false)}
                                    className={({ isActive }) =>
                                        cn(
                                            'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition',
                                            isActive ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50',
                                        )
                                    }
                                >
                                    <span className="text-lg leading-none">{item.icon}</span>
                                    {item.label}
                                </NavLink>
                            ))}
                        </nav>
                        <div className="border-t border-gray-100 p-4">
                            <div className="text-sm font-medium text-gray-700">{user?.name}</div>
                            <div className="text-xs capitalize text-gray-400">{user?.role}</div>
                            <button onClick={handleLogout} className="mt-2 text-xs font-medium text-red-500 hover:text-red-700">
                                Sign out
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Desktop Static Sidebar */}
            <aside className="hidden w-60 flex-col border-r border-gray-200 bg-white md:flex">
                <div className="border-b border-gray-100 px-5 py-4">
                    <div className="text-lg font-bold text-indigo-700">StockPilot</div>
                    <div className="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        <span className="max-w-[170px] truncate font-medium text-gray-700">{user?.organization?.name || 'Workspace'}</span>
                    </div>
                </div>
                <nav className="flex-1 space-y-0.5 p-3">
                    {navItems.map((item) => (
                        <NavLink
                            key={item.to}
                            to={item.to}
                            end={item.end}
                            className={({ isActive }) =>
                                cn(
                                    'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition',
                                    isActive ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-50',
                                )
                            }
                        >
                            <span className="text-base leading-none">{item.icon}</span>
                            {item.label}
                        </NavLink>
                    ))}
                </nav>
                <div className="border-t border-gray-100 p-4">
                    <div className="text-sm font-medium text-gray-700">{user?.name}</div>
                    <div className="text-xs capitalize text-gray-400">{user?.role}</div>
                    <button onClick={handleLogout} className="mt-2 text-xs font-medium text-red-500 hover:text-red-700">
                        Sign out
                    </button>
                </div>
            </aside>

            {/* Main Content Area */}
            <main className="flex-1 overflow-y-auto bg-gray-50 min-w-0">
                <div className="mx-auto max-w-6xl p-3 sm:p-5 md:p-6">
                    <Outlet />
                </div>
            </main>
        </div>
    );
}
