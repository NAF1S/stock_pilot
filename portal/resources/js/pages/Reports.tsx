import { useQuery } from '@tanstack/react-query';
import { BarChart3 } from 'lucide-react';
import { useState } from 'react';
import { apiGet } from '../lib/api';
import { useAuth } from '../lib/auth';
import { date, money, number } from '../lib/format';
import { Card, EmptyState, PageHeader, Tabs } from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

interface AssetReport {
    total: number;
    materials_total: number;
    finished_total: number;
    by_category: Array<{ category_id: number; category_name: string; value: number; quantity: number }>;
    by_item: Array<{ id: number; name: string; sku: string | null; category: string | null; quantity_on_hand: number; cost_price: number; value: number }>;
    by_product: Array<{ id: number; name: string; sku: string | null; quantity_on_hand: number; quantity_reserved: number; cost: number; value: number }>;
    snapshots: Array<{ captured_at: string; total_value: number }>;
}

interface Consumption {
    consumption: Array<{ item_id: number; item_name: string; quantity_consumed: number; cost: number; events: number }>;
}

const TABS: { id: 'asset' | 'consumption'; label: string }[] = [
    { id: 'asset', label: 'Asset value' },
    { id: 'consumption', label: 'Consumption' },
];

interface ValueLine {
    id: number;
    label: string;
    units: number;
    value: number;
}

