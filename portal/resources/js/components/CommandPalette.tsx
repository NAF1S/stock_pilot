import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import {
    Activity,
    BarChart3,
    Boxes,
    CornerDownLeft,
    LayoutDashboard,
    LineChart,
    Package,
    Plug,
    Search,
    Settings,
    ShoppingCart,
    Sparkles,
    Users,
} from 'lucide-react';
import { useAuth } from '../lib/auth';
import { cn } from '../lib/cn';

interface Entry {
    label: string;
    to: string;
    group: string;
    icon: ReactNode;
    adminOnly?: boolean;
}

const ENTRIES: Entry[] = [
    { label: 'Dashboard', to: '/app', group: 'Pages', icon: <LayoutDashboard className="size-4" /> },
    { label: 'Items', to: '/app/items', group: 'Pages', icon: <Package className="size-4" /> },
    { label: 'Products & Recipes', to: '/app/products', group: 'Pages', icon: <Boxes className="size-4" /> },
    { label: 'Orders', to: '/app/orders', group: 'Pages', icon: <ShoppingCart className="size-4" /> },
    { label: 'Movements', to: '/app/movements', group: 'Pages', icon: <LineChart className="size-4" /> },
    { label: 'AI Forecasting', to: '/app/forecasts', group: 'Pages', icon: <Sparkles className="size-4" /> },
    { label: 'Activity', to: '/app/activity', group: 'Pages', icon: <Activity className="size-4" /> },
    { label: 'Reports', to: '/app/reports', group: 'Pages', icon: <BarChart3 className="size-4" /> },
    { label: 'Stores', to: '/app/stores', group: 'System', icon: <Plug className="size-4" /> },
    { label: 'Settings', to: '/app/settings', group: 'System', icon: <Settings className="size-4" /> },
    { label: 'Team', to: '/app/users', group: 'System', icon: <Users className="size-4" />, adminOnly: true },
];

export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { user } = useAuth();
    const [query, setQuery] = useState('');
    const [cursor, setCursor] = useState(0);
    const navigate = useNavigate();
    const listRef = useRef<HTMLDivElement>(null);

    const isAdmin = user?.role === 'admin';

    const entries = useMemo(() => ENTRIES.filter((e) => !e.adminOnly || isAdmin), [isAdmin]);

    const results = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return entries;
        return entries.filter((e) => e.label.toLowerCase().includes(q) || e.group.toLowerCase().includes(q));
    }, [entries, query]);

    useEffect(() => {
        if (!open) {
            setQuery('');
            setCursor(0);
        }
    }, [open]);

    useEffect(() => setCursor(0), [query]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setCursor((c) => (results.length === 0 ? 0 : Math.min(c + 1, results.length - 1)));
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                setCursor((c) => Math.max(c - 1, 0));
            }
            if (e.key === 'Home') {
                e.preventDefault();
                setCursor(0);
            }
            if (e.key === 'End') {
                e.preventDefault();
                setCursor(Math.max(results.length - 1, 0));
            }
            if (e.key === 'Enter' && results[cursor]) {
                e.preventDefault();
                navigate(results[cursor].to);
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, results, cursor, navigate, onClose]);

    useEffect(() => {
        if (!open) return;
        const active = listRef.current?.querySelector<HTMLElement>('[data-active="true"]');
        active?.scrollIntoView({ block: 'nearest' });
    }, [open, cursor]);

    if (!open) return null;

    const activeOption = results[cursor] ? `command-palette-option-${cursor}` : undefined;

    return (
        <div
            className="fixed inset-0 z-60 flex items-start justify-center bg-ink/50 p-4 pt-24"
            onClick={onClose}
            role="presentation"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
                className="w-full max-w-lg overflow-hidden border-2 border-line-strong bg-surface"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center gap-2 border-b-2 border-line-strong px-3">
                    <Search className="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Jump to a page…"
                        aria-label="Search pages"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="command-palette-results"
                        aria-autocomplete="list"
                        aria-activedescendant={activeOption}
                        className="h-12 w-full bg-transparent text-sm text-ink placeholder:text-ink-subtle focus:outline-none"
                    />
                    <kbd className="sp-kbd hidden shrink-0 sm:inline-flex">ESC</kbd>
                </div>

                <div
                    ref={listRef}
                    id="command-palette-results"
                    role="listbox"
                    aria-label="Pages"
                    className="max-h-96 overflow-y-auto p-1"
                >
                    {results.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-ink-subtle">No matching pages.</p>
                    ) : (
                        results.map((r, i) => (
                            <button
                                key={r.to}
                                id={`command-palette-option-${i}`}
                                type="button"
                                role="option"
                                aria-selected={i === cursor}
                                data-active={i === cursor}
                                tabIndex={-1}
                                onMouseEnter={() => setCursor(i)}
                                onClick={() => {
                                    navigate(r.to);
                                    onClose();
                                }}
                                className={cn(
                                    'flex w-full items-center gap-3 px-2.5 py-2 text-left text-sm',
                                    i === cursor ? 'bg-surface-sunken text-ink' : 'text-ink-muted',
                                )}
                            >
                                <span className={i === cursor ? 'text-brand-600' : 'text-ink-subtle'}>{r.icon}</span>
                                <span className="flex-1 font-medium">{r.label}</span>
                                <span className="text-xs text-ink-subtle">{r.group}</span>
                                {i === cursor && (
                                    <CornerDownLeft className="size-3.5 text-ink-subtle" aria-hidden="true" />
                                )}
                            </button>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}
