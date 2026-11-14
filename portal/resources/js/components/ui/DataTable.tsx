import React from 'react';
import { ChevronRight } from 'lucide-react';
import { cn } from '../../lib/cn';

export type Column<T> = {
    /** Column head. Sentence case, never wrapped. Empty for an action column. */
    head: string;
    /** The cell's content. */
    cell: (row: T) => React.ReactNode;
    /** Right-align a figure column. */
    align?: 'start' | 'end';
    /** Hide below this surface width when the table is dense. */
    hide?: 'sm' | 'md' | 'lg';
    /** A mono figure: SKUs, balances, money. */
    code?: boolean;
    /** The column that names the row. It leads the card on a phone. */
    key?: boolean;
    /** An action column: kept out of the phone definition list, shown beneath it. */
    actions?: boolean;
};

const HIDE: Record<'sm' | 'md' | 'lg', string> = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
};

/**
 * One table, two layouts from the same column definitions.
 *
 * Desktop: a real table, hairline row rules, a 2px head rule, no outer card.
 * Phone: each row becomes a labelled block, so the name and the actions stay in
 * view without a sideways scroll (craft § 9, F25).
 *
 * The open affordance is its own button rather than a wrapper, so a row's own
 * buttons are never nested inside it.
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    onRowClick,
    activeKey,
    empty,
    className,
}: {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    onRowClick?: (row: T) => void;
    activeKey?: string | number | null;
    empty?: React.ReactNode;
    className?: string;
}) {
    const keyCol = columns.find((c) => c.key) ?? columns[0];
    const detailCols = columns.filter((c) => c !== keyCol && !c.actions);
    const actionCol = columns.find((c) => c.actions);

    if (rows.length === 0 && empty) {
        return <div className={className}>{empty}</div>;
    }

    return (
        <div className={cn('w-full', className)}>
            {/* -------------------------------------------------- wide */}
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-full border-collapse text-sm">
                    <thead>
                        <tr className="border-b-2 border-line-strong">
                            {columns.map((c, i) => (
                                <th
                                    key={i}
                                    scope="col"
                                    className={cn(
                                        'px-2 py-2 text-xs font-medium whitespace-nowrap text-ink-subtle',
                                        c.align === 'end' ? 'text-right' : 'text-left',
                                        c.hide && HIDE[c.hide],
                                    )}
                                >
                                    {c.head}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const k = rowKey(row);
                            return (
                                <tr
                                    key={k}
                                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                                    className={cn(
                                        'border-b border-line',
                                        onRowClick && 'cursor-pointer hover:bg-surface-sunken',
                                        activeKey === k && 'bg-surface-sunken',
                                    )}
                                >
                                    {columns.map((c, i) => (
                                        <td
                                            key={i}
                                            className={cn(
                                                'px-2 py-2 align-middle',
                                                c.align === 'end' ? 'text-right' : 'text-left',
                                                c.code ? 'sp-code' : 'text-ink-muted',
                                                c.hide && HIDE[c.hide],
                                            )}
                                        >
                                            {c.cell(row)}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* ------------------------------------------------- phone */}
            <ul className="divide-y divide-line border-t-2 border-line-strong md:hidden">
                {rows.map((row) => {
                    const k = rowKey(row);
                    const isActive = activeKey === k;
                    return (
                        <li key={k} className={cn('py-3', isActive && 'bg-surface-sunken')}>
                            {onRowClick ? (
                                <button
                                    type="button"
                                    onClick={() => onRowClick(row)}
                                    className="flex w-full items-center gap-2 px-1 text-left"
                                >
                                    <span className="min-w-0 flex-1 text-sm font-semibold text-ink">
                                        {keyCol.cell(row)}
                                    </span>
                                    <ChevronRight
                                        className="size-4 shrink-0 text-ink-subtle"
                                        aria-hidden="true"
                                    />
                                </button>
                            ) : (
                                <div className="px-1 text-sm font-semibold text-ink">
                                    {keyCol.cell(row)}
                                </div>
                            )}

                            {detailCols.length > 0 && (
                                <dl className="mt-2 space-y-1 px-1">
                                    {detailCols.map((c, i) => (
                                        <div
                                            key={i}
                                            className="flex min-w-0 items-baseline justify-between gap-3"
                                        >
                                            <dt className="shrink-0 text-xs text-ink-subtle">
                                                {c.head || '\u00a0'}
                                            </dt>
                                            <dd
                                                className={cn(
                                                    'min-w-0 text-right text-xs break-words text-ink',
                                                    c.code && 'sp-code',
                                                )}
                                            >
                                                {c.cell(row)}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            )}

                            {actionCol && (
                                <div className="mt-2 flex flex-wrap items-center gap-1 px-1">
                                    {actionCol.cell(row)}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
