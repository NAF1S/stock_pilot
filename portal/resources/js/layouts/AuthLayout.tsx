import React from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Check } from 'lucide-react';
import { Badge } from '../components/ui';
import { Logo } from '../components/Logo';
import { cn } from '../lib/cn';

/**
 * The four things a kit seller gets on day one. Stated as facts, not a badge row.
 * No dash, no middle dot: each one is its own line (19, 63).
 */
const PROOF = [
    'Multi-level kit recipes',
    'Append-only stock ledger',
    'WooCommerce auto-sync',
    'Stockout forecasting',
];

/**
 * The auth shell: an ink plate on the left carrying the product's one argument,
 * the form on paper at the right. Wayfind depth is borders, so the plate is a
 * solid field inside a 2px rule, never a gradient.
 */
export function AuthLayout({
    title,
    subtitle,
    children,
    footer,
    badge,
    backTo,
    backLabel = 'Back',
    wide = false,
}: {
    title: string;
    subtitle?: string;
    children: React.ReactNode;
    footer?: React.ReactNode;
    badge?: string;
    backTo?: string;
    backLabel?: string;
    wide?: boolean;
}) {
    return (
        <div className="flex min-h-screen bg-surface-sunken">
            {/* -------------------------------------------------- The plate */}
            <aside className="relative hidden w-[44%] max-w-2xl shrink-0 flex-col border-r-2 border-line-strong bg-plate lg:flex">
                <div className="flex flex-1 flex-col justify-between p-10 xl:p-12">
                    <span className="w-fit text-plate-ink">
                        <span className="inline-flex items-center gap-2.5">
                            <span className="grid size-9 place-items-center border-2 border-plate-ink/40">
                                <svg viewBox="0 0 24 24" fill="none" className="size-5" aria-hidden="true">
                                    <path
                                        d="M12 3.25 20.5 7v10L12 20.75 3.5 17V7L12 3.25Z"
                                        stroke="currentColor"
                                        strokeWidth="1.6"
                                        strokeLinejoin="round"
                                    />
                                    <path
                                        d="M12 20.5V12M20.25 7.15 12 11.9 3.75 7.15"
                                        stroke="currentColor"
                                        strokeWidth="1.6"
                                        strokeLinejoin="round"
                                        opacity=".85"
                                    />
                                </svg>
                            </span>
                            <span className="text-lg font-bold tracking-tight">
                                Stock<span className="text-plate-ink/70">Pilot</span>
                            </span>
                        </span>
                    </span>

                    <div>
                        <p className="text-xs text-plate-ink/70">Inventory for kit sellers</p>
                        <h2 className="mt-3 max-w-md text-3xl font-bold tracking-tight text-plate-ink xl:text-4xl">
                            Every component, recipe and order, in one ledger.
                        </h2>

                        <ul className="mt-8 border-t border-plate-ink/20">
                            {PROOF.map((item) => (
                                <li
                                    key={item}
                                    className="flex items-center gap-2.5 border-b border-plate-ink/20 py-2.5 text-sm text-plate-ink/90"
                                >
                                    <Check className="size-3.5 shrink-0 text-figure" aria-hidden="true" />
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p className="border-l-2 border-figure pl-4 text-sm leading-relaxed text-plate-ink/85">
                        A mistake in the ledger is corrected by a new row that points at the one it fixes.
                        Nothing is ever rewritten.
                    </p>
                </div>
            </aside>

            {/* ------------------------------------------------------ Form side */}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 items-center justify-between px-4 sm:px-6 lg:justify-end lg:px-8">
                    <span className="lg:hidden">
                        <Logo size="sm" />
                    </span>
                    {backTo && (
                        <Link
                            to={backTo}
                            className="hidden items-center gap-1.5 px-2 py-1.5 text-xs font-medium text-ink-subtle hover:text-ink sm:flex"
                        >
                            <ArrowLeft className="size-3.5" aria-hidden="true" />
                            {backLabel}
                        </Link>
                    )}
                </header>

                <main className="flex flex-1 items-center justify-center px-4 pb-8 sm:px-6">
                    <div className={cn('w-full', wide ? 'max-w-lg' : 'max-w-sm')}>
                        <div className="mb-5">
                            {badge && (
                                <Badge tone="gray" className="mb-2">
                                    {badge}
                                </Badge>
                            )}
                            <h1 className="text-2xl font-bold tracking-tight text-ink">{title}</h1>
                            {subtitle && <p className="mt-1.5 text-sm text-ink-muted">{subtitle}</p>}
                        </div>

                        {children}

                        {footer && <div className="mt-5 text-sm text-ink-muted">{footer}</div>}
                    </div>
                </main>

                <footer className="px-4 pb-5 text-center text-xs text-ink-subtle sm:px-6">
                    StockPilot · Inventory and warehouse management
                </footer>
            </div>
        </div>
    );
}
