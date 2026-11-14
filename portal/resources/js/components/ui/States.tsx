import React from 'react';
import { AlertTriangle, RotateCw } from 'lucide-react';
import { Button } from './index';
import { cn } from '../../lib/cn';

/**
 * The three states a data region has besides "full".
 * A skeleton in the shape of what is coming, not a spinner in the middle of nowhere.
 */

export function TableSkeleton({ rows = 5, cols = 4 }: { rows?: number; cols?: number }) {
    return (
        <div aria-hidden="true" className="w-full">
            <div className="flex gap-3 border-b-2 border-line-strong py-2">
                {Array.from({ length: cols }).map((_, i) => (
                    <div key={i} className="h-3 flex-1 animate-pulse bg-line/70 motion-reduce:animate-none" />
                ))}
            </div>
            {Array.from({ length: rows }).map((_, r) => (
                <div key={r} className="flex gap-3 border-b border-line py-3">
                    {Array.from({ length: cols }).map((_, c) => (
                        <div
                            key={c}
                            className={cn(
                                'h-3 animate-pulse bg-line/60 motion-reduce:animate-none',
                                // the naming column is wider, so the skeleton reads like the table
                                c === 0 ? 'flex-[2]' : 'flex-1',
                            )}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

export function StatSkeleton({ count = 4 }: { count?: number }) {
    return (
        <div aria-hidden="true" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: count }).map((_, i) => (
                <div key={i} className="border border-line p-3">
                    <div className="h-3 w-20 animate-pulse bg-line/70 motion-reduce:animate-none" />
                    <div className="mt-2.5 h-6 w-28 animate-pulse bg-line/60 motion-reduce:animate-none" />
                    <div className="mt-2 h-2.5 w-16 animate-pulse bg-line/50 motion-reduce:animate-none" />
                </div>
            ))}
        </div>
    );
}

export function ErrorState({
    what = 'this page',
    detail,
    onRetry,
}: {
    what?: string;
    detail?: string;
    onRetry?: () => void;
}) {
    return (
        <div
            role="alert"
            className="flex flex-col items-start gap-3 border border-line border-l-2 border-l-danger bg-surface px-3 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="flex items-start gap-2.5">
                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-danger" aria-hidden="true" />
                <div>
                    <div className="text-sm font-semibold text-ink">Could not load {what}</div>
                    <p className="mt-0.5 text-xs text-ink-muted">
                        {detail ?? 'The request did not come back. Check the connection and try again.'}
                    </p>
                </div>
            </div>
            {onRetry && (
                <Button variant="secondary" size="sm" onClick={onRetry}>
                    <RotateCw className="size-3.5" aria-hidden="true" />
                    Try again
                </Button>
            )}
        </div>
    );
}

/** A page-level frame that keeps the head visible while its data resolves. */
export function PageBody({ children }: { children: React.ReactNode }) {
    return <div className="space-y-4">{children}</div>;
}
