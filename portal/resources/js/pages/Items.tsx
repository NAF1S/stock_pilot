import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ReservationsPanel } from '../components/ReservationsPanel';
import { apiDelete, apiGet, apiPost, apiPut, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { cn } from '../lib/cn';
import { money, number } from '../lib/format';
import {
    Button, Card, EmptyState, Flash, Input, Modal, PageHeader, Pagination, Select, Switch,
} from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';
import { TableSkeleton } from '../components/ui/States';

interface Item {
    id: number;
    name: string;
    sku: string | null;
    unit: string;
    category_id: number | null;
    category_name: string | null;
    cost_price: number;
    quantity_on_hand: number;
    quantity_reserved: number;
    available: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
    asset_value: number;
    is_active: boolean;
}

interface Category { id: number; name: string }

const emptyForm = {
    name: '', sku: '', category_id: '', unit: 'pcs', cost_price: '0', quantity_on_hand: '0', low_stock_threshold: '0',
};

function coverPct(onHand: number, threshold: number): number {
    if (threshold <= 0) return 100;
    return Math.max(0, Math.min(100, (onHand / threshold) * 100));
}

function OnHand({ item }: { item: Item }) {
    const out = item.quantity_on_hand <= 0;
    const pct = coverPct(item.quantity_on_hand, item.low_stock_threshold);

    return (
        <span className="inline-flex items-center gap-2">
            <span className="block h-1 w-16 shrink-0 bg-line" aria-hidden="true">
                <span
                    className={cn(
                        'block h-full',
                        out ? 'bg-danger' : item.is_low_stock ? 'bg-figure' : 'bg-positive',
                    )}
                    style={{ width: `${Math.max(pct, 4)}%` }}
                />
            </span>
            <span className={cn('font-mono text-xs', out ? 'text-danger' : 'text-ink')}>
                {number(item.quantity_on_hand)}
            </span>
            <span className="font-mono text-xs text-ink-subtle">{item.unit}</span>
        </span>
    );
}

export default function Items() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const currency = user?.organization?.currency ?? 'BDT';

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');
    const [lowOnly, setLowOnly] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);
    const [form, setForm] = useState(emptyForm);
    const [adjusting, setAdjusting] = useState<Item | null>(null);
    const [reservationsFor, setReservationsFor] = useState<number | null>(null);
    const [adj, setAdj] = useState({ type: 'add', quantity: '1', reason: '' });
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['items', page, search, category, lowOnly],
        queryFn: () =>
            apiGet<{ data: Item[]; current_page: number; last_page: number; total: number }>('/items', {
                page, per_page: 25, search: search || undefined, category_id: category || undefined, low_stock_only: lowOnly || undefined,
            }),
    });

    const { data: categories } = useQuery({ queryKey: ['categories'], queryFn: () => apiGet<{ categories: Category[] }>('/categories') });

    const save = useMutation({
        mutationFn: (body: Record<string, unknown>) =>
            editing ? apiPut(`/items/${editing.id}`, body) : apiPost('/items', body),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFormOpen(false);
            setFlash(editing ? 'Item updated.' : 'Item created.');
            setEditing(null);
            setForm(emptyForm);
        },
        onError: (e) => setError(errMsg(e)),
    });

    const remove = useMutation({
        mutationFn: (id: number) => apiDelete(`/items/${id}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash('Item deleted.');
        },
    });

    const adjust = useMutation({
        mutationFn: (body: { type: string; quantity: string; reason: string }) =>
            apiPost(`/items/${adjusting!.id}/adjust`, body),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setAdjusting(null);
            setFlash('Stock adjusted.');
        },
        onError: (e) => setError(errMsg(e)),
    });

    const openNew = () => {
        setEditing(null);
        setForm(emptyForm);
        setFormOpen(true);
    };

    const openEdit = (item: Item) => {
        setEditing(item);
        setForm({
            name: item.name,
            sku: item.sku ?? '',
            category_id: item.category_id ? String(item.category_id) : '',
            unit: item.unit,
            cost_price: String(item.cost_price),
            quantity_on_hand: String(item.quantity_on_hand),
            low_stock_threshold: String(item.low_stock_threshold),
        });
        setFormOpen(true);
    };

    const catOptions = useMemo(
        () => categories?.categories ?? [],
        [categories],
    );

    const submit = () => {
        setError(null);
        save.mutate({
            name: form.name,
            sku: form.sku || null,
            category_id: form.category_id || null,
            unit: form.unit,
            cost_price: Number(form.cost_price),
            quantity_on_hand: Number(form.quantity_on_hand),
            low_stock_threshold: Number(form.low_stock_threshold),
        });
    };

    const rows = useMemo(() => data?.data ?? [], [data]);
    const tracked = data?.total ?? rows.length;
    const belowThreshold = rows.filter((i) => i.is_low_stock).length;
    const stockAtCost = rows.reduce((sum, i) => sum + Number(i.asset_value ?? 0), 0);

    const openAdjust = (item: Item) => {
        setAdjusting(item);
        setAdj({ type: 'add', quantity: '1', reason: '' });
        setError(null);
    };

    const columns: Column<Item>[] = [
        {
            head: 'Component',
            key: true,
            cell: (item) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">{item.name}</span>
                    {item.sku && <span className="sp-code">{item.sku}</span>}
                </span>
            ),
        },
        { head: 'Category', cell: (item) => item.category_name ?? '—', hide: 'lg' },
        { head: 'On hand', cell: (item) => <OnHand item={item} /> },
        { head: 'Reserved', cell: (item) => number(item.quantity_reserved), align: 'end', code: true, hide: 'md' },
        { head: 'Cost', cell: (item) => money(item.cost_price, currency), align: 'end', hide: 'lg' },
        { head: 'Value', cell: (item) => money(item.asset_value, currency), align: 'end', code: true },
        {
            head: '',
            align: 'end',
            actions: true,
            cell: (item) => (
                <span className="flex items-center justify-end gap-1">
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={(e) => { e.stopPropagation(); openAdjust(item); }}
                        aria-label={`Adjust stock for ${item.name}`}
                    >
                        Adjust
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={(e) => { e.stopPropagation(); openEdit(item); }}
                        aria-label={`Edit ${item.name}`}
                    >
                        <Pencil className="size-3.5" aria-hidden="true" />
                    </Button>
                    {user?.role === 'admin' && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={(e) => {
                                e.stopPropagation();
                                if (confirm(`Delete ${item.name}?`)) remove.mutate(item.id);
                            }}
                            aria-label={`Delete ${item.name}`}
                        >
                            <Trash2 className="size-3.5" aria-hidden="true" />
                        </Button>
                    )}
                </span>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Items"
                subtitle="Components you stock — cost, quantity and warning thresholds."
                actions={
                    <Button onClick={openNew}>
                        <Plus className="size-4" aria-hidden="true" />
                        Add item
                    </Button>
                }
            />

            <Flash message={flash} />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Components tracked</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{number(tracked)}</div>
                    <div className="mt-1 text-xs text-ink-subtle">matching this filter</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Below threshold</div>
                    <div className="sp-figure mt-1.5 text-2xl text-danger">{number(belowThreshold)}</div>
                    <div className="mt-1 text-xs text-ink-subtle">on this page</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Stock at cost</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{money(stockAtCost, currency)}</div>
                    <div className="mt-1 text-xs text-ink-subtle">on this page</div>
                </div>
            </div>

            <Card bodyClassName="p-0">
                <div className="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2.5">
                    <div className="relative min-w-0 flex-1 basis-full sm:basis-auto sm:max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
                        <Input
                            value={search}
                            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                            placeholder="Name or SKU…"
                            aria-label="Search components"
                            className="pl-8"
                        />
                    </div>
                    <div className="w-40 shrink-0">
                        <Select
                            value={category}
                            onChange={(e) => { setCategory(e.target.value); setPage(1); }}
                            aria-label="Filter by category"
                        >
                            <option value="">All categories</option>
                            {catOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </Select>
                    </div>
                    <div className="flex min-w-0 shrink-0 items-center sm:ml-auto">
                        <Switch
                            checked={lowOnly}
                            onChange={(next) => { setLowOnly(next); setPage(1); }}
                            label="Low stock only"
                        />
                    </div>
                </div>

                <div className="px-3 py-1">
                    {isLoading ? (
                        <TableSkeleton rows={6} cols={5} />
                    ) : (
                        <DataTable
                            columns={columns}
                            rows={rows}
                            rowKey={(item) => item.id}
                            onRowClick={(item) =>
                                setReservationsFor((current) => (current === item.id ? null : item.id))
                            }
                            activeKey={reservationsFor}
                            empty={
                                <EmptyState
                                    title="No items yet"
                                    hint="Add your first component, e.g. Thank-you card."
                                    action={<Button variant="secondary" onClick={openNew}>Add item</Button>}
                                />
                            }
                        />
                    )}
                </div>

                {rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line px-3 py-2 text-xs text-ink-subtle">
                        <span>
                            Showing {number(rows.length)} of {number(tracked)} components
                        </span>
                        <span>Select a component to see the orders reserving it.</span>
                    </div>
                )}

                {rows.filter((item) => item.id === reservationsFor).map((item) => (
                    <div key={item.id} className="border-t-2 border-line-strong">
                        <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <h2 className="min-w-0 text-sm font-semibold text-ink">
                                Reservations · {item.name}
                                {item.sku && <span className="sp-code ml-2 font-normal">{item.sku}</span>}
                            </h2>
                            <Button size="sm" variant="ghost" onClick={() => setReservationsFor(null)}>
                                <X className="size-3.5" aria-hidden="true" />
                                Close
                            </Button>
                        </div>
                        <div className="px-3 pb-3">
                            <ReservationsPanel kind="item" id={item.id} />
                        </div>
                    </div>
                ))}
            </Card>

            <Pagination meta={data as never} onPage={setPage} />

            <Modal
                open={formOpen}
                onClose={() => setFormOpen(false)}
                title={editing ? 'Edit item' : 'Add item'}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setFormOpen(false)}>Cancel</Button>
                        <Button loading={save.isPending} onClick={submit}>
                            {editing ? 'Save changes' : 'Create item'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <Flash message={error} tone="red" />
                    <Input label="Name *" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="SKU" value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} />
                        <Select label="Unit" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })}>
                            {['pcs', 'box', 'set', 'roll', 'kg', 'piece'].map((u) => <option key={u}>{u}</option>)}
                        </Select>
                    </div>
                    <Select label="Category" value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
                        <option value="">—</option>
                        {catOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Cost price *" type="number" step="0.01" value={form.cost_price} onChange={(e) => setForm({ ...form, cost_price: e.target.value })} />
                        <Input label="Starting quantity" type="number" step="0.0001" value={form.quantity_on_hand} disabled={!!editing} onChange={(e) => setForm({ ...form, quantity_on_hand: e.target.value })} />
                    </div>
                    <Input label="Low-stock warning threshold" type="number" step="0.0001" value={form.low_stock_threshold} onChange={(e) => setForm({ ...form, low_stock_threshold: e.target.value })} />
                </div>
            </Modal>

            <Modal
                open={!!adjusting}
                onClose={() => setAdjusting(null)}
                title={adjusting ? `Adjust stock — ${adjusting.name}` : 'Adjust stock'}
                description="A correction is a new movement. Rows in the ledger are never edited."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setAdjusting(null)}>Cancel</Button>
                        <Button loading={adjust.isPending} onClick={() => adjust.mutate(adj)}>Apply</Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <Flash message={error} tone="red" />
                    {adjusting && (
                        <div className="sp-plate flex flex-wrap items-baseline justify-between gap-2 px-3 py-2.5">
                            <span className="text-xs">On hand now</span>
                            <span className="sp-figure text-xl">
                                {number(adjusting.quantity_on_hand)} {adjusting.unit}
                            </span>
                        </div>
                    )}
                    <Select label="Action" value={adj.type} onChange={(e) => setAdj({ ...adj, type: e.target.value })}>
                        <option value="add">Add stock</option>
                        <option value="remove">Remove stock</option>
                    </Select>
                    <Input label="Quantity *" type="number" step="0.0001" min="0" value={adj.quantity} onChange={(e) => setAdj({ ...adj, quantity: e.target.value })} />
                    <Input label="Reason" value={adj.reason} onChange={(e) => setAdj({ ...adj, reason: e.target.value })} />
                </div>
            </Modal>
        </>
    );
}