export default function Reports() {
    const { user } = useAuth();
    const currency = user?.organization?.currency ?? 'BDT';
    const [tab, setTab] = useState<'asset' | 'consumption'>('asset');

    const asset = useQuery({ queryKey: ['report-asset'], queryFn: () => apiGet<AssetReport>('/reports/asset-value') });
    const consumption = useQuery({ queryKey: ['report-consumption'], queryFn: () => apiGet<Consumption>('/reports/consumption') });

    const assetLines: ValueLine[] = (asset.data?.by_category ?? []).map((c) => ({
        id: c.category_id,
        label: c.category_name,
        units: c.quantity,
        value: c.value,
    }));

    const consumptionLines: ValueLine[] = (consumption.data?.consumption ?? []).map((c) => ({
        id: c.item_id,
        label: c.item_name,
        units: c.quantity_consumed,
        value: c.cost,
    }));

    const assetColumns: Column<ValueLine>[] = [
        { head: 'Category', key: true, cell: (line) => <span className="font-medium text-ink">{line.label}</span> },
        { head: 'Units', cell: (line) => number(line.units), align: 'end', code: true },
        { head: 'Value', cell: (line) => money(line.value, currency), align: 'end', code: true },
    ];

    const consumptionColumns: Column<ValueLine>[] = [
        { head: 'Item', key: true, cell: (line) => <span className="font-medium text-ink">{line.label}</span> },
        { head: 'Units consumed', cell: (line) => number(line.units), align: 'end', code: true },
        { head: 'COGS', cell: (line) => money(line.value, currency), align: 'end', code: true },
    ];

    const itemColumns: Column<AssetReport['by_item'][number]>[] = [
        { head: 'Item', key: true, cell: (item) => <span className="font-medium text-ink">{item.name}</span> },
        { head: 'Category', hide: 'md', cell: (item) => item.category ?? '—' },
        { head: 'On hand', align: 'end', code: true, cell: (item) => number(item.quantity_on_hand) },
        { head: 'Cost', align: 'end', code: true, hide: 'md', cell: (item) => money(item.cost_price, currency) },
        { head: 'Value', align: 'end', code: true, cell: (item) => money(item.value, currency) },
    ];

    const productColumns: Column<AssetReport['by_product'][number]>[] = [
        { head: 'Product', key: true, cell: (product) => <span className="font-medium text-ink">{product.name}</span> },
        { head: 'SKU', hide: 'lg', code: true, cell: (product) => product.sku ?? '—' },
        { head: 'On hand', align: 'end', code: true, cell: (product) => number(product.quantity_on_hand) },
        { head: 'Reserved', align: 'end', code: true, hide: 'md', cell: (product) => number(product.quantity_reserved) },
        { head: 'Cost', align: 'end', code: true, hide: 'md', cell: (product) => money(product.cost, currency) },
        { head: 'Value', align: 'end', code: true, cell: (product) => money(product.value, currency) },
    ];

    const snapshotColumns: Column<AssetReport['snapshots'][number]>[] = [
        { head: 'Captured', key: true, code: true, cell: (snapshot) => date(snapshot.captured_at) },
        { head: 'Total value', align: 'end', code: true, cell: (snapshot) => money(snapshot.total_value, currency) },
    ];

    const assetTotal = assetLines.reduce((sum, line) => sum + line.value, 0);
    const consumptionTotal = consumptionLines.reduce((sum, line) => sum + line.value, 0);

    return (
        <div>
            <PageHeader
                title="Reports"
                subtitle="What the stock is worth and what it consumed, taken from the ledger at a point in time."
            />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Total asset value</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{money(asset.data?.total ?? 0, currency)}</div>
                    <div className="mt-1 text-xs text-ink-subtle">raw materials plus finished goods</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Raw materials</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{money(asset.data?.materials_total ?? 0, currency)}</div>
                    <div className="mt-1 text-xs text-ink-subtle">components and supplies</div>
                </div>
                <div className="border border-line bg-surface p-3">
                    <div className="text-xs text-ink-subtle">Finished goods</div>
                    <div className="sp-figure mt-1.5 text-2xl text-ink">{money(asset.data?.finished_total ?? 0, currency)}</div>
                    <div className="mt-1 text-xs text-ink-subtle">products with their own stock</div>
                </div>
            </div>

            <Card bodyClassName="p-0">
                <div className="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2.5">
                    <Tabs items={TABS} value={tab} onChange={setTab} />
                    <span className="ml-auto text-xs text-ink-subtle">
                        {tab === 'asset' ? 'valued at unit cost' : 'components consumed by completed orders'}
                    </span>
                </div>

                {tab === 'asset' ? (
                    <>
                        <div className="px-3 py-1">
                            <DataTable
                                columns={assetColumns}
                                rows={assetLines}
                                rowKey={(line) => line.id}
                                empty={
                                    <EmptyState
                                        title="No stock to value yet"
                                        hint="Add items or finished goods and their value will appear here."
                                    />
                                }
                            />
                        </div>
                        <div className="flex items-center justify-between gap-2 border-t-2 border-line-strong px-3 py-2.5">
                            <span className="text-sm font-semibold text-ink">Total</span>
                            <span className="sp-figure text-lg text-ink">{money(assetTotal, currency)}</span>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="px-3 py-1">
                            <DataTable
                                columns={consumptionColumns}
                                rows={consumptionLines}
                                rowKey={(line) => line.id}
                                empty={
                                    <EmptyState
                                        title="Nothing consumed yet"
                                        hint="Complete some orders to see component consumption."
                                    />
                                }
                            />
                        </div>
                        <div className="flex items-center justify-between gap-2 border-t-2 border-line-strong px-3 py-2.5">
                            <span className="text-sm font-semibold text-ink">Total consumed</span>
                            <span className="sp-figure text-lg text-ink">{money(consumptionTotal, currency)}</span>
                        </div>
                    </>
                )}
            </Card>

            {tab === 'asset' && (
                <div className="mt-4 space-y-4">
                    <Card title="Value by item" description="Raw materials at unit cost" bodyClassName="p-0">
                        <div className="px-3 py-1">
                            <DataTable
                                columns={itemColumns}
                                rows={asset.data?.by_item ?? []}
                                rowKey={(item) => item.id}
                                empty={
                                    <EmptyState
                                        title="No items yet"
                                        hint="Components and supplies appear here once they are stocked."
                                    />
                                }
                            />
                        </div>
                    </Card>

                    <Card
                        title="Value by finished goods"
                        description="Products held in their own right, separate from their recipe components"
                        bodyClassName="p-0"
                    >
                        <div className="px-3 py-1">
                            <DataTable
                                columns={productColumns}
                                rows={asset.data?.by_product ?? []}
                                rowKey={(product) => product.id}
                                empty={
                                    <EmptyState
                                        title="No products yet"
                                        hint="Products with their own stock will be valued here."
                                    />
                                }
                            />
                        </div>
                    </Card>

                    {asset.data?.snapshots?.length ? (
                        <Card title="Value history" description="One capture per run, newest first" bodyClassName="p-0">
                            <div className="px-3 py-1">
                                <DataTable
                                    columns={snapshotColumns}
                                    rows={asset.data.snapshots}
                                    rowKey={(snapshot) => `${snapshot.captured_at}-${snapshot.total_value}`}
                                />
                            </div>
                        </Card>
                    ) : null}
                </div>
            )}

            <p className="mt-3 flex items-center gap-1.5 text-xs text-ink-subtle">
                <BarChart3 className="size-3.5" aria-hidden="true" />
                Figures are derived from the append-only movement ledger, so a past report can always be rebuilt exactly.
            </p>
        </div>
    );
}
