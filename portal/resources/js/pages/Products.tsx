import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import {
    Boxes, ChevronDown, ChevronRight, Layers, Minus, Plus, Trash2, TriangleAlert,
} from 'lucide-react';
import { ReservationsPanel } from '../components/ReservationsPanel';
import { apiDelete, apiGet, apiPost, apiPut, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { money, number } from '../lib/format';
import {
    Badge, Button, Card, EmptyState, Flash, Input, Modal, PageHeader, Pagination, Select,
} from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

interface Product {
    id: number;
    name: string;
    sku: string | null;
    source: 'woo' | 'manual';
    store_name: string | null;
    price: number;
    cost: number | null;
    quantity_on_hand: number;
    quantity_reserved: number;
    available: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
    stock_value: number;
    categories: Array<{ id: number; name: string }>;
    has_recipe: boolean;
    material_cost: number;
    is_active: boolean;
}

interface BomLine {
    id: number;
    component_type: 'item' | 'product';
    item_id: number | null;
    component_product_id: number | null;
    item_name: string | null;
    item_sku: string | null;
    item_unit: string;
    item_cost: number;
    item_available: number;
    product_name: string | null;
    product_sku: string | null;
    product_cost: number | null;
    product_available: number | null;
    quantity: number;
    line_cost: number;
}

type BomLineInput = { type: 'item' | 'product'; id: number; quantity: string };

interface ItemOption { id: number; name: string; available: number; cost_price: number; unit: string }

export default function Products() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const currency = user?.organization?.currency ?? 'BDT';

    const [page, setPage] = useState(1);
    const [noRecipe, setNoRecipe] = useState(false);
    const [flash, setFlash] = useState<string | null>(null);
    const [selected, setSelected] = useState<Product | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', sku: '', price: '0' });

    const { data, isLoading } = useQuery({
        queryKey: ['products', page, noRecipe],
        queryFn: () =>
            apiGet<{ data: Product[]; current_page: number; last_page: number; total: number }>('/products', {
                page, per_page: 25, no_recipe: noRecipe || undefined,
            }),
    });

    const { data: itemData } = useQuery({ queryKey: ['items-for-bom'], queryFn: () => apiGet<{ data: ItemOption[] }>('/items', { per_page: 200 }) });

    const { data: productData } = useQuery({
        queryKey: ['products-for-bom'],
        queryFn: () => apiGet<{ data: Product[] }>('/products', { per_page: 200 }),
    });

    const create = useMutation({
        mutationFn: () => apiPost('/products', createForm),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['products'] }); setCreateOpen(false); setFlash('Product created.'); setCreateForm({ name: '', sku: '', price: '0' }); },
        onError: (e) => setFlash(errMsg(e)),
    });

    const remove = useMutation({
        mutationFn: (id: number) => apiDelete(`/products/${id}`),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['products'] }); setFlash('Product deleted.'); },
    });

    const rows = data?.data ?? [];
    const active = rows.find((p) => p.id === selected?.id) ?? rows[0] ?? null;
    const productOptions = (productData?.data ?? []).filter((p) => p.id !== active?.id);

    const allProducts = productData?.data ?? [];
    const withoutRecipe = allProducts.filter((p) => !p.has_recipe).length;
    const finishedAtPrice = allProducts.reduce((sum, p) => sum + p.available * p.price, 0);
    const productTotal = allProducts.length;

    const withRecipe = rows.filter((p) => p.has_recipe);
    const bomQueries = useQueries({
        queries: withRecipe.map((p) => ({
            queryKey: ['product-bom', p.id],
            queryFn: () => apiGet<{ bom: BomLine[] }>(`/products/${p.id}/bom`),
        })),
    });
    const componentCount = (p: Product): number | null => {
        if (!p.has_recipe) return 0;
        const index = withRecipe.findIndex((row) => row.id === p.id);
        const bom = index === -1 ? undefined : bomQueries[index]?.data;
        return bom ? bom.bom.length : null;
    };

    const columns: Column<Product>[] = [
        {
            head: 'Product',
            key: true,
            cell: (p) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">{p.name}</span>
                    {p.sku && <span className="sp-code">{p.sku}</span>}
                </span>
            ),
        },
        { head: 'Price', cell: (p) => money(p.price, currency), align: 'end', code: true },
        {
            head: 'Available',
            cell: (p) => (p.available === 0 ? <span className="text-danger">{number(p.available)}</span> : number(p.available)),
            align: 'end',
            code: true,
        },
        { head: 'Reserved', cell: (p) => number(p.quantity_reserved), align: 'end', code: true, hide: 'lg' },
        {
            head: 'Recipe',
            cell: (p) => {
                if (!p.has_recipe) return <Badge tone="amber">no recipe</Badge>;
                const count = componentCount(p);
                if (count === null) return <span className="sp-code text-xs text-ink-subtle">—</span>;
                return <Badge>{`${number(count, 0)} ${count === 1 ? 'component' : 'components'}`}</Badge>;
            },
        },
        {
            head: 'Cost / unit',
            cell: (p) => (p.has_recipe ? money(p.material_cost, currency) : 'n/a'),
            align: 'end',
            code: true,
            hide: 'md',
        },
        {
            head: 'Margin',
            cell: (p) => {
                if (!p.has_recipe || p.price <= 0) return <span className="text-xs text-ink-subtle">n/a</span>;
                return <span className="sp-code">{Math.round(((p.price - p.material_cost) / p.price) * 100)}%</span>;
            },
            align: 'end',
        },
    ];

    return (
        <div>
            <PageHeader
                title="Products & Recipes"
                subtitle="Sellable products (synced from WooCommerce) and the components each one consumes"
                actions={
                    user?.role !== 'staff' ? (
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus className="size-4" aria-hidden="true" />
                            Manual product
                        </Button>
                    ) : undefined
                }
            />

            <Flash message={flash} />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Products</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{number(productTotal, 0)}</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Without a recipe</div>
                    <div className={`sp-figure mt-1.5 text-2xl ${withoutRecipe > 0 ? 'text-danger' : 'text-ink'}`}>
                        {number(withoutRecipe, 0)}
                    </div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Finished goods at price</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{money(finishedAtPrice, currency)}</div>
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
                <Card bodyClassName="p-0">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-3 py-2.5">
                        <div className="min-w-0">
                            <h2 className="text-sm font-semibold text-ink">Products</h2>
                            <p className="mt-0.5 text-xs text-ink-subtle">
                                Attach a recipe to each so orders deduct inventory automatically.
                            </p>
                        </div>
                        <label className="sp-hit-row gap-2 text-xs text-ink-muted">
                            <input
                                type="checkbox"
                                checked={noRecipe}
                                onChange={(e) => { setNoRecipe(e.target.checked); setPage(1); }}
                                className="size-4 accent-brand-600"
                            />
                            No recipe only
                        </label>
                    </div>

                    <div className="px-3 py-1">
                        <DataTable
                            columns={columns}
                            rows={rows}
                            rowKey={(p) => p.id}
                            activeKey={active?.id ?? null}
                            onRowClick={(p) => setSelected(p)}
                            empty={
                                isLoading ? undefined : (
                                    <EmptyState
                                        title={noRecipe ? 'Every product has a recipe' : 'No products yet'}
                                        hint="Products appear here automatically once the WooCommerce plugin connects."
                                        icon={<Boxes className="size-5" aria-hidden="true" />}
                                    />
                                )
                            }
                        />
                    </div>

                    {data && data.last_page > 1 && (
                        <div className="border-t border-line px-3 py-2">
                            <Pagination meta={data} onPage={setPage} />
                        </div>
                    )}
                </Card>

                <Card
                    title="Recipe"
                    description={active ? active.name : 'Pick a product to see and edit its components.'}
                    actions={
                        active && user?.role === 'admin' ? (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => { if (confirm(`Delete ${active.name}?`)) remove.mutate(active.id); }}
                            >
                                <Trash2 className="size-3.5" aria-hidden="true" />
                                Delete
                            </Button>
                        ) : undefined
                    }
                    bodyClassName="p-3"
                >
                    {active ? (
                        <RecipeEditor
                            key={active.id}
                            product={active}
                            items={itemData?.data ?? []}
                            productOptions={productOptions}
                            currency={currency}
                        />
                    ) : (
                        <EmptyState
                            title="Nothing selected"
                            hint="Choose a product in the list."
                            icon={<Layers className="size-5" aria-hidden="true" />}
                        />
                    )}
                </Card>
            </div>

            <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Create manual product">
                <div className="space-y-3">
                    <Input label="Name *" value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} />
                    <Input label="SKU" value={createForm.sku} onChange={(e) => setCreateForm({ ...createForm, sku: e.target.value })} />
                    <Input label="Price" type="number" step="0.01" value={createForm.price} onChange={(e) => setCreateForm({ ...createForm, price: e.target.value })} />
                    <Button className="w-full" loading={create.isPending} onClick={() => create.mutate()}>Create</Button>
                </div>
            </Modal>
        </div>
    );
}

