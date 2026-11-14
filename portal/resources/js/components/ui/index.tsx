import React from 'react';
import { Boxes, ChevronLeft, ChevronRight, Loader2, X } from 'lucide-react';
import { cn } from '../../lib/cn';
import type { Tone } from '../../lib/status';

/* ------------------------------------------------------------------ Badge */

const TONES: Record<Tone, string> = {
    green: 'border-positive/40 text-positive',
    red: 'border-danger/40 text-danger',
    amber: 'border-line text-ink',
    blue: 'border-brand-600/40 text-brand-700 dark:text-brand-300',
    gray: 'border-line text-ink-muted',
    purple: 'border-accent-500/50 text-accent-700 dark:text-accent-300',
};

export function Badge({
    tone = 'gray',
    children,
    className,
    dot = false,
}: {
    tone?: Tone;
    children: React.ReactNode;
    className?: string;
    dot?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-none border px-1.5 py-0.5 text-xs font-medium whitespace-nowrap',
                TONES[tone],
                className,
            )}
        >
            {dot && <span className="size-1.5 shrink-0 bg-current" />}
            {children}
        </span>
    );
}

/* ----------------------------------------------------------------- Button */

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'ai';
type ButtonSize = 'sm' | 'md' | 'lg';

const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
    primary:
        'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 disabled:hover:bg-brand-600',
    secondary:
        'bg-surface text-ink border-2 border-field-line hover:bg-surface-sunken active:bg-line',
    ghost: 'text-ink-muted hover:bg-surface-sunken hover:text-ink active:bg-line',
    danger: 'bg-danger text-white hover:opacity-90 active:opacity-80',
    ai: 'bg-surface text-ink border-2 border-brand-600 hover:bg-brand-50 active:bg-brand-100 dark:hover:bg-brand-500/10',
};

const BUTTON_SIZES: Record<ButtonSize, string> = {
    sm: 'h-8 gap-1.5 px-2.5 text-xs',
    md: 'h-9 gap-2 px-3.5 text-sm',
    lg: 'h-10 gap-2 px-4 text-sm',
};

export function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    className,
    children,
    disabled,
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: ButtonVariant;
    size?: ButtonSize;
    loading?: boolean;
}) {
    return (
        <button
            {...props}
            disabled={disabled || loading}
            className={cn(
                'relative inline-flex shrink-0 items-center justify-center rounded-none font-semibold whitespace-nowrap transition-colors',
                // Wayfind controls are 32 to 40px tall, so the 44px hit target is
                // expanded past the box rather than by growing the box.
                "before:absolute before:-inset-y-1 before:inset-x-0 before:content-['']",
                'disabled:cursor-not-allowed disabled:opacity-55',
                BUTTON_SIZES[size],
                BUTTON_VARIANTS[variant],
                className,
            )}
        >
            {loading && <Loader2 className="size-4 animate-spin" />}
            {children}
        </button>
    );
}

/* ------------------------------------------------------------------ Field */

const CONTROL =
    'w-full rounded-none border border-field-line bg-surface px-2.5 py-1.5 text-sm text-ink transition ' +
    'placeholder:text-ink-subtle focus:outline-2 focus:outline-field-line focus:outline-offset-2 ' +
    'disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-subtle disabled:opacity-55';

function FieldLabel({ id, label, hint }: { id?: string; label?: string; hint?: string }) {
    if (!label) return null;
    return (
        <span className="mb-1.5 flex items-baseline justify-between gap-2">
            <label htmlFor={id} className="text-xs font-semibold text-ink-muted">
                {label}
            </label>
            {hint && <span className="text-[11px] font-normal text-ink-subtle">{hint}</span>}
        </span>
    );
}

