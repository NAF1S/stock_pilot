import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { History, Search } from 'lucide-react';
import { apiGet } from '../lib/api';
import { dateTime, number } from '../lib/format';
import { movementMeta, statusTone } from '../lib/status';
import { Badge, Card, EmptyState, Input, PageHeader, Pagination, Select, Spinner } from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

interface Movement {
    id: number;
    entity_type: 'item' | 'product';
    entity_id: number;
    entity_name: string;
    entity_sku: string | null;
    movement_type: string;
    qty_delta: number;
    reserved_delta: number;
    on_hand_after: number;
    reserved_after: number;
    ref_type: string | null;
    ref_id: number | null;
    ref_label: string | null;
    ref_order_status: string | null;
    reason: string | null;
    created_by: string | null;
    occurred_at: string | null;
    created_at: string;
}

const TYPES = Object.keys({
    reserve: 1, confirm: 1, release: 1, return: 1, adjustment: 1, initial: 1, purchase_in: 1, sync_fix: 1,
});

const REVERSALS = new Set(['release', 'return']);

function Delta({ value }: { value: number }) {
    if (value === 0) return <span className="sp-code text-ink-subtle">0</span>;
    const positive = value > 0;
    return (
        <span className={positive ? 'sp-code text-positive' : 'sp-code text-danger'}>
            {positive ? '+' : ''}{number(value)}
        </span>
    );
}

