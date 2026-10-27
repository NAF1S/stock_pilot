import { useQuery } from '@tanstack/react-query';
import { apiGet, errMsg } from '../lib/api';
import { dateTime, number } from '../lib/format';
import { Badge } from './ui';

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
        return <div className="px-1 py-2 text-xs text-gray-500">Loading reservations…</div>;
    }

    if (isError) {
        return <div className="px-1 py-2 text-xs text-red-600">{errMsg(error)}</div>;
    }

    const rows = data?.reservations ?? [];
    const cached = kind === 'item' ? data?.item?.quantity_reserved : data?.product?.quantity_reserved;
    const total = Number(data?.total_reserved ?? 0);

    return (
        <div className="rounded-md border border-gray-200 bg-white">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-3 py-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Orders reserving this {kind}
                </span>
                <span className="text-xs text-gray-500">
                    {rows.length} line{rows.length === 1 ? '' : 's'} · {number(total)} reserved
                    {cached != null && Math.abs(Number(cached) - total) > 0.0001 && (
                        <span
                            className="ml-1 text-amber-600"
                            title="The cached reserved quantity differs from the sum of the active reservation rows. The rows below are the actual orders; this usually means historical cache drift."
                        >
                            (cached: {number(cached)} — differs from listed rows)
                        </span>
                    )}
                </span>
            </div>

            {rows.length === 0 ? (
                <div className="px-3 py-4 text-xs text-gray-500">No active reservations.</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-xs">
                        <thead className="bg-gray-50 text-left text-[11px] uppercase text-gray-500">
                            <tr>
                                <th className="px-3 py-1.5">Order</th>
                                <th className="px-3 py-1.5">Status</th>
                                <th className="px-3 py-1.5">Customer</th>
                                <th className="px-3 py-1.5">Store</th>
                                <th className="px-3 py-1.5 text-right">Reserved</th>
                                <th className="px-3 py-1.5 text-right">Ordered</th>
                                <th className="px-3 py-1.5">{kind === 'product' ? 'Usage' : 'Sellable product'}</th>
                                <th className="px-3 py-1.5">Reserved at</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((row) => (
                                <tr key={row.component_id}>
                                    <td className="px-3 py-1.5">
                                        <div className="font-medium text-gray-800">#{row.order_number ?? row.order_id}</div>
                                        {row.order_item_name && (
                                            <div className="text-[11px] text-gray-400">{row.order_item_name}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <Badge tone={statusTone(row.order_status)}>{row.order_status ?? '—'}</Badge>
                                        <div className="mt-0.5 text-[11px] text-gray-400">
                                            {row.reservation_state ?? '—'} · component: {row.component_status}
                                        </div>
                                    </td>
                                    <td className="px-3 py-1.5 text-gray-600">{row.customer_name ?? '—'}</td>
                                    <td className="px-3 py-1.5 text-gray-600">{row.store_name ?? '—'}</td>
                                    <td className="px-3 py-1.5 text-right font-medium text-gray-800">
                                        {number(row.reserved_quantity)}
                                    </td>
                                    <td className="px-3 py-1.5 text-right text-gray-600">{number(row.total_quantity)}</td>
                                    <td className="px-3 py-1.5 text-gray-600">
                                        {kind === 'product' ? (
                                            row.usage === 'component' ? (
                                                <span>
                                                    Component of <b>{row.sellable_product?.name ?? 'another product'}</b>
                                                </span>
                                            ) : (
                                                <Badge tone="gray">Direct</Badge>
                                            )
                                        ) : (
                                            <>
                                                <div>{row.sellable_product?.name ?? '—'}</div>
                                                {row.quantity_per_unit > 0 && (
                                                    <div className="text-[11px] text-gray-400">
                                                        {number(row.quantity_per_unit)} per unit
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-3 py-1.5 text-gray-500">
                                        {dateTime(row.reserved_at ?? row.order_created_at)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {data?.truncated && (
                <div className="px-3 py-2 text-[11px] text-amber-600">
                    Showing the first 200 reservation lines.
                </div>
            )}
        </div>
    );
}