export function Input({
    label,
    hint,
    error,
    className,
    id,
    ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { label?: string; hint?: string; error?: string }) {
    return (
        <label className="block">
            <FieldLabel id={id} label={label} hint={hint} />
            <input
                id={id}
                {...props}
                aria-invalid={error ? true : undefined}
                className={cn(CONTROL, error && 'border-danger focus:outline-danger', className)}
            />
            {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
        </label>
    );
}

export function Textarea({
    label,
    hint,
    error,
    className,
    id,
    ...props
}: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { label?: string; hint?: string; error?: string }) {
    return (
        <label className="block">
            <FieldLabel id={id} label={label} hint={hint} />
            <textarea
                id={id}
                {...props}
                aria-invalid={error ? true : undefined}
                className={cn(CONTROL, 'min-h-[88px] resize-y', error && 'border-danger', className)}
            />
            {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
        </label>
    );
}

export function Select({
    label,
    hint,
    className,
    children,
    options,
    id,
    ...props
}: React.SelectHTMLAttributes<HTMLSelectElement> & {
    label?: string;
    hint?: string;
    options?: { value: string | number; label: string }[];
}) {
    return (
        <label className="block">
            <FieldLabel id={id} label={label} hint={hint} />
            <select id={id} {...props} className={cn(CONTROL, 'cursor-pointer pr-8', className)}>
                {options
                    ? options.map((opt) => (
                          <option key={opt.value} value={opt.value}>
                              {opt.label}
                          </option>
                      ))
                    : children}
            </select>
        </label>
    );
}

export function Checkbox({
    label,
    className,
    id,
    ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { label: React.ReactNode }) {
    return (
        <label
            htmlFor={id}
            className={cn('flex cursor-pointer items-center gap-2 text-sm text-ink-muted select-none', className)}
        >
            <input
                id={id}
                type="checkbox"
                {...props}
                className="size-4 rounded border-line text-brand-600 accent-brand-600"
            />
            {label}
        </label>
    );
}

export function Switch({
    checked,
    onChange,
    label,
    description,
    disabled,
}: {
    checked: boolean;
    onChange: (next: boolean) => void;
    label: string;
    description?: string;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div>
                <div className="text-sm font-medium text-ink">{label}</div>
                {description && <div className="mt-0.5 text-xs text-ink-subtle">{description}</div>}
            </div>
            {/* The switch draws at 24px; the button's padding carries a 44px target. */}
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={label}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={cn(
                    'inline-flex shrink-0 cursor-pointer items-center justify-center px-1 py-2.5 -my-2.5 rounded-none',
                    disabled && 'cursor-not-allowed opacity-50',
                )}
            >
                <span
                    className={cn(
                        'relative inline-flex h-6 w-11 border-2 transition-colors',
                        checked ? 'border-plate bg-plate' : 'border-field-line bg-surface-sunken',
                    )}
                >
                    <span
                        className={cn(
                            'pointer-events-none absolute top-0.5 left-0.5 size-4 transition-transform motion-reduce:transition-none',
                            checked ? 'translate-x-5 bg-surface' : 'bg-ink-muted',
                        )}
                    />
                </span>
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------- Card */

export function Card({
    title,
    description,
    actions,
    children,
    className,
    bodyClassName,
}: {
    title?: React.ReactNode;
    description?: React.ReactNode;
    actions?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
    bodyClassName?: string;
}) {
    return (
        <section
            className={cn(
                'overflow-hidden rounded-none border border-line bg-surface',
                className,
            )}
        >
            {(title || actions) && (
                <header className="flex flex-wrap items-center justify-between gap-2 border-b-2 border-line-strong px-3 py-2.5 sm:px-4">
                    <div className="min-w-0">
                        {title && <h2 className="truncate text-sm font-semibold text-ink">{title}</h2>}
                        {description && <p className="mt-0.5 text-xs text-ink-subtle">{description}</p>}
                    </div>
                    {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
                </header>
            )}
            <div className={cn('p-3 sm:p-4', bodyClassName)}>{children}</div>
        </section>
    );
}

/* --------------------------------------------------------------- StatCard */

export function StatCard({
    label,
    value,
    sub,
    tone = 'blue',
    icon,
    trend,
}: {
    label: string;
    value: React.ReactNode;
    sub?: React.ReactNode;
    tone?: Tone;
    icon?: React.ReactNode;
    trend?: { value: string; positive: boolean };
}) {
    const accent: Record<Tone, string> = {
        green: 'text-positive',
        red: 'text-danger',
        amber: 'text-ink',
        blue: 'text-brand-700 dark:text-brand-300',
        gray: 'text-ink',
        purple: 'text-accent-700 dark:text-accent-300',
    };

    return (
        <div className="rounded-none border border-line bg-surface p-3 sm:p-4">
            <div className="flex items-start justify-between gap-2">
                <span className="text-xs font-medium text-ink-subtle">{label}</span>
                {icon && <span className="shrink-0 text-ink-subtle">{icon}</span>}
            </div>
            <div
                className={cn(
                    'sp-figure mt-2 text-[1.375rem] leading-none break-words sm:text-[1.625rem]',
                    accent[tone],
                )}
            >
                {value}
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                {sub && <span className="text-xs text-ink-subtle">{sub}</span>}
                {trend && (
                    <span
                        className={cn(
                            'sp-code',
                            trend.positive
                                ? 'text-positive'
                                : 'text-danger',
                        )}
                    >
                        {trend.positive ? '+' : '−'}
                        {trend.value}
                    </span>
                )}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- Spinner */

export function Spinner({ className }: { className?: string }) {
    return <Loader2 className={cn('size-4 animate-spin', className)} />;
}

export function FullPageSpinner() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-surface-sunken">
            <Spinner className="size-8 text-brand-600" />
        </div>
    );
}

export function Skeleton({ className }: { className?: string }) {
    return <div className={cn('animate-pulse rounded-none bg-line/70', className)} />;
}

/* ------------------------------------------------------------------ Modal */

export function Modal({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    wide = false,
}: {
    open: boolean;
    onClose: () => void;
    title: React.ReactNode;
    description?: React.ReactNode;
    children: React.ReactNode;
    footer?: React.ReactNode;
    wide?: boolean;
}) {
    React.useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = prev;
        };
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 p-0 sm:items-center sm:p-4"
            onClick={onClose}
            role="presentation"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label={typeof title === 'string' ? title : undefined}
                className={cn(
                    'flex max-h-[92vh] w-full flex-col rounded-none border-2 border-line-strong bg-surface sm:max-w-lg',
                    wide ? 'sm:max-w-3xl' : 'sm:max-w-lg',
                )}
                onClick={(e) => e.stopPropagation()}
            >
                <header className="flex shrink-0 items-start justify-between gap-3 border-b border-line px-4 py-3.5 sm:px-5">
                    <div className="min-w-0">
                        <h2 className="truncate text-sm font-semibold text-ink">{title}</h2>
                        {description && <p className="mt-0.5 text-xs text-ink-subtle">{description}</p>}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close dialog"
                        className="grid size-8 shrink-0 place-items-center rounded-none text-ink-subtle hover:bg-surface-sunken hover:text-ink"
                    >
                        <X className="size-4" aria-hidden="true" />
                    </button>
                </header>
                <div className="flex-1 overflow-y-auto p-3 sm:p-4">{children}</div>
                {footer && (
                    <footer className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-line px-3 py-2.5 sm:px-4">
                        {footer}
                    </footer>
                )}
            </div>
        </div>
    );
}

/* -------------------------------------------------------------- PageHeader */

export function PageHeader({
    title,
    subtitle,
    actions,
    breadcrumb,
}: {
    title: string;
    subtitle?: string;
    actions?: React.ReactNode;
    breadcrumb?: React.ReactNode;
}) {
    return (
        <div className="mb-4 sm:mb-5">
            {breadcrumb && <div className="mb-2">{breadcrumb}</div>}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0">
                    <h1 className="text-xl font-bold tracking-tight text-ink sm:text-2xl">{title}</h1>
                    {subtitle && <p className="mt-1 max-w-2xl text-sm text-ink-subtle">{subtitle}</p>}
                </div>
                {actions && (
                    <div className="flex flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ Table */

export function Table({
    head,
    children,
    empty,
    className,
}: {
    head: React.ReactNode[];
    children: React.ReactNode;
    empty?: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('overflow-hidden rounded-none border border-line bg-surface', className)}>
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="border-b-2 border-line-strong">
                            {head.map((h, i) => (
                                <th
                                    key={i}
                                    scope="col"
                                    className="px-3 py-2 text-left text-xs font-medium whitespace-nowrap text-ink-subtle"
                                >
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">{children}</tbody>
                </table>
            </div>
            {empty}
        </div>
    );
}

export function Td({ className, children, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
    return (
        <td {...props} className={cn('px-3 py-2 align-middle text-ink-muted', className)}>
            {children}
        </td>
    );
}

/* ------------------------------------------------------------- Pagination */

export function Pagination({
    meta,
    onPage,
}: {
    meta: { current_page: number; last_page: number; total: number } | undefined;
    onPage: (page: number) => void;
}) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-ink-muted">
            <span className="text-xs">
                {meta.total} total · page {meta.current_page} of {meta.last_page}
            </span>
            <div className="flex items-center gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    disabled={meta.current_page <= 1}
                    onClick={() => onPage(meta.current_page - 1)}
                >
                    <ChevronLeft className="size-3.5" aria-hidden="true" />
                    Previous
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => onPage(meta.current_page + 1)}
                >
                    Next
                    <ChevronRight className="size-3.5" aria-hidden="true" />
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------- EmptyState */

export function EmptyState({
    title,
    hint,
    icon,
    action,
}: {
    title: string;
    hint?: string;
    icon?: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center px-4 py-14 text-center">
            <div className="mb-3 flex size-11 items-center justify-center rounded-none border border-line bg-surface-sunken text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                {icon ?? <Boxes className="size-5" aria-hidden="true" />}
            </div>
            <div className="text-sm font-semibold text-ink">{title}</div>
            {hint && <div className="mt-1 max-w-sm text-xs text-ink-subtle">{hint}</div>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

/* ------------------------------------------------------------------ Flash */

export function Flash({ message, tone = 'green' }: { message: string | null; tone?: 'green' | 'red' | 'info' }) {
    if (!message) return null;
    const styles = {
        green: 'border-l-2 border-positive bg-surface text-ink',
        red: 'border-l-2 border-danger bg-surface text-ink',
        info: 'border-l-2 border-brand-600 bg-surface text-ink',
    }[tone];
    return (
        <div role="status" className={cn('mb-4 rounded-none border border-line px-3 py-2 text-sm', styles)}>
            {message}
        </div>
    );
}

/* ------------------------------------------------------------------- Tabs */

export interface TabItem<T extends string> {
    id: T;
    label: React.ReactNode;
    count?: number;
}

export function Tabs<T extends string>({
    items,
    value,
    onChange,
    variant = 'pill',
    className,
}: {
    items: TabItem<T>[];
    value: T;
    onChange: (id: T) => void;
    variant?: 'pill' | 'underline';
    className?: string;
}) {
    if (variant === 'underline') {
        return (
            <div className={cn('scrollbar-none -mb-px flex gap-5 overflow-x-auto border-b border-line', className)}>
                {items.map((item) => {
                    const active = item.id === value;
                    return (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => onChange(item.id)}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex shrink-0 items-center gap-1.5 border-b-2 pb-2.5 text-sm font-semibold whitespace-nowrap transition',
                                active
                                    ? 'border-brand-600 text-brand-700 dark:text-brand-400'
                                    : 'border-transparent text-ink-subtle hover:border-line hover:text-ink',
                            )}
                        >
                            {item.label}
                            {typeof item.count === 'number' && (
                                <span className="rounded-none bg-surface-sunken px-1.5 py-0.5 text-[11px] font-semibold text-ink-subtle">
                                    {item.count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        );
    }

    return (
        <div
            role="tablist"
            className={cn(
                'scrollbar-none inline-flex max-w-full gap-0 overflow-x-auto rounded-none border border-line bg-surface p-0.5',
                className,
            )}
        >
            {items.map((item) => {
                const active = item.id === value;
                return (
                    <button
                        key={item.id}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(item.id)}
                        className={cn(
                            'flex min-h-8 shrink-0 items-center gap-2 rounded-none px-3 text-xs font-semibold whitespace-nowrap transition-colors',
                            active
                                ? 'sp-cell-on'
                                : 'text-ink-muted hover:bg-surface-sunken hover:text-ink',
                        )}
                    >
                        {item.label}
                        {typeof item.count === 'number' && (
                            <span
                                className={cn(
                                    'sp-code rounded-none px-1 text-[11px]',
                                    active ? 'text-plate-ink opacity-75' : 'text-ink-subtle',
                                )}
                            >
                                {item.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/* ---------------------------------------------------------------- Tooltip */

export function Tooltip({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <span className="group/tip relative inline-flex">
            {children}
            <span
                role="tooltip"
                className="pointer-events-none absolute bottom-full left-1/2 z-40 mb-1.5 -translate-x-1/2 rounded-none border border-ink bg-ink px-2 py-1 text-[11px] font-medium whitespace-nowrap text-surface opacity-0 transition-opacity group-hover/tip:opacity-100 group-focus-within/tip:opacity-100"
            >
                {label}
            </span>
        </span>
    );
}

/* --------------------------------------------------------------- Progress */

export function Progress({ value, tone = 'blue' }: { value: number; tone?: Tone }) {
    const bar: Record<Tone, string> = {
        green: 'bg-positive',
        red: 'bg-danger',
        amber: 'bg-figure',
        blue: 'bg-brand-600',
        gray: 'bg-ink-subtle',
        purple: 'bg-accent-500',
    };
    const pct = Math.max(0, Math.min(100, value));
    return (
        <div
            className="h-1.5 w-full overflow-hidden rounded-none bg-line"
            role="progressbar"
            aria-valuenow={Math.round(pct)}
            aria-valuemin={0}
            aria-valuemax={100}
        >
            <div
                className={cn(
                    'h-full origin-left rounded-none transition-transform duration-200',
                    bar[tone],
                )}
                style={{ transform: `scaleX(${pct / 100})` }}
            />
        </div>
    );
}

/* ----------------------------------------------------------------- Avatar */

export function Avatar({ name, className }: { name: string; className?: string }) {
    const initials = name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase())
        .join('');
    return (
        <span
            className={cn(
                'inline-flex size-8 shrink-0 items-center justify-center rounded-none border-2 border-ink bg-surface-sunken text-[11px] font-semibold text-ink',
                className,
            )}
            aria-hidden="true"
        >
            {initials || '?'}
        </span>
    );
}
