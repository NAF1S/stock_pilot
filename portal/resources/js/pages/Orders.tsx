import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ChevronRight, Search, ShoppingCart } from 'lucide-react';
import { apiDelete, apiGet, apiPost, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime, money, number } from '../lib/format';
import { componentTone, reservationTone, statusTone, syncMeta } from '../lib/status';
import {
    Badge,
    Button,
    Card,
    Checkbox,
    EmptyState,
    Flash,
    Input,
    Modal,
    PageHeader,
    Pagination,
    Select,
    Spinner,
} from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

interface OrderSummary {
    id: number;
    woocommerce_id: number;
    number: string;
    status: string;
    reservation_state: string;
    customer_name: string | null;
    total: number;
    refund_total: number;
    store_name: string | null;
    sync_status: string;
    order_created_at: string;
    reserved_at: string | null;
    confirmed_at: string | null;
}

interface OrderDetail extends OrderSummary {
    items: Array<{ id: number; product_name: string; sku: string | null; quantity: number; price: number; line_total: number }>;
    components: Array<{ id: number; item_id: number | null; component_product_id: number | null; item: { name: string; unit: string } | null; component_product: { name: string } | null; quantity_per_unit: number; order_quantity: number; total_quantity: number; reserved_quantity: number; confirmed_quantity: number; returned_quantity: number; status: string }>;
    status_events: Array<{ id: number; status_from: string | null; status_to: string; occurred_at: string }>;
}

type BulkAction = 'reprocess' | 'recompute_dry' | 'recompute_apply' | 'delete' | 'reserve' | 'confirm' | 'release' | 'confirm_no_deduct';

const STATUSES = ['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed'];
const RESERVATIONS = ['none', 'reserved', 'confirmed', 'released', 'returned', 'partial'];

