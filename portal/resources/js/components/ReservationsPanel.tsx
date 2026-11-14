import { useQuery } from '@tanstack/react-query';
import { apiGet, errMsg } from '../lib/api';
import { dateTime, number } from '../lib/format';
import { Badge, EmptyState, Tooltip } from './ui';
import { DataTable, type Column } from './ui/DataTable';
import { TableSkeleton } from './ui/States';

type Kind = 'item' | 'product';
type Tone = 'green' | 'red' | 'amber' | 'blue' | 'gray' | 'purple';

interface ReservationRow {
    component_id: number;
    order_id: number;
    order_number: string | null;
    order_status: string | null;
    reservation_state: string | null;
    customer_name: string | null;
    store_name: string | null;
    order_created_at: string | null;
    reserved_at: string | null;
    component_status: string;
    quantity_per_unit: number;
    order_quantity: number;
    total_quantity: number;
    reserved_quantity: number;
    order_item_name: string | null;
    sellable_product: { id: number; name: string; sku: string | null } | null;
    usage: 'direct' | 'component' | null;
}

interface ReservationsResponse {
    item?: { id: number; name: string; sku: string | null; unit: string; quantity_reserved: number };
    product?: { id: number; name: string; sku: string | null; quantity_reserved: number };
    reservations: ReservationRow[];
    total_reserved: number;
    truncated: boolean;
}

function statusTone(status: string | null): Tone {
    switch ((status ?? '').toLowerCase()) {
        case 'completed':
            return 'green';
        case 'processing':
        case 'confirmed':
            return 'blue';
        case 'pending':
        case 'on-hold':
            return 'amber';
        case 'cancelled':
        case 'failed':
        case 'trash':
            return 'red';
        case 'refunded':
        case 'returned':
        case 'returned-with-charge':
            return 'purple';
        default:
            return 'gray';
    }
}

export function ReservationsPanel({ kind, id }: { kind: Kind; id: number }) {
    const { data, isLoading, isError, error } = useQuery({
        queryKey: [`${kind}-reservations`, id],
        queryFn: () => apiGet<ReservationsResponse>(`/${kind}s/${id}/reservations`),
        staleTime: 15_000,
    });

    if (isLoading) {
        return (
            <div className="border border-line bg-surface p-3">
                <TableSkeleton rows={3} cols={4} />
            </div>
        );
    }

    if (isError) {
        return (
            <div
                role="alert"
                className="border border-line border-l-2 border-l-danger bg-surface px-3 py-2.5 text-xs text-ink"
            >
                {errMsg(error)}
            </div>
        );
    }

    const rows = data?.reservations ?? [];
    const cached = kind === 'item' ? data?.item?.quantity_reserved : data?.product?.quantity_reserved;
    const total = Number(data?.total_reserved ?? 0);
    const drift = cached != null && Math.abs(Number(cached) - total) > 0.0001;

    const columns: Column<ReservationRow>[] = [
        {
            head: 'Order',
            key: true,
            cell: (row) => (
                <span className="flex min-w-0 flex-col">
                    <span className="sp-code font-semibold">
                        #{row.order_number ?? row.order_id}
                    </span>
                    {row.order_item_name && (
                        <span className="truncate font-normal text-ink-subtle">{row.order_item_name}</span>
                    )}
                </span>
            ),
        },
        {
            head: 'Status',
            cell: (row) => (
                <span className="flex flex-col items-start gap-1">
                    <Badge tone={statusTone(row.order_status)}>{row.order_status ?? '—'}</Badge>
                    <span className="sp-code text-ink-subtle">
                        {row.reservation_state ?? '—'} · component: {row.component_status}
                    </span>
                </span>
            ),
        },
        { head: 'Customer', cell: (row) => row.customer_name ?? '—', hide: 'lg' },
        { head: 'Store', cell: (row) => row.store_name ?? '—', hide: 'lg' },
        {
            head: 'Reserved',
            cell: (row) => <span className="font-semibold">{number(row.reserved_quantity)}</span>,
            align: 'end',
            code: true,
        },
        { head: 'Ordered', cell: (row) => number(row.total_quantity), align: 'end', code: true, hide: 'md' },
        {
            head: kind === 'product' ? 'Usage' : 'Sellable product',
            cell: (row) =>
                kind === 'product' ? (
                    row.usage === 'component' ? (
                        <span>
                            Component of <span className="font-semibold text-ink">{row.sellable_product?.name ?? 'another product'}</span>
                        </span>
                    ) : (
                        <Badge tone="gray">Direct</Badge>
                    )
                ) : (
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate">{row.sellable_product?.name ?? '—'}</span>
                        {row.quantity_per_unit > 0 && (
                            <span className="sp-code text-ink-subtle">
                                {number(row.quantity_per_unit)} per unit
                            </span>
                        )}
                    </span>
                ),
        },
        {
            head: 'Reserved at',
            cell: (row) => (
                <span className="sp-code">{dateTime(row.reserved_at ?? row.order_created_at)}</span>
            ),
            hide: 'md',
        },
    ];

    return (
        <section className="border border-line bg-surface">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b-2 border-line-strong px-3 py-2">
                <h3 className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                    Orders reserving this {kind}
                </h3>
                <span className="flex flex-wrap items-center gap-1.5 text-xs text-ink-subtle">
                    <span className="sp-code">
                        {rows.length} line{rows.length === 1 ? '' : 's'}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span className="sp-code">{number(total)} reserved</span>
                    {drift && (
                        <Tooltip label="The cached reserved quantity differs from the sum of the active reservation rows. The rows below are the actual orders; this usually means historical cache drift.">
                            <Badge tone="amber">cached {number(cached)} · differs</Badge>
                        </Tooltip>
                    )}
                </span>
            </header>

            <div className="px-1 py-1">
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.component_id}
                    empty={
                        <EmptyState
                            title="No active reservations"
                            hint="Nothing is holding this stock right now."
                        />
                    }
                />
            </div>

            {data?.truncated && (
                <div className="border-t border-line px-3 py-2">
                    <Badge tone="amber">Showing the first 200 reservation lines</Badge>
                </div>
            )}
        </section>
    );
}
