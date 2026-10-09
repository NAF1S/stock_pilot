import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiGet } from '../lib/api';
import { useAuth } from '../lib/auth';
import { money, number } from '../lib/format';
import { Badge, Card, PageHeader, Spinner, StatCard, Table } from '../components/ui';

interface DashboardData {
    asset_value: number;
    stock_alerts_count: { items: number; products: number; total: number };
    low_stock: Array<{
        id: number;
        name: string;
        sku: string | null;
        unit: string | null;
        quantity_on_hand: number;
        low_stock_threshold: number;
        is_out_of_stock: boolean;
    }>;
    low_stock_products: Array<{
        id: number;
        name: string;
        sku: string | null;
        quantity_on_hand: number;
        quantity_reserved: number;
        available: number;
        low_stock_threshold: number;
        is_out_of_stock: boolean;
    }>;
    movements: {
        today: { total_in: number; total_out: number; net_change: number; total_events: number };
        week: { total_in: number; total_out: number; net_change: number; total_events: number };
        year: { total_in: number; total_out: number; net_change: number; total_events: number };
        top_moving: Array<{
            entity_type: 'item' | 'product';
            entity_id: number;
            name: string;
            sku: string | null;
            in_qty: number;
            out_qty: number;
            net_qty: number;
        }>;
    };
    active_warnings: number;
    orders_by_status: Array<{ status: string; count: number }>;
    recent_orders: Array<{
        id: number;
        woocommerce_number: string;
        customer_name: string;
        status: string;
        reservation_state: string;
        total: number;
        order_created_at: string;
    }>;
    store_health: { connected: number; total: number };
}

const stateTone = (s: string) => {
    switch (s) {
        case 'confirmed': return 'green';
        case 'reserved': return 'blue';
        case 'returned': return 'purple';
        case 'released': return 'gray';
        case 'partial': return 'amber';
        default: return 'gray';
    }
};