function RecipeEditor({ product, items, productOptions, currency }: {
    product: Product;
    items: ItemOption[];
    productOptions: Product[];
    currency: string;
}) {
    const qc = useQueryClient();
    const [lines, setLines] = useState<BomLineInput[] | null>(null);
    const [flash, setFlash] = useState<string | null>(null);
    const [reservationsOpen, setReservationsOpen] = useState(false);
    const [stock, setStock] = useState({
        cost: product.cost != null ? String(product.cost) : '',
        onHand: String(product.quantity_on_hand),
        threshold: String(product.low_stock_threshold),
    });

    const { data } = useQuery({
        queryKey: ['product-bom', product.id],
        queryFn: () => apiGet<{ bom: BomLine[] }>(`/products/${product.id}/bom`),
    });

    const saveProductInfo = useMutation({
        mutationFn: () =>
            apiPut(`/products/${product.id}`, {
                cost: stock.cost ? Number(stock.cost) : null,
                quantity_on_hand: Number(stock.onHand),
                low_stock_threshold: Number(stock.threshold),
            }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['products'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFlash('Product cost / stock / warning saved.');
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const adjustStock = useMutation({
        mutationFn: (type: 'add' | 'remove') =>
            apiPost<{ product: Product }>(`/products/${product.id}/adjust`, { type, quantity: '1' }),
        onSuccess: (r) => {
            qc.invalidateQueries({ queryKey: ['products'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setStock((s) => ({ ...s, onHand: String(r.product.quantity_on_hand) }));
            setFlash('Stock adjusted.');
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    useEffect(() => {
        if (data && lines === null) {
            setLines(data.bom.map((l) => ({
                type: l.component_type,
                id: l.component_type === 'product' ? (l.component_product_id ?? 0) : (l.item_id ?? 0),
                quantity: String(l.quantity),
            })));
        }
    }, [data, lines]);

    const save = useMutation({
        mutationFn: () => apiPut(`/products/${product.id}/bom`, { lines: lines ?? [] }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['products'] });
            qc.invalidateQueries({ queryKey: ['product-bom', product.id] });
            setFlash('Recipe saved.');
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const used = new Set((lines ?? []).map((l) => `${l.type}:${l.id}`));

    const addLine = () => {
        const freeItem = items.find((i) => !used.has(`item:${i.id}`));
        const freeProduct = productOptions.find((p) => !used.has(`product:${p.id}`));
        const type = freeItem ? 'item' : 'product';
        const id = freeItem ? freeItem.id : (freeProduct?.id ?? 0);
        if (!id) return;
        setLines([...(lines ?? []), { type, id, quantity: '1' }]);
    };

    const setLineType = (index: number, type: 'item' | 'product') => {
        setLines((prev) => {
            const cur = prev ?? [];
            const usedElsewhere = new Set(cur.map((l, i) => (i === index ? '' : `${l.type}:${l.id}`)));
            const freeItem = type === 'item' ? items.find((i) => !usedElsewhere.has(`item:${i.id}`)) : undefined;
            const freeProduct = type === 'product' ? productOptions.find((p) => !usedElsewhere.has(`product:${p.id}`)) : undefined;
            const id = type === 'item' ? (freeItem?.id ?? 0) : (freeProduct?.id ?? 0);
            return cur.map((l, i) => (i === index ? { type, id, quantity: l.quantity } : l));
        });
    };

    const setLineId = (index: number, id: number) => {
        setLines((prev) => (prev ?? []).map((l, i) => (i === index ? { ...l, id: Number(id) } : l)));
    };

    const setQty = (index: number, quantity: string) => {
        setLines((prev) => (prev ?? []).map((l, i) => (i === index ? { ...l, quantity } : l)));
    };

    const removeLine = (index: number) => setLines((prev) => (prev ?? []).filter((_, i) => i !== index));

    const lineInfo = (line: BomLineInput): { name: string; sku: string | null; cost: number; unit: string } => {
        if (line.type === 'product') {
            const p = productOptions.find((x) => x.id === line.id);
            return { name: p?.name ?? 'Unknown product', sku: p?.sku ?? null, cost: p?.cost ?? 0, unit: 'unit' };
        }
        const it = items.find((x) => x.id === line.id);
        const saved = data?.bom.find((b) => b.component_type === 'item' && b.item_id === line.id);
        return {
            name: it?.name ?? 'Unknown item',
            sku: saved?.item_sku ?? null,
            cost: it?.cost_price ?? 0,
            unit: it?.unit ?? 'unit',
        };
    };

    const lineList = lines ?? [];
    const materialCost = lineList.reduce((sum, l) => sum + Number(l.quantity) * lineInfo(l).cost, 0);
    const margin = product.price > 0 ? Math.round(((product.price - materialCost) / product.price) * 100) : 0;
    const liveAvailable = Number(stock.onHand) - product.quantity_reserved;

    return (
        <div className="space-y-3">
            <Flash message={flash} />

            {lines !== null && lineList.length === 0 && (
                <div className="border border-line border-l-2 border-l-figure bg-surface px-3 py-4">
                    <div className="flex items-start gap-2">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                        <div>
                            <div className="text-sm font-semibold text-ink">No recipe yet</div>
                            <p className="mt-0.5 max-w-prose text-xs text-ink-muted">
                                Without a recipe an order for this product cannot deduct stock. Add its components and the
                                ledger will start tracking it.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {lineList.length > 0 && (
                <div className="border border-line">
                    <ul>
                        {lineList.map((line, index) => {
                            const info = lineInfo(line);
                            return (
                                <li key={index} className="border-b border-line px-3 py-2 last:border-b-0">
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <div className="w-24 shrink-0">
                                            <Select
                                                aria-label={`Component type of line ${index + 1}`}
                                                value={line.type}
                                                onChange={(e) => setLineType(index, e.target.value as 'item' | 'product')}
                                                className="py-1 text-xs"
                                            >
                                                <option value="item">Item</option>
                                                <option value="product">Product</option>
                                            </Select>
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            {line.type === 'item' ? (
                                                <Select
                                                    aria-label={`Component of line ${index + 1}`}
                                                    value={String(line.id)}
                                                    onChange={(e) => setLineId(index, Number(e.target.value))}
                                                    className="py-1"
                                                >
                                                    {items.filter((i) => i.id === line.id || !used.has(`item:${i.id}`)).map((i) => (
                                                        <option key={i.id} value={String(i.id)}>{i.name} ({i.available} avail)</option>
                                                    ))}
                                                </Select>
                                            ) : (
                                                <Select
                                                    aria-label={`Component of line ${index + 1}`}
                                                    value={String(line.id)}
                                                    onChange={(e) => setLineId(index, Number(e.target.value))}
                                                    className="py-1"
                                                >
                                                    {productOptions.filter((p) => p.id === line.id || !used.has(`product:${p.id}`)).map((p) => (
                                                        <option key={p.id} value={String(p.id)}>{p.name} ({p.available} avail)</option>
                                                    ))}
                                                </Select>
                                            )}
                                            <span className="sp-code mt-0.5 block truncate text-[11px]">
                                                {info.sku ?? '—'} · {money(info.cost, currency)} a {info.unit}
                                            </span>
                                        </div>

                                        <div className="flex shrink-0 items-center gap-1">
                                            <span className="sp-code text-xs text-ink-subtle" aria-hidden="true">×</span>
                                            <div className="w-14">
                                                <Input
                                                    aria-label={`Quantity per unit of ${info.name}`}
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    value={line.quantity}
                                                    onChange={(e) => setQty(index, e.target.value)}
                                                    className="py-1 text-right text-xs"
                                                />
                                            </div>
                                        </div>

                                        <span className="sp-code w-20 shrink-0 text-right text-sm text-ink">
                                            {money(Number(line.quantity) * info.cost, currency)}
                                        </span>

                                        <button
                                            type="button"
                                            aria-label={`Remove ${info.name} from the recipe`}
                                            onClick={() => removeLine(index)}
                                            className="grid size-8 shrink-0 place-items-center text-ink-subtle hover:bg-surface-sunken hover:text-danger"
                                        >
                                            <Trash2 className="size-3.5" aria-hidden="true" />
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="sp-plate flex flex-wrap items-baseline justify-between gap-2 px-3 py-2.5">
                        <span className="text-xs">Material cost per unit</span>
                        <span className="sp-figure text-xl">{money(materialCost, currency)}</span>
                    </div>

                    <div className="flex items-baseline justify-between gap-2 border-t border-line px-3 py-2 text-xs text-ink-muted">
                        <span>
                            Sells at <span className="sp-code text-ink">{money(product.price, currency)}</span>
                        </span>
                        <span>
                            Margin <span className={`sp-code ${margin < 0 ? 'text-danger' : 'text-ink'}`}>{margin}%</span>
                        </span>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={addLine}
                    disabled={used.size >= items.length + productOptions.length}
                >
                    <Plus className="size-3.5" aria-hidden="true" />
                    Add component
                </Button>
                <Button size="sm" loading={save.isPending} onClick={() => save.mutate()}>Save recipe</Button>
            </div>

            <p className="text-xs text-ink-subtle">
                An order consumes this product's own stock plus the components above. Add an <b className="text-ink">item</b> for
                raw materials, or a <b className="text-ink">product</b> to consume another product (e.g. a belt or money bag)
                along with its own recipe.
            </p>

            <div className="border border-line bg-surface-sunken p-3">
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <span className="text-xs font-semibold text-ink-muted">Finished goods (manual)</span>
                    <span className="text-[11px] text-ink-subtle">This product's own stock is deducted when it is ordered</span>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Input
                        label="Cost per unit"
                        type="number"
                        step="0.01"
                        min="0"
                        value={stock.cost}
                        onChange={(e) => setStock({ ...stock, cost: e.target.value })}
                    />
                    <div>
                        <span className="mb-1.5 block text-xs font-semibold text-ink-muted">Units on hand</span>
                        <div className="flex items-center gap-1">
                            <Button size="sm" variant="secondary" aria-label="Remove one unit" onClick={() => adjustStock.mutate('remove')}>
                                <Minus className="size-3.5" aria-hidden="true" />
                            </Button>
                            <span className={`flex-1 border border-field-line bg-surface px-3 py-1.5 text-center text-sm font-medium ${Number(stock.onHand) < 0 ? 'text-danger' : 'text-ink'}`}>
                                {stock.onHand}
                            </span>
                            <Button size="sm" variant="secondary" aria-label="Add one unit" onClick={() => adjustStock.mutate('add')}>
                                <Plus className="size-3.5" aria-hidden="true" />
                            </Button>
                        </div>
                    </div>
                    <Input
                        label="Low-stock warning threshold"
                        type="number"
                        step="0.0001"
                        min="0"
                        value={stock.threshold}
                        onChange={(e) => setStock({ ...stock, threshold: e.target.value })}
                    />
                </div>

                <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-ink-muted">
                    <span>Reserved: <b className="sp-code text-ink">{number(product.quantity_reserved)}</b></span>
                    <span>
                        Available:{' '}
                        <b className={liveAvailable < 0 ? 'sp-code text-danger' : 'sp-code text-ink'}>{number(liveAvailable)}</b>
                    </span>
                    {liveAvailable < 0 && (
                        <span className="text-danger">
                            Short by {number(Math.abs(liveAvailable))} after reservations
                        </span>
                    )}
                </div>

                <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <span className="text-xs text-ink-subtle">
                        {stock.cost ? `Value: ${money(Number(stock.cost) * Number(stock.onHand), currency)}` : 'Set a cost to see value'}
                    </span>
                    <Button size="sm" loading={saveProductInfo.isPending} onClick={() => saveProductInfo.mutate()}>
                        Save cost / stock / warning
                    </Button>
                </div>
            </div>

            <div>
                <Button size="sm" variant="ghost" onClick={() => setReservationsOpen((open) => !open)}>
                    {reservationsOpen ? (
                        <ChevronDown className="size-3.5" aria-hidden="true" />
                    ) : (
                        <ChevronRight className="size-3.5" aria-hidden="true" />
                    )}
                    Reservations
                </Button>
                {reservationsOpen && (
                    <div className="mt-2">
                        <ReservationsPanel kind="product" id={product.id} />
                    </div>
                )}
            </div>
        </div>
    );
}
