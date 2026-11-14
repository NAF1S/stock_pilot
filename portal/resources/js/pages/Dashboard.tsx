import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
    AlertTriangle,
    ArrowRight,
    Boxes,
    Package,
    Plug,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { apiGet } from '../lib/api';
import { useAuth } from '../lib/auth';
import { compact, money, number } from '../lib/format';
import { reservationTone, riskTone, statusTone } from '../lib/status';
import { Badge, Button, Card, EmptyState, PageHeader, Progress, Spinner, StatCard, Tabs } from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

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

type RecentOrder = DashboardData['recent_orders'][number];

export default function Dashboard() {
    const { user } = useAuth();
    const { data, isLoading } = useQuery({
        queryKey: ['dashboard'],
        queryFn: () => apiGet<DashboardData>('/dashboard'),
    });

    const [stockTab, setStockTab] = useState<'items' | 'products'>('items');
    const [period, setPeriod] = useState<'today' | 'week' | 'year'>('week');

    const currency = user?.organization?.currency ?? 'BDT';
    const firstName = user?.name?.trim().split(/\s+/)[0] || 'there';

    const movement = data?.movements
        ? data.movements[period]
        : { total_in: 0, total_out: 0, net_change: 0, total_events: 0 };

    const alerts = data
        ? {
              items: data.stock_alerts_count?.items ?? data.low_stock.length,
              products: data.stock_alerts_count?.products ?? data.low_stock_products.length,
              total:
                  data.stock_alerts_count?.total ??
                  data.low_stock.length + data.low_stock_products.length,
          }
        : { items: 0, products: 0, total: 0 };

    const ordersTotal = data?.orders_by_status.reduce((sum, row) => sum + row.count, 0) ?? 0;

    const orderColumns: Column<RecentOrder>[] = [
        {
            head: 'Order',
            key: true,
            code: true,
            cell: (o) => (
                <span className="font-semibold whitespace-nowrap text-ink">#{o.woocommerce_number}</span>
            ),
        },
        {
            head: 'Customer',
            cell: (o) => o.customer_name || '—',
        },
        {
            head: 'Status',
            cell: (o) => <Badge tone={statusTone(o.status)}>{o.status}</Badge>,
        },
        {
            head: 'Reservation',
            cell: (o) => <Badge tone={reservationTone(o.reservation_state)}>{o.reservation_state}</Badge>,
        },
        {
            head: 'Total',
            align: 'end',
            code: true,
            cell: (o) => <span className="whitespace-nowrap text-ink">{money(o.total, currency)}</span>,
        },
    ];

    return (
        <div>
            <PageHeader
                title={`Welcome back, ${firstName}`}
                subtitle="A live view of your inventory value, stock health and order flow."
                actions={
                    <>
                        <Link to="/app/forecasts">
                            <Button variant="secondary">
                                <Sparkles className="size-4" aria-hidden="true" />
                                AI insights
                            </Button>
                        </Link>
                        <Link to="/app/orders">
                            <Button>
                                View orders
                                <ArrowRight className="size-4" aria-hidden="true" />
                            </Button>
                        </Link>
                    </>
                }
            />
            {isLoading || !data ? (
                <div className="flex justify-center p-12">
                    <Spinner className="size-8 text-brand-600" />
                </div>
            ) : (
                <>
                    {/* Stat row */}
                    <div className="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                        <StatCard
                            label="Asset value"
                            value={money(data.asset_value, currency)}
                            sub="on-hand × cost"
                            tone="blue"
                            icon={<Wallet className="size-4" />}
                        />
                        <StatCard
                            label="Stock alerts"
                            value={alerts.total}
                            sub={`${alerts.items} materials · ${alerts.products} products`}
                            tone="red"
                            icon={<AlertTriangle className="size-4" />}
                        />
                        <StatCard
                            label="Orders synced"
                            value={compact(ordersTotal)}
                            sub="all time (synced)"
                            tone="gray"
                            icon={<Package className="size-4" />}
                        />
                        <StatCard
                            label="Stores connected"
                            value={`${data.store_health.connected} / ${data.store_health.total}`}
                            sub="WooCommerce"
                            tone="green"
                            icon={<Plug className="size-4" />}
                        />
                    </div>

                    {/* Two-column: stock health + movement */}
                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <Card
                            title="Low & out of stock"
                            actions={
                                <Tabs
                                    items={[
                                        { id: 'items', label: 'Materials', count: alerts.items },
                                        { id: 'products', label: 'Products', count: alerts.products },
                                    ]}
                                    value={stockTab}
                                    onChange={setStockTab}
                                />
                            }
                            bodyClassName="p-0"
                        >
                            {stockTab === 'items' ? (
                                data.low_stock.length === 0 ? (
                                    <EmptyState
                                        title="No materials need attention"
                                        hint="Every material is above its low-stock threshold."
                                        icon={<Boxes className="size-5" aria-hidden="true" />}
                                    />
                                ) : (
                                    <ul className="divide-y divide-line">
                                        {data.low_stock.map((item) => {
                                            const pct =
                                                item.low_stock_threshold > 0
                                                    ? (item.quantity_on_hand / item.low_stock_threshold) * 100
                                                    : 0;
                                            return (
                                                <li key={item.id} className="flex items-center gap-3 px-3 py-2.5">
                                                    <div className="min-w-0 flex-1">
                                                        <Link
                                                            to="/app/items"
                                                            className="block truncate text-sm font-medium text-ink hover:text-brand-600 dark:hover:text-brand-400"
                                                        >
                                                            {item.name}
                                                        </Link>
                                                        <div className="sp-code mt-0.5 truncate">
                                                            {item.sku ? `${item.sku} · ` : ''}
                                                            threshold {number(item.low_stock_threshold)}
                                                            {item.unit ? ` ${item.unit}` : ''}
                                                        </div>
                                                        <div className="mt-1.5 max-w-[10rem]">
                                                            <Progress
                                                                value={pct}
                                                                tone={riskTone(
                                                                    item.is_out_of_stock ? 'critical' : 'warning',
                                                                )}
                                                            />
                                                        </div>
                                                    </div>
                                                    <div className="shrink-0 text-right">
                                                        <div className="text-sm font-semibold text-ink">
                                                            {number(item.quantity_on_hand)}
                                                            <span className="ml-1 text-[11px] font-normal text-ink-subtle">
                                                                {item.unit ?? ''}
                                                            </span>
                                                        </div>
                                                        <Badge
                                                            tone={riskTone(
                                                                item.is_out_of_stock ? 'critical' : 'warning',
                                                            )}
                                                            className="mt-1"
                                                        >
                                                            {item.is_out_of_stock ? 'Out of stock' : 'Low stock'}
                                                        </Badge>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )
                            ) : data.low_stock_products.length === 0 ? (
                                <EmptyState
                                    title="No products need attention"
                                    hint="Every product has enough available stock for its threshold."
                                    icon={<Package className="size-5" aria-hidden="true" />}
                                />
                            ) : (
                                <ul className="divide-y divide-line">
                                    {data.low_stock_products.map((product) => {
                                        const pct =
                                            product.low_stock_threshold > 0
                                                ? (product.available / product.low_stock_threshold) * 100
                                                : 0;
                                        return (
                                            <li key={product.id} className="flex items-center gap-3 px-3 py-2.5">
                                                <div className="min-w-0 flex-1">
                                                    <Link
                                                        to="/app/products"
                                                        className="block truncate text-sm font-medium text-ink hover:text-brand-600 dark:hover:text-brand-400"
                                                    >
                                                        {product.name}
                                                    </Link>
                                                    <div className="sp-code mt-0.5 truncate">
                                                        {product.sku ? `${product.sku} · ` : ''}
                                                        threshold {number(product.low_stock_threshold)} · reserved{' '}
                                                        {number(product.quantity_reserved)}
                                                    </div>
                                                    <div className="mt-1.5 max-w-[10rem]">
                                                        <Progress
                                                            value={pct}
                                                            tone={riskTone(
                                                                product.is_out_of_stock ? 'critical' : 'warning',
                                                            )}
                                                        />
                                                    </div>
                                                </div>
                                                <div className="shrink-0 text-right">
                                                    <div className="text-sm font-semibold text-ink">
                                                        {number(product.available)}
                                                        <span className="ml-1 text-[11px] font-normal text-ink-subtle">
                                                            avail
                                                        </span>
                                                    </div>
                                                    <Badge
                                                        tone={riskTone(
                                                            product.is_out_of_stock ? 'critical' : 'warning',
                                                        )}
                                                        className="mt-1"
                                                    >
                                                        {product.is_out_of_stock ? 'Out of stock' : 'Low stock'}
                                                    </Badge>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}

                            <div className="border-t border-line px-3 py-2">
                                <Link
                                    to={stockTab === 'items' ? '/app/items' : '/app/products'}
                                    className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                >
                                    Manage {stockTab === 'items' ? 'materials' : 'products'}
                                    <ArrowRight className="size-3" aria-hidden="true" />
                                </Link>
                            </div>
                        </Card>

                        <Card
                            title="Stock movement"
                            actions={
                                <Tabs
                                    items={[
                                        { id: 'today', label: 'Today' },
                                        { id: 'week', label: 'Week' },
                                        { id: 'year', label: 'Year' },
                                    ]}
                                    value={period}
                                    onChange={setPeriod}
                                />
                            }
                        >
                            <div className="grid grid-cols-3 gap-2 border border-line bg-surface-sunken p-2 text-center">
                                <div>
                                    <div className="text-xs text-ink-subtle">Stock in</div>
                                    <div className="sp-figure mt-1 flex items-center justify-center gap-1 text-lg text-positive">
                                        <TrendingUp className="size-3.5" aria-hidden="true" />+
                                        {number(movement.total_in)}
                                    </div>
                                </div>
                                <div className="border-x border-line">
                                    <div className="text-xs text-ink-subtle">Stock out</div>
                                    <div className="sp-figure mt-1 flex items-center justify-center gap-1 text-lg text-danger">
                                        <TrendingDown className="size-3.5" aria-hidden="true" />-
                                        {number(movement.total_out)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-ink-subtle">Net delta</div>
                                    <div
                                        className={`sp-figure mt-1 text-lg ${
                                            movement.net_change >= 0 ? 'text-positive' : 'text-ink'
                                        }`}
                                    >
                                        {movement.net_change >= 0 ? '+' : ''}
                                        {number(movement.net_change)}
                                    </div>
                                </div>
                            </div>

                            <div className="mt-4">
                                <div className="mb-2 text-xs font-medium text-ink-subtle">
                                    Top movers this week
                                </div>
                                {data.movements?.top_moving && data.movements.top_moving.length > 0 ? (
                                    <ul className="space-y-1.5">
                                        {data.movements.top_moving.map((mover) => (
                                            <li
                                                key={`${mover.entity_type}-${mover.entity_id}`}
                                                className="flex items-center gap-2 text-xs"
                                            >
                                                <span
                                                    className={`size-1.5 shrink-0 ${
                                                        mover.entity_type === 'item'
                                                            ? 'bg-brand-500'
                                                            : 'bg-accent-500'
                                                    }`}
                                                />
                                                <span className="flex-1 truncate text-ink">{mover.name}</span>
                                                <span className="sp-code shrink-0 text-danger">
                                                    -{number(mover.out_qty)}
                                                </span>
                                                {mover.in_qty > 0 && (
                                                    <span className="sp-code shrink-0 text-positive">
                                                        +{number(mover.in_qty)}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-xs text-ink-subtle">No stock movements recorded this week.</p>
                                )}
                            </div>

                            <div className="mt-3 border-t border-line pt-2">
                                <Link
                                    to="/app/movements"
                                    className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                >
                                    Full movement analytics
                                    <ArrowRight className="size-3" aria-hidden="true" />
                                </Link>
                            </div>
                        </Card>
                    </div>

                    {/* Orders */}
                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        <Card title="Orders by status">
                            {data.orders_by_status.length === 0 ? (
                                <p className="text-sm text-ink-subtle">No orders synced yet.</p>
                            ) : (
                                <ul className="space-y-2.5">
                                    {data.orders_by_status.map((row) => (
                                        <li
                                            key={row.status}
                                            className="flex items-center justify-between gap-2"
                                        >
                                            <Badge tone={statusTone(row.status)}>{row.status}</Badge>
                                            <span className="text-sm font-semibold text-ink">
                                                {number(row.count)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>

                        <Card
                            title="Recent orders"
                            className="lg:col-span-2"
                            actions={
                                <Link to="/app/orders">
                                    <Button size="sm" variant="ghost">
                                        View all <ArrowRight className="size-3" aria-hidden="true" />
                                    </Button>
                                </Link>
                            }
                            bodyClassName="p-0"
                        >
                            <DataTable
                                columns={orderColumns}
                                rows={data.recent_orders}
                                rowKey={(order) => order.id}
                                empty={
                                    <EmptyState
                                        title="No recent orders"
                                        hint="Orders synced from WooCommerce will appear here."
                                        icon={<Package className="size-5" aria-hidden="true" />}
                                    />
                                }
                            />
                        </Card>
                    </div>

                    {data.store_health.total === 0 && (
                        <div className="mt-4 border border-line border-l-2 border-l-brand-600 bg-surface p-3 sm:p-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-start gap-3">
                                    <div className="flex size-9 shrink-0 items-center justify-center border border-line bg-surface-sunken text-brand-600 dark:text-brand-300">
                                        <Boxes className="size-4" aria-hidden="true" />
                                    </div>
                                    <div className="min-w-0">
                                        <h3 className="text-sm font-semibold text-ink">
                                            Connect your first WooCommerce store
                                        </h3>
                                        <p className="mt-0.5 text-sm text-ink-muted">
                                            Install the bridge plugin to sync your products, orders and inventory
                                            automatically.
                                        </p>
                                    </div>
                                </div>
                                <Link to="/app/stores" className="shrink-0">
                                    <Button variant="secondary">Connect store</Button>
                                </Link>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
