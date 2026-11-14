import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
    Activity,
    BarChart3,
    Boxes,
    ChevronsUpDown,
    LayoutDashboard,
    LineChart,
    LogOut,
    Menu,
    Package,
    PanelLeftClose,
    PanelLeftOpen,
    Plug,
    Search,
    Settings,
    ShoppingCart,
    Sparkles,
    User,
    Users,
    X,
} from 'lucide-react';
import { Logo } from '../components/Logo';
import { ThemeToggle } from '../components/ThemeToggle';
import { CommandPalette } from '../components/CommandPalette';
import { Avatar, Badge, Tooltip } from '../components/ui';
import { useAuth } from '../lib/auth';
import { cn } from '../lib/cn';

const NAV = [
    { to: '/app', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/app/items', label: 'Items', icon: Package },
    { to: '/app/products', label: 'Products & Recipes', icon: Boxes },
    { to: '/app/orders', label: 'Orders', icon: ShoppingCart },
    { to: '/app/movements', label: 'Movements', icon: LineChart },
    { to: '/app/forecasts', label: 'AI Forecasting', icon: Sparkles },
    { to: '/app/activity', label: 'Activity', icon: Activity },
    { to: '/app/reports', label: 'Reports', icon: BarChart3 },
] as const;

const SYSTEM_NAV = [
    { to: '/app/stores', label: 'Stores', icon: Plug },
    { to: '/app/settings', label: 'Settings', icon: Settings },
    { to: '/app/users', label: 'Team', icon: Users, adminOnly: true },
] as const;

const TITLES: Record<string, string> = Object.fromEntries(
    [...NAV, ...SYSTEM_NAV].map((item) => [item.to, item.label]),
);

const RAIL_SLOT =
    'flex w-full justify-center ' +
    '[&_[role=tooltip]]:top-1/2 [&_[role=tooltip]]:left-full [&_[role=tooltip]]:bottom-auto ' +
    '[&_[role=tooltip]]:mb-0 [&_[role=tooltip]]:ml-2 [&_[role=tooltip]]:-translate-y-1/2 [&_[role=tooltip]]:translate-x-0';

function titleFor(pathname: string): string {
    if (TITLES[pathname]) return TITLES[pathname];
    const key = Object.keys(TITLES)
        .filter((to) => to !== '/app' && pathname.startsWith(`${to}/`))
        .sort((a, b) => b.length - a.length)[0];
    return key ? TITLES[key] : 'Workspace';
}

export default function AppLayout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const { pathname } = useLocation();

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    const [userMenu, setUserMenu] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const userMenuRef = useRef<HTMLDivElement>(null);
    const drawerRef = useRef<HTMLElement>(null);
    const drawerTriggerRef = useRef<HTMLButtonElement>(null);

    const isAdmin = user?.role === 'admin';
    const systemNav = SYSTEM_NAV.filter((item) => !('adminOnly' in item) || !item.adminOnly || isAdmin);
    const title = titleFor(pathname);
    const org = user?.organization?.name ?? 'Workspace';

    useEffect(() => {
        setDrawerOpen(false);
        setUserMenu(false);
    }, [pathname]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setUserMenu(false);
                setDrawerOpen(false);
                setPaletteOpen((v) => !v);
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (!userMenu) return;
        const onDown = (e: MouseEvent) => {
            if (userMenuRef.current && !userMenuRef.current.contains(e.target as Node)) setUserMenu(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setUserMenu(false);
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [userMenu]);

    const closeDrawer = () => {
        drawerTriggerRef.current?.focus();
        setDrawerOpen(false);
    };

    useEffect(() => {
        if (!drawerOpen) return;
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                drawerTriggerRef.current?.focus();
                setDrawerOpen(false);
                return;
            }
            if (e.key !== 'Tab' || !drawerRef.current) return;
            const focusable = drawerRef.current.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
            );
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKey);
        const raf = requestAnimationFrame(() => {
            drawerRef.current?.querySelector<HTMLElement>('a[href], button:not([disabled])')?.focus();
        });

        return () => {
            document.body.style.overflow = prev;
            document.removeEventListener('keydown', onKey);
            cancelAnimationFrame(raf);
        };
    }, [drawerOpen]);

    const handleLogout = async () => {
        setUserMenu(false);
        setDrawerOpen(false);
        await logout();
        navigate('/login');
    };

    const renderNav = (compact: boolean, onNavigate?: () => void) => {
        const items = [...NAV, ...systemNav];

        return items.map((item) => {
            const Icon = item.icon;
            const end = 'end' in item ? item.end : false;

            if (compact) {
                return (
                    <div key={item.to} className={RAIL_SLOT}>
                        <Tooltip label={item.label}>
                            <NavLink
                                to={item.to}
                                end={end}
                                aria-label={item.label}
                                className="sp-rail-link sp-rail-link--compact w-11"
                            >
                                <Icon className="size-4.5" aria-hidden="true" />
                            </NavLink>
                        </Tooltip>
                    </div>
                );
            }

            return (
                <NavLink key={item.to} to={item.to} end={end} onClick={onNavigate} className="sp-rail-link">
                    <Icon className="size-4.5" aria-hidden="true" />
                    <span className="truncate">{item.label}</span>
                </NavLink>
            );
        });
    };

    return (
        <div className="flex min-h-screen bg-surface-sunken">
            {drawerOpen && (
                <div className="fixed inset-0 z-50 md:hidden">
                    <div
                        className="absolute inset-0 bg-ink/50"
                        onClick={closeDrawer}
                        aria-hidden="true"
                    />
                    <aside
                        ref={drawerRef}
                        role="dialog"
                        aria-modal="true"
                        aria-label="Navigation"
                        className="absolute inset-y-0 left-0 flex w-68 flex-col border-r-2 border-line-strong bg-surface"
                    >
                        <div className="flex h-14 shrink-0 items-center justify-between border-b-2 border-line-strong px-4">
                            <Logo size="sm" />
                            <button
                                type="button"
                                onClick={closeDrawer}
                                aria-label="Close navigation"
                                className="grid size-9 place-items-center text-ink-subtle hover:bg-surface-sunken hover:text-ink"
                            >
                                <X className="size-4" aria-hidden="true" />
                            </button>
                        </div>

                        <div className="flex shrink-0 items-center gap-2 border-b border-line px-3 py-2.5 text-xs font-medium text-ink-muted">
                            <span className="size-1.5 shrink-0 bg-positive" aria-hidden="true" />
                            <span className="truncate">{org}</span>
                        </div>

                        <nav className="flex-1 overflow-y-auto py-2" aria-label="Mobile">
                            {renderNav(false, () => setDrawerOpen(false))}
                        </nav>

                        <div className="shrink-0 border-t-2 border-line-strong p-3">
                            <div className="flex items-center gap-2.5">
                                <Avatar name={user?.name ?? 'User'} />
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-semibold text-ink">{user?.name}</div>
                                    <div className="text-xs text-ink-subtle capitalize">{user?.role}</div>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={handleLogout}
                                className="mt-2.5 flex min-h-9 w-full items-center gap-2 px-2 text-xs font-semibold text-danger hover:bg-danger-surface"
                            >
                                <LogOut className="size-3.5" aria-hidden="true" /> Sign out
                            </button>
                        </div>
                    </aside>
                </div>
            )}

            <aside
                className={cn(
                    'sticky top-0 hidden h-screen shrink-0 flex-col border-r-2 border-line-strong bg-surface-sunken md:flex',
                    collapsed ? 'w-17' : 'w-60',
                )}
            >
                <div
                    className={cn(
                        'flex h-14 shrink-0 items-center border-b-2 border-line-strong bg-surface',
                        collapsed ? 'justify-center px-2' : 'justify-between px-3',
                    )}
                >
                    {collapsed ? <Logo showText={false} size="sm" /> : <Logo size="sm" />}
                    <button
                        type="button"
                        onClick={() => setCollapsed(true)}
                        aria-label="Collapse sidebar"
                        className={cn(
                            'grid size-8 place-items-center text-ink-subtle hover:bg-surface-sunken hover:text-ink',
                            collapsed && 'hidden',
                        )}
                    >
                        <PanelLeftClose className="size-4" aria-hidden="true" />
                    </button>
                </div>

                {collapsed && (
                    <div className="flex shrink-0 justify-center border-b border-line py-1.5">
                        <button
                            type="button"
                            onClick={() => setCollapsed(false)}
                            aria-label="Expand sidebar"
                            className="grid size-8 place-items-center text-ink-subtle hover:bg-surface hover:text-ink"
                        >
                            <PanelLeftOpen className="size-4" aria-hidden="true" />
                        </button>
                    </div>
                )}

                {!collapsed && (
                    <div className="shrink-0 border-b border-line px-3 py-2.5">
                        <span className="flex items-center gap-2 text-xs font-medium text-ink-muted">
                            <span className="size-1.5 shrink-0 bg-positive" aria-hidden="true" />
                            <span className="truncate">{org}</span>
                        </span>
                    </div>
                )}

                <nav
                    className={cn('min-h-0 flex-1 py-2', collapsed ? 'overflow-visible' : 'overflow-y-auto')}
                    aria-label="Main"
                >
                    {renderNav(collapsed)}
                </nav>

                <div
                    className={cn(
                        'shrink-0 border-t border-line py-2',
                        collapsed ? 'flex justify-center' : 'px-3',
                    )}
                >
                    {collapsed ? (
                        <div className={cn('w-full', RAIL_SLOT)}>
                            <Tooltip label="Sign out">
                                <button
                                    type="button"
                                    onClick={handleLogout}
                                    aria-label="Sign out"
                                    className="sp-rail-link sp-rail-link--compact w-11 text-ink-subtle hover:text-danger"
                                >
                                    <LogOut className="size-4.5" aria-hidden="true" />
                                </button>
                            </Tooltip>
                        </div>
                    ) : (
                        <>
                            <div className="flex items-center gap-2.5">
                                <Avatar name={user?.name ?? 'User'} className="size-7" />
                                <div className="min-w-0">
                                    <div className="truncate text-xs font-semibold text-ink">{user?.name}</div>
                                    <div className="truncate text-xs text-ink-subtle capitalize">{user?.role}</div>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={handleLogout}
                                className="mt-2 flex min-h-9 w-full items-center gap-2 px-2 text-xs font-semibold text-ink-muted hover:bg-surface hover:text-danger"
                            >
                                <LogOut className="size-3.5" aria-hidden="true" /> Sign out
                            </button>
                        </>
                    )}
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b-2 border-line-strong bg-surface px-3 sm:px-4">
                    <button
                        ref={drawerTriggerRef}
                        type="button"
                        onClick={() => setDrawerOpen(true)}
                        aria-label="Open navigation"
                        className="grid size-9 shrink-0 place-items-center text-ink-muted hover:bg-surface-sunken hover:text-ink md:hidden"
                    >
                        <Menu className="size-5" aria-hidden="true" />
                    </button>

                    <div className="shrink-0 md:hidden">
                        <Logo size="sm" showText={false} />
                    </div>

                    <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink sm:flex-none">
                        {title}
                    </span>
                    <span className="hidden h-5 w-px shrink-0 bg-line sm:block" aria-hidden="true" />

                    <div className="hidden min-w-0 flex-1 sm:flex">
                        <button
                            type="button"
                            onClick={() => setPaletteOpen(true)}
                            className="sp-command-trigger w-64"
                        >
                            <Search className="size-4 shrink-0" aria-hidden="true" />
                            <span className="truncate">Search or jump to…</span>
                            <kbd className="sp-kbd ml-auto">⌘K</kbd>
                        </button>
                    </div>

                    <div className="ml-auto flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            onClick={() => setPaletteOpen(true)}
                            aria-label="Search or jump to"
                            className="grid size-9 place-items-center text-ink-muted hover:bg-surface-sunken hover:text-ink sm:hidden"
                        >
                            <Search className="size-4" aria-hidden="true" />
                        </button>

                        <ThemeToggle />

                        <div className="relative" ref={userMenuRef}>
                            <button
                                type="button"
                                onClick={() => setUserMenu((v) => !v)}
                                aria-haspopup="menu"
                                aria-expanded={userMenu}
                                aria-label="Account menu"
                                className="flex items-center gap-2 p-1 hover:bg-surface-sunken"
                            >
                                <Avatar name={user?.name ?? 'User'} className="size-7" />
                                <ChevronsUpDown
                                    className="hidden size-3.5 text-ink-subtle sm:block"
                                    aria-hidden="true"
                                />
                            </button>

                            {userMenu && (
                                <div
                                    role="menu"
                                    aria-label="Account"
                                    className="absolute right-0 z-50 mt-2 w-60 border-2 border-line-strong bg-surface-raised"
                                >
                                    <div className="border-b border-line px-3 py-2.5">
                                        <div className="truncate text-sm font-semibold text-ink">{user?.name}</div>
                                        <div className="sp-code truncate">{user?.email}</div>
                                        <Badge tone="purple" className="mt-2 capitalize">
                                            {user?.role}
                                        </Badge>
                                    </div>
                                    <div className="p-1">
                                        <button
                                            type="button"
                                            role="menuitem"
                                            onClick={() => {
                                                setUserMenu(false);
                                                navigate('/app/settings');
                                            }}
                                            className="flex min-h-9 w-full items-center gap-2 px-2 text-sm text-ink-muted hover:bg-surface-sunken hover:text-ink"
                                        >
                                            <User className="size-4" aria-hidden="true" /> Workspace settings
                                        </button>
                                        <button
                                            type="button"
                                            role="menuitem"
                                            onClick={handleLogout}
                                            className="flex min-h-9 w-full items-center gap-2 px-2 text-sm font-medium text-danger hover:bg-danger-surface"
                                        >
                                            <LogOut className="size-4" aria-hidden="true" /> Sign out
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                <main className="min-w-0 flex-1 px-4 py-5 sm:px-5 sm:py-6">
                    <div className="mx-auto w-full max-w-screen-2xl">
                        <Outlet />
                    </div>
                </main>
            </div>

            <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
        </div>
    );
}