export default function Orders() {
    const { user } = useAuth();
    const currency = user?.organization?.currency ?? 'BDT';
    const qc = useQueryClient();
    const [searchParams] = useSearchParams();

    // Deep-link support: /orders?order=<id> (used by the Activity Log) auto-opens that order.
    useEffect(() => {
        const raw = searchParams.get('order');
        const id = raw ? Number(raw) : NaN;
        if (Number.isFinite(id) && id > 0) loadDetail(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchParams]);

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [reservation, setReservation] = useState('');
    const [mismatch, setMismatch] = useState(false);
    const [unmapped, setUnmapped] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [detail, setDetail] = useState<OrderDetail | null>(null);
    const [flash, setFlash] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['orders', page, status, reservation, mismatch, unmapped],
        queryFn: () =>
            apiGet<{ data: OrderSummary[]; current_page: number; last_page: number; total: number }>('/orders', {
                page, per_page: 25, status: status || undefined, reservation_state: reservation || undefined, mismatch: mismatch || undefined, unmapped: unmapped || undefined,
            }),
    });

    const loadDetail = async (id: number) => {
        const r = await apiGet<{ order: OrderDetail; items: OrderDetail['items']; components: OrderDetail['components']; status_events: OrderDetail['status_events'] }>(`/orders/${id}`);
        setDetail({ ...r.order, items: r.items, components: r.components, status_events: r.status_events });
    };

    const reserveOrder = useMutation({
        mutationFn: () => apiPost<{ result: { action: string; reservation_state: string } }>(`/orders/${detail!.id}/reserve`),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash(`Order #${detail!.number} stock reserved.`);
            if (detail) await loadDetail(detail.id);
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const confirmOrder = useMutation({
        mutationFn: () => apiPost<{ result: { action: string; reservation_state: string } }>(`/orders/${detail!.id}/confirm`),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash(`Order #${detail!.number} stock confirmed and deducted.`);
            if (detail) await loadDetail(detail.id);
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const releaseOrder = useMutation({
        mutationFn: () => apiPost<{ result: { action: string; reservation_state: string } }>(`/orders/${detail!.id}/release`),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash(`Order #${detail!.number} stock released.`);
            if (detail) await loadDetail(detail.id);
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const confirmNoDeductOrder = useMutation({
        mutationFn: () => apiPost<{ result: { action: string; reservation_state: string } }>(`/orders/${detail!.id}/confirm-no-deduct`),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash(`Order #${detail!.number} confirmed without stock deduction. Status updated to OK.`);
            if (detail) await loadDetail(detail.id);
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const recompute = useMutation({
        mutationFn: (apply: boolean) =>
            apiPost<{ apply: boolean; drift_count: number; reconciliation_count: number }>(
                `/orders/${detail!.id}/recompute`,
                { apply },
            ),
        onSuccess: (r: { apply: boolean; drift_count: number; reconciliation_count: number }) => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFlash(r.apply ? `Reconciled ${r.reconciliation_count} item cache(s).` : `Dry run: ${r.drift_count} recipe drift(s), ${r.reconciliation_count} cache diff(s).`);
        },
    });

    const reprocess = useMutation({
        mutationFn: () => apiPost<{ result: { action: string; reservation_state: string } }>(`/orders/${detail!.id}/reprocess`),
        onSuccess: async (r) => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFlash(`Reprocessed: action=${r.result.action}, reservation state=${r.result.reservation_state}`);
            if (detail) await loadDetail(detail.id);
        },
    });

    const del = useMutation({
        mutationFn: () => apiDelete(`/orders/${detail!.id}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash('Order deleted.');
            setDetail(null);
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const bulk = useMutation({
        mutationFn: (action: BulkAction) =>
            apiPost<{ results: Array<{ id: number; number: string; status: string; error?: string }> }>('/orders/bulk', { action, ids: Array.from(selected) }),
        onSuccess: (r, action) => {
            const ok = r.results.filter((x) => x.status === 'ok').length;
            const errs = r.results.filter((x) => x.status === 'error');
            const label = action === 'recompute_dry' ? 'Dry-run'
                : action === 'recompute_apply' ? 'Reconciliation'
                : action === 'delete' ? 'Delete'
                : action === 'reserve' ? 'Reserve'
                : action === 'confirm' ? 'Confirm'
                : action === 'release' ? 'Release'
                : action === 'confirm_no_deduct' ? 'Force Confirm (No Deduction)'
                : 'Reprocess';
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash(`Bulk ${label}: ${ok} ok${errs.length ? `, ${errs.length} failed — ${errs[0].error ?? errs[0].number}` : ''}.`);
            setSelected(new Set());
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const pageRows = data?.data ?? [];
    const term = search.trim().toLowerCase();
    const rows = term
        ? pageRows.filter((o) => `${o.number} ${o.customer_name ?? ''} ${o.store_name ?? ''}`.toLowerCase().includes(term))
        : pageRows;

    const visibleIds = rows.map((o) => o.id);
    const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

    const toggleAll = () => {
        setSelected((prev) => {
            const next = new Set(prev);
            visibleIds.forEach((id) => (allSelected ? next.delete(id) : next.add(id)));
            return next;
        });
    };

    const toggleSel = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const confirmBulkDelete = () => {
        if (!confirm(`Delete ${selected.size} order(s)? Stock reservations will be released and confirmed stock restocked first.`)) return;
        bulk.mutate('delete');
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setReservation('');
        setMismatch(false);
        setUnmapped(false);
        setPage(1);
    };

    const columns: Column<OrderSummary>[] = [
        {
            head: 'Select',
            hide: 'lg',
            cell: (o) => (
                <label className="sp-hit-row items-center">
                    <span className="visually-hidden">Select order {o.number}</span>
                    <input
                        type="checkbox"
                        checked={selected.has(o.id)}
                        onChange={() => toggleSel(o.id)}
                        onClick={(e) => e.stopPropagation()}
                        className="size-4 accent-brand-600"
                    />
                </label>
            ),
        },
        {
            head: 'Order number',
            key: true,
            cell: (o) => (
                <span className="flex min-w-0 flex-col">
                    <span className="sp-code font-semibold text-ink">#{o.number}</span>
                    {o.store_name && <span className="mt-0.5 truncate text-xs text-ink-subtle">{o.store_name}</span>}
                </span>
            ),
        },
        { head: 'Customer', cell: (o) => o.customer_name ?? '—' },
        { head: 'Status', cell: (o) => <Badge tone={statusTone(o.status)}>{o.status}</Badge> },
        {
            head: 'Reservation',
            cell: (o) => <Badge tone={reservationTone(o.reservation_state)}>{o.reservation_state}</Badge>,
        },
        {
            head: 'Sync',
            hide: 'lg',
            cell: (o) => <Badge tone={syncMeta(o.sync_status).tone}>{syncMeta(o.sync_status).label}</Badge>,
        },
        {
            head: 'Total',
            align: 'end',
            code: true,
            cell: (o) => (
                <span className="flex flex-col items-end">
                    <span>{money(o.total, currency)}</span>
                    {o.refund_total > 0 && <span className="text-xs text-danger">-{money(o.refund_total, currency)}</span>}
                </span>
            ),
        },
        {
            head: 'Placed',
            align: 'end',
            hide: 'md',
            cell: (o) => <span className="sp-code text-xs">{dateTime(o.order_created_at)}</span>,
        },
        {
            head: '',
            align: 'end',
            actions: true,
            hide: 'sm',
            cell: () => <ChevronRight className="ml-auto size-4 text-ink-subtle" aria-hidden="true" />,
        },
    ];

    return (
        <>
            <PageHeader
                title="Orders"
                subtitle="Synced WooCommerce orders and how they consumed inventory."
                actions={
                    <Button variant="secondary" onClick={clearFilters}>
                        Clear filters
                    </Button>
                }
            />

            <Flash message={flash} />

            <Card bodyClassName="p-0">
                <div className="flex flex-wrap items-end gap-2 border-b border-line px-3 py-2.5">
                    <div className="relative flex min-w-0 flex-1 items-center sm:max-w-xs">
                        <Search className="absolute left-2 size-4 text-ink-subtle" aria-hidden="true" />
                        <Input
                            aria-label="Search orders"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Order number or customer…"
                            className="pl-8"
                        />
                    </div>

                    <Select
                        value={status}
                        onChange={(e) => { setStatus(e.target.value); setPage(1); }}
                        aria-label="Filter by status"
                        className="w-full sm:w-auto"
                    >
                        <option value="">Any status</option>
                        {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                    </Select>

                    <Select
                        value={reservation}
                        onChange={(e) => { setReservation(e.target.value); setPage(1); }}
                        aria-label="Filter by reservation"
                        className="w-full sm:w-auto"
                    >
                        <option value="">Any reservation</option>
                        {RESERVATIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                    </Select>

                    <Checkbox
                        id="orders-mismatch"
                        label="Mismatches only"
                        checked={mismatch}
                        onChange={(e) => { setMismatch(e.target.checked); setPage(1); }}
                    />

                    <Checkbox
                        id="orders-unmapped"
                        label="Unmapped only"
                        checked={unmapped}
                        onChange={(e) => { setUnmapped(e.target.checked); setPage(1); }}
                    />

                    <span className="ml-auto text-xs text-ink-subtle">{number(data?.total ?? 0)} orders</span>
                </div>

                {selected.size > 0 && (
                    <div className="flex flex-wrap items-center gap-2 border-b border-line bg-surface-sunken px-3 py-2">
                        <span className="mr-1 text-xs font-semibold text-ink">{selected.size} selected</span>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'reserve'} disabled={bulk.isPending} onClick={() => { if (confirm(`Reserve stock for ${selected.size} selected order(s)?`)) bulk.mutate('reserve'); }}>
                            Reserve
                        </Button>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'confirm'} disabled={bulk.isPending} onClick={() => { if (confirm(`Confirm stock deduction for ${selected.size} selected order(s)?`)) bulk.mutate('confirm'); }}>
                            Confirm
                        </Button>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'release'} disabled={bulk.isPending} onClick={() => { if (confirm(`Release stock for ${selected.size} selected order(s)?`)) bulk.mutate('release'); }}>
                            Release
                        </Button>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'confirm_no_deduct'} disabled={bulk.isPending} onClick={() => { if (confirm(`Force confirm ${selected.size} selected order(s) without stock deduction? Mismatches will be cleared and all items marked confirmed.`)) bulk.mutate('confirm_no_deduct'); }}>
                            Force confirm (no stock deduction)
                        </Button>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'reprocess'} disabled={bulk.isPending} onClick={() => bulk.mutate('reprocess')}>
                            Reprocess
                        </Button>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'recompute_dry'} disabled={bulk.isPending} onClick={() => bulk.mutate('recompute_dry')}>
                            Dry-run
                        </Button>
                        <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'recompute_apply'} disabled={bulk.isPending} onClick={() => { if (confirm(`Apply reconciliation to ${selected.size} order(s)?`)) bulk.mutate('recompute_apply'); }}>
                            Reconcile
                        </Button>
                        {user?.role === 'admin' && (
                            <Button size="sm" variant="danger" loading={bulk.isPending && bulk.variables === 'delete'} disabled={bulk.isPending} onClick={confirmBulkDelete}>
                                Delete
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" className="ml-auto" onClick={() => setSelected(new Set())}>
                            Clear selection
                        </Button>
                    </div>
                )}

                <div className="px-3 py-1">
                    <div className="flex items-center gap-3 border-b border-line px-2 py-1.5">
                        <label className="sp-hit-row gap-1.5 text-xs text-ink-subtle">
                            <input
                                type="checkbox"
                                checked={allSelected}
                                onChange={toggleAll}
                                aria-label="Select all on page"
                                className="size-4 accent-brand-600"
                            />
                            All
                        </label>
                    </div>

                    <DataTable
                        columns={columns}
                        rows={rows}
                        rowKey={(o) => o.id}
                        activeKey={detail ? detail.id : null}
                        onRowClick={(o) => loadDetail(o.id)}
                        empty={
                            isLoading ? (
                                <div className="py-12 text-center">
                                    <Spinner className="mx-auto text-ink-subtle" />
                                </div>
                            ) : (
                                <EmptyState
                                    title="No orders synced yet"
                                    hint="Connect your store and place an order — it will appear here."
                                    icon={<ShoppingCart className="size-5" aria-hidden="true" />}
                                />
                            )
                        }
                    />
                </div>

                <div className="px-3">
                    <Pagination meta={data} onPage={setPage} />
                </div>
            </Card>

            <Modal
                open={!!detail}
                onClose={() => setDetail(null)}
                wide
                title={detail ? `Order #${detail.number}` : ''}
                description={detail ? `${detail.customer_name ?? '—'} · ${dateTime(detail.order_created_at)}` : ''}
                footer={
                    detail && (
                        <>
                            <Button variant="secondary" size="sm" onClick={() => setDetail(null)}>
                                Close
                            </Button>
                            {['none', 'released', 'partial'].includes(detail.reservation_state) && (
                                <Button
                                    size="sm"
                                    loading={reserveOrder.isPending}
                                    onClick={() => { if (confirm(`Reserve stock for Order #${detail.number}?`)) reserveOrder.mutate(); }}
                                >
                                    Reserve stock
                                </Button>
                            )}
                            {detail.reservation_state !== 'confirmed' && (
                                <Button
                                    size="sm"
                                    loading={confirmOrder.isPending}
                                    onClick={() => { if (confirm(`Confirm stock deduction for Order #${detail.number}?`)) confirmOrder.mutate(); }}
                                >
                                    Confirm stock
                                </Button>
                            )}
                            {['reserved', 'partial', 'confirmed'].includes(detail.reservation_state) && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    loading={releaseOrder.isPending}
                                    onClick={() => {
                                        const msg = detail.reservation_state === 'confirmed'
                                            ? `Order #${detail.number} is confirmed. Releasing will restock goods back to on-hand inventory. Release stock?`
                                            : `Release reserved stock for Order #${detail.number}?`;
                                        if (confirm(msg)) releaseOrder.mutate();
                                    }}
                                >
                                    Release stock
                                </Button>
                            )}
                        </>
                    )
                }
            >
                {detail && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge tone={statusTone(detail.status)}>{detail.status}</Badge>
                            <Badge tone={reservationTone(detail.reservation_state)}>{detail.reservation_state}</Badge>
                            <Badge tone={syncMeta(detail.sync_status).tone}>{syncMeta(detail.sync_status).label}</Badge>
                            <span className="ml-auto sp-code text-ink">{money(detail.total, currency)}</span>
                        </div>

                        <div>
                            <h3 className="mb-2 text-sm font-semibold text-ink">Ordered items</h3>
                            {detail.items.length === 0 ? (
                                <p className="text-xs text-ink-subtle">No line items on this order.</p>
                            ) : (
                                <ul className="border-t-2 border-line-strong">
                                    {detail.items.map((i) => (
                                        <li key={i.id} className="flex items-baseline justify-between gap-3 border-b border-line py-2 text-sm">
                                            <span className="min-w-0">
                                                <span className="block truncate text-ink">{i.product_name}</span>
                                                {i.sku && <span className="sp-code">{i.sku}</span>}
                                            </span>
                                            <span className="flex shrink-0 items-baseline gap-3">
                                                <span className="sp-code text-xs text-ink-muted">×{number(i.quantity)}</span>
                                                <span className="sp-code text-ink">{money(i.line_total, currency)}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div>
                            <h3 className="mb-2 text-sm font-semibold text-ink">What it deducted</h3>
                            {detail.components.length === 0 ? (
                                <p className="text-xs text-ink-subtle">
                                    No components — no product line mapped to a recipe. Fix the order&apos;s recipe, then reprocess it.
                                </p>
                            ) : (
                                <ul className="border-t-2 border-line-strong">
                                    {detail.components.map((c) => (
                                        <li key={c.id} className="flex items-baseline justify-between gap-3 border-b border-line py-2 text-sm">
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span className="min-w-0 truncate text-ink">
                                                    {c.component_product ? c.component_product.name : (c.item?.name ?? `Item #${c.item_id}`)}
                                                </span>
                                                <Badge tone={componentTone(c.status)}>{c.status}</Badge>
                                            </span>
                                            <span className="flex shrink-0 flex-col items-end">
                                                <span className="sp-code text-ink">{number(c.total_quantity)}</span>
                                                <span className="sp-code text-xs">
                                                    {number(c.quantity_per_unit)}/unit · {number(c.order_quantity)} ordered
                                                </span>
                                                <span className="sp-code text-xs">
                                                    {number(c.reserved_quantity)} res · {number(c.confirmed_quantity)} conf · {number(c.returned_quantity)} ret
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div>
                            <h3 className="mb-2 text-sm font-semibold text-ink">Status history</h3>
                            {detail.status_events.length === 0 ? (
                                <p className="text-xs text-ink-subtle">No status changes recorded.</p>
                            ) : (
                                <ol className="space-y-2 border-l-2 border-line pl-3">
                                    {detail.status_events.map((e) => (
                                        <li key={e.id} className="text-xs">
                                            <span className="sp-code text-ink-muted">{dateTime(e.occurred_at)}</span>
                                            <span className="ml-2 text-ink">
                                                <span className="sp-code">{e.status_from ?? '—'}</span>
                                                {' → '}
                                                <span className="sp-code text-ink">{e.status_to}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-2 border-t border-line pt-3">
                            <span className="mr-1 text-xs font-semibold text-ink-muted">Maintenance</span>
                            <Button
                                variant="secondary"
                                size="sm"
                                loading={confirmNoDeductOrder.isPending}
                                onClick={() => {
                                    if (confirm(`Confirm all items for Order #${detail.number} without deducting current stock? This clears any mismatch status and marks items confirmed.`)) {
                                        confirmNoDeductOrder.mutate();
                                    }
                                }}
                            >
                                Force confirm (no stock deduction)
                            </Button>
                            <Button variant="secondary" size="sm" loading={reprocess.isPending} onClick={() => { if (confirm('Re-run this order through the inventory engine? Idempotent — safe for already-processed orders.')) reprocess.mutate(); }}>
                                Reprocess
                            </Button>
                            <Button variant="secondary" size="sm" loading={recompute.isPending} onClick={() => recompute.mutate(false)}>
                                Dry-run
                            </Button>
                            <Button variant="secondary" size="sm" loading={recompute.isPending} onClick={() => { if (confirm('Apply cache reconciliation? This fixes item caches against the ledger.')) recompute.mutate(true); }}>
                                Reconcile
                            </Button>
                            {user?.role === 'admin' && (
                                <Button variant="danger" size="sm" loading={del.isPending} onClick={() => { if (confirm('Delete this order? Reservations will be released and confirmed stock restocked first.')) del.mutate(); }}>
                                    Delete
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </Modal>
        </>
    );
}