export default function Dashboard() {
    const { user } = useAuth();
    const { data, isLoading } = useQuery({
        queryKey: ['dashboard'],
        queryFn: () => apiGet<DashboardData>('/dashboard'),
    });

    const [stockTab, setStockTab] = useState<'items' | 'products'>('items');
    const [movePeriod, setMovePeriod] = useState<'today' | 'week' | 'year'>('week');

    const currency = user?.organization?.currency ?? 'BDT';

    const currentMoveSummary = data?.movements
        ? data.movements[movePeriod]
        : { total_in: 0, total_out: 0, net_change: 0, total_events: 0 };

    return (
        <div>
            <PageHeader title="Dashboard" subtitle="A live view of your inventory value, movements, and order health" />
            {isLoading || !data ? (
                <div className="flex justify-center p-12">
                    <Spinner className="size-8 text-indigo-600" />
                </div>
            ) : (
                <>
                    {data.store_health.total === 0 && (
                        <div className="mb-6 rounded-xl border border-indigo-100 bg-gradient-to-r from-indigo-50 via-purple-50 to-white p-5 shadow-sm">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-xl">🚀</span>
                                        <h3 className="text-base font-bold text-indigo-950">Welcome to your new StockPilot workspace!</h3>
                                    </div>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Connect your WooCommerce store via our bridge plugin to automatically sync your products, orders, and inventory.
                                    </p>
                                </div>
                                <Link
                                    to="/stores"
                                    className="inline-flex shrink-0 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition"
                                >
                                    Connect Store &rarr;
                                </Link>
                            </div>
                        </div>
                    )}

                    {/* Stat Cards */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Asset value" value={money(data.asset_value, currency)} sub="Σ on-hand × cost" />
                        <StatCard
                            label="Stock Alerts"
                            value={data.stock_alerts_count?.total ?? (data.low_stock.length + data.low_stock_products.length)}
                            tone="red"
                            sub={`${data.stock_alerts_count?.items ?? data.low_stock.length} materials · ${data.stock_alerts_count?.products ?? data.low_stock_products.length} products`}
                        />
                        <StatCard
                            label="Orders"
                            value={data.orders_by_status.reduce((a, r) => a + r.count, 0)}
                            sub="All time (synced)"
                        />
                        <StatCard
                            label="Stores connected"
                            value={`${data.store_health.connected}/${data.store_health.total}`}
                            sub="WooCommerce"
                        />
                    </div>

                    {/* 2-Column Grid: Stock Alerts (Materials & Products) & Movement Overview */}
                    <div className="mt-6 grid gap-4 lg:grid-cols-2">
                        {/* Out of Stock & Low Stock Card (Items & Products) */}
                        <Card
                            title={
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex items-center gap-2">
                                        <span>⚠️</span>
                                        <span className="font-semibold text-gray-900">Low &amp; Out of Stock</span>
                                    </div>
                                    <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5">
                                        <button
                                            type="button"
                                            onClick={() => setStockTab('items')}
                                            className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                                                stockTab === 'items'
                                                    ? 'bg-white text-indigo-700 shadow-xs'
                                                    : 'text-gray-600 hover:text-gray-900'
                                            }`}
                                        >
                                            Materials ({data.stock_alerts_count?.items ?? data.low_stock.length})
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setStockTab('products')}
                                            className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                                                stockTab === 'products'
                                                    ? 'bg-white text-indigo-700 shadow-xs'
                                                    : 'text-gray-600 hover:text-gray-900'
                                            }`}
                                        >
                                            Products ({data.stock_alerts_count?.products ?? data.low_stock_products.length})
                                        </button>
                                    </div>
                                </div>
                            }
                        >
                            {stockTab === 'items' ? (
                                data.low_stock.length === 0 ? (
                                    <p className="text-sm text-gray-500 py-3">No materials are low or out of stock. 🎉</p>
                                ) : (
                                    <div className="divide-y divide-gray-100">
                                        {data.low_stock.map((item) => (
                                            <div key={item.id} className="flex items-center justify-between py-2 text-sm">
                                                <div className="min-w-0 pr-2">
                                                    <Link to="/items" className="font-medium text-gray-800 hover:text-indigo-600 truncate block">
                                                        {item.name}
                                                    </Link>
                                                    <span className="text-xs text-gray-500">
                                                        Threshold: {number(item.low_stock_threshold)} {item.unit || ''}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <span className="font-semibold text-gray-700 text-xs sm:text-sm">
                                                        {number(item.quantity_on_hand)} {item.unit || ''}
                                                    </span>
                                                    {item.is_out_of_stock ? (
                                                        <Badge tone="red">Out of stock</Badge>
                                                    ) : (
                                                        <Badge tone="amber">Low stock</Badge>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )
                            ) : data.low_stock_products.length === 0 ? (
                                <p className="text-sm text-gray-500 py-3">No products are low or out of stock. 🎉</p>
                            ) : (
                                <div className="divide-y divide-gray-100">
                                    {data.low_stock_products.map((p) => (
                                        <div key={p.id} className="flex items-center justify-between py-2 text-sm">
                                            <div className="min-w-0 pr-2">
                                                <Link to="/products" className="font-medium text-gray-800 hover:text-indigo-600 truncate block">
                                                    {p.name}
                                                </Link>
                                                <span className="text-xs text-gray-500">
                                                    Reserved: {number(p.quantity_reserved)} · Threshold: {number(p.low_stock_threshold)}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2 shrink-0">
                                                <span className="font-semibold text-gray-700 text-xs sm:text-sm">
                                                    {number(p.available)} avail
                                                </span>
                                                {p.is_out_of_stock ? (
                                                    <Badge tone="red">Out of stock</Badge>
                                                ) : (
                                                    <Badge tone="amber">Low stock</Badge>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <div className="mt-3 pt-2 border-t border-gray-100 flex justify-end">
                                <Link
                                    to={stockTab === 'items' ? '/items' : '/products'}
                                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    Manage {stockTab === 'items' ? 'Materials' : 'Products'} &rarr;
                                </Link>
                            </div>
                        </Card>

                        {/* Stock Movement Overview Card */}
                        <Card
                            title={
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex items-center gap-2">
                                        <span>📈</span>
                                        <span className="font-semibold text-gray-900">Stock Movement Overview</span>
                                    </div>
                                    <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5">
                                        {(
                                            [
                                                { id: 'today', label: 'Today' },
                                                { id: 'week', label: 'This Week' },
                                                { id: 'year', label: 'This Year' },
                                            ] as const
                                        ).map((tab) => (
                                            <button
                                                key={tab.id}
                                                type="button"
                                                onClick={() => setMovePeriod(tab.id)}
                                                className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                                                    movePeriod === tab.id
                                                        ? 'bg-white text-indigo-700 shadow-xs'
                                                        : 'text-gray-600 hover:text-gray-900'
                                                }`}
                                            >
                                                {tab.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            }
                        >
                            {/* Movement Metrics Trio */}
                            <div className="grid grid-cols-3 gap-2 rounded-lg bg-gray-50 p-3 text-center">
                                <div>
                                    <div className="text-[11px] font-medium text-gray-500">Stock Out (-)</div>
                                    <div className="text-base sm:text-lg font-bold text-red-600">
                                        -{number(currentMoveSummary.total_out)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-[11px] font-medium text-gray-500">Stock In (+)</div>
                                    <div className="text-base sm:text-lg font-bold text-emerald-600">
                                        +{number(currentMoveSummary.total_in)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-[11px] font-medium text-gray-500">Net Delta</div>
                                    <div className={`text-base sm:text-lg font-bold ${
                                        currentMoveSummary.net_change >= 0 ? 'text-emerald-700' : 'text-amber-600'
                                    }`}>
                                        {currentMoveSummary.net_change >= 0 ? '+' : ''}{number(currentMoveSummary.net_change)}
                                    </div>
                                </div>
                            </div>

                            {/* Top Active Movers This Week */}
                            <div className="mt-4">
                                <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                                    Top Moved Items &amp; Products (Weekly Flow)
                                </div>
                                {(!data.movements?.top_moving || data.movements.top_moving.length === 0) ? (
                                    <p className="text-xs text-gray-400 py-2">No stock movements recorded this week.</p>
                                ) : (
                                    <div className="space-y-1.5">
                                        {data.movements.top_moving.map((m) => (
                                            <div key={`${m.entity_type}-${m.entity_id}`} className="flex items-center justify-between text-xs py-1">
                                                <div className="flex items-center gap-1.5 truncate pr-2">
                                                    <span className={`inline-block size-1.5 rounded-full ${
                                                        m.entity_type === 'item' ? 'bg-purple-500' : 'bg-blue-500'
                                                    }`} />
                                                    <span className="font-medium text-gray-800 truncate">{m.name}</span>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0 font-semibold">
                                                    <span className="text-red-600">-{number(m.out_qty)}</span>
                                                    {m.in_qty > 0 && <span className="text-emerald-600">+{number(m.in_qty)}</span>}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="mt-4 pt-2 border-t border-gray-100 flex justify-end">
                                <Link
                                    to="/movements"
                                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    View Full Movement Analytics &rarr;
                                </Link>
                            </div>
                        </Card>
                    </div>

                    {/* Orders by Status & Recent Orders */}
                    <div className="mt-6 grid gap-4 lg:grid-cols-3">
                        <div className="lg:col-span-1">
                            <Card title="Orders by status">
                                {data.orders_by_status.length === 0 ? (
                                    <p className="text-sm text-gray-500">No orders synced yet.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {data.orders_by_status.map((r) => (
                                            <div key={r.status} className="flex items-center justify-between text-sm">
                                                <Badge tone="gray">{r.status}</Badge>
                                                <span className="font-medium">{r.count}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Card>
                        </div>

                        <div className="lg:col-span-2">
                            <Card title="Recent orders">
                                <Table
                                    head={['Order', 'Customer', 'Status', 'Reservation', 'Total']}
                                    empty={
                                        data.recent_orders.length === 0 ? (
                                            <p className="p-4 text-sm text-gray-500">Orders synced from WooCommerce will appear here.</p>
                                        ) : undefined
                                    }
                                >
                                    {data.recent_orders.map((o) => (
                                        <tr key={o.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-2 font-medium text-gray-800">#{o.woocommerce_number}</td>
                                            <td className="px-4 py-2">{o.customer_name ?? '—'}</td>
                                            <td className="px-4 py-2"><Badge tone="gray">{o.status}</Badge></td>
                                            <td className="px-4 py-2"><Badge tone={stateTone(o.reservation_state) as 'green'}>{o.reservation_state}</Badge></td>
                                            <td className="px-4 py-2">{money(o.total, currency)}</td>
                                        </tr>
                                    ))}
                                </Table>
                            </Card>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