export default function Activity() {
    const [page, setPage] = useState(1);
    const [entity, setEntity] = useState('');
    const [movementType, setMovementType] = useState('');
    const [refType, setRefType] = useState('');
    const [q, setQ] = useState('');
    const [qInput, setQInput] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['movements', page, entity, movementType, refType, q],
        queryFn: () =>
            apiGet<{ data: Movement[]; current_page: number; last_page: number; total: number }>('/movements', {
                page, per_page: 50,
                entity: entity || undefined,
                movement_type: movementType || undefined,
                ref_type: refType || undefined,
                q: q || undefined,
            }),
    });

    const rows = data?.data ?? [];
    const corrections = rows.filter((m) => REVERSALS.has(m.movement_type)).length;

    const columns: Column<Movement>[] = [
        {
            head: 'When',
            hide: 'lg',
            cell: (m) => <span className="sp-code text-xs">{dateTime(m.occurred_at ?? m.created_at)}</span>,
        },
        {
            head: 'Movement',
            key: true,
            cell: (m) => (
                <span className="flex min-w-0 flex-col">
                    <span className="flex items-center gap-1.5">
                        <Badge tone={movementMeta(m.movement_type).tone}>{movementMeta(m.movement_type).label}</Badge>
                        {REVERSALS.has(m.movement_type) && <Badge tone="gray">correction</Badge>}
                    </span>
                    <span className="mt-0.5 truncate text-xs text-ink-muted">{m.entity_name}</span>
                </span>
            ),
        },
        {
            head: 'SKU',
            hide: 'md',
            cell: (m) => (
                <span className="flex items-center gap-2">
                    <span className="sp-code">{m.entity_sku ?? '—'}</span>
                    <Badge tone={m.entity_type === 'product' ? 'blue' : 'gray'}>{m.entity_type}</Badge>
                </span>
            ),
        },
        {
            head: 'Change',
            align: 'end',
            cell: (m) => (
                <span className="flex flex-col items-end">
                    <Delta value={m.qty_delta} />
                    {m.reserved_delta !== 0 && (
                        <span className="sp-code text-xs text-ink-subtle">
                            {m.reserved_delta > 0 ? '+' : ''}
                            {number(m.reserved_delta)} reserved
                        </span>
                    )}
                </span>
            ),
        },
        {
            head: 'Balance',
            align: 'end',
            code: true,
            cell: (m) => (
                <span className="flex flex-col items-end">
                    <span>{number(m.on_hand_after)}</span>
                    {m.reserved_after > 0 && (
                        <span className="text-xs text-ink-subtle">{number(m.reserved_after)} reserved</span>
                    )}
                </span>
            ),
        },
        {
            head: 'Source',
            hide: 'lg',
            cell: (m) => (
                <span className="flex flex-col">
                    <span className="text-xs text-ink-muted">{m.ref_type ?? '—'}</span>
                    {m.created_by && <span className="text-xs text-ink-subtle">{m.created_by}</span>}
                </span>
            ),
        },
        {
            head: 'Reference',
            hide: 'md',
            cell: (m) =>
                m.ref_type === 'order' && m.ref_label ? (
                    <span className="flex flex-wrap items-center gap-1.5">
                        <Link to={`/orders?order=${m.ref_id}`} className="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                            {m.ref_label}
                        </Link>
                        {m.ref_order_status && <Badge tone={statusTone(m.ref_order_status)}>{m.ref_order_status}</Badge>}
                    </span>
                ) : (
                    <span className="text-xs text-ink-muted">{m.reason ?? m.ref_label ?? '—'}</span>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Activity"
                subtitle="Every stock movement — reserve, confirm, release, return, adjustment — for items and products, and the order or reason that caused it."
            />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Rows in view</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{number(rows.length)}</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Corrections</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{number(corrections)}</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Rows are append-only</div>
                    <div className="mt-2 text-xs text-ink-muted">
                        Nothing is deleted. A mistake is fixed by a new row pointing at the original.
                    </div>
                </div>
            </div>

            <Card bodyClassName="p-0">
                <div className="flex flex-wrap items-end gap-2 border-b border-line px-3 py-2.5">
                    <div className="relative flex min-w-0 flex-1 items-center sm:max-w-xs">
                        <Search className="absolute left-2 size-4 text-ink-subtle" aria-hidden="true" />
                        <Input
                            aria-label="Search item or product"
                            value={qInput}
                            onChange={(e) => setQInput(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') { setQ(qInput); setPage(1); } }}
                            placeholder="Name or SKU…"
                            className="pl-8"
                        />
                    </div>

                    <Select
                        value={entity}
                        onChange={(e) => { setEntity(e.target.value); setPage(1); }}
                        aria-label="Filter by entity"
                        className="w-full sm:w-auto"
                    >
                        <option value="">All entities</option>
                        <option value="item">Items</option>
                        <option value="product">Products</option>
                    </Select>

                    <Select
                        value={movementType}
                        onChange={(e) => { setMovementType(e.target.value); setPage(1); }}
                        aria-label="Filter by movement type"
                        className="w-full sm:w-auto"
                    >
                        <option value="">Any movement</option>
                        {TYPES.map((t) => (
                            <option key={t} value={t}>{movementMeta(t).label}</option>
                        ))}
                    </Select>

                    <Select
                        value={refType}
                        onChange={(e) => { setRefType(e.target.value); setPage(1); }}
                        aria-label="Filter by source"
                        className="w-full sm:w-auto"
                    >
                        <option value="">Any source</option>
                        <option value="order">Orders</option>
                        <option value="adjustment">Adjustments</option>
                        <option value="seed">Initial stock</option>
                    </Select>

                    <span className="ml-auto text-xs text-ink-subtle">{number(data?.total ?? 0)} movements</span>
                </div>

                <div className="px-3 py-1">
                    <DataTable
                        columns={columns}
                        rows={rows}
                        rowKey={(m) => m.id}
                        empty={
                            isLoading ? (
                                <div className="py-12 text-center">
                                    <Spinner className="mx-auto text-ink-subtle" />
                                </div>
                            ) : (
                                <EmptyState
                                    title="No movements found"
                                    hint="Adjust stock or sync orders and their activity will appear here."
                                    icon={<History className="size-5" aria-hidden="true" />}
                                />
                            )
                        }
                    />
                </div>

                <div className="px-3">
                    <Pagination meta={data} onPage={setPage} />
                </div>
            </Card>
        </>
    );
}
