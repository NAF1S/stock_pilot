import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiGet } from '../lib/api';
import { number } from '../lib/format';
import { Badge, Card, EmptyState, Input, PageHeader, Spinner, StatCard, Table } from '../components/ui';

interface TimelineBucket {
    label: string;
    full_label: string;
    date: string;
    in_qty: number;
    out_qty: number;
    net_qty: number;
}

interface EntityMovement {
    entity_type: 'item' | 'product';
    entity_id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    in_qty: number;
    out_qty: number;
    net_qty: number;
    reserved_qty: number;
    current_on_hand: number;
    current_reserved: number;
    events: number;
}

interface AnalyticsResponse {
    period: 'day' | 'week' | 'month' | 'year' | 'custom';
    period_label: string;
    range: { from: string; to: string };
    summary: {
        total_in: number;
        total_out: number;
        net_change: number;
        total_reserved: number;
        total_returned: number;
        total_released: number;
        total_events: number;
    };
    timeline: TimelineBucket[];
    breakdown: EntityMovement[];
}

export default function MovementAnalytics() {
    const [period, setPeriod] = useState<'day' | 'week' | 'month' | 'year'>('week');
    const [entity, setEntity] = useState<'all' | 'item' | 'product'>('all');
    const [search, setSearch] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['movement-analytics', period, entity],
        queryFn: () =>
            apiGet<AnalyticsResponse>('/movements/analytics', {
                period,
                entity: entity === 'all' ? undefined : entity,
            }),
    });

    const filteredBreakdown = (data?.breakdown ?? []).filter((item) => {
        if (!search) return true;
        const q = search.toLowerCase();
        return item.name.toLowerCase().includes(q) || (item.sku && item.sku.toLowerCase().includes(q));
    });

    // Calculate maximum volume across timeline buckets for proportional bar chart
    const maxTimelineVolume = Math.max(
        ...(data?.timeline ?? []).map((b) => Math.max(b.in_qty, b.out_qty)),
        1
    );

    return (
        <div>
            <PageHeader
                title="Stock Movement Analytics"
                subtitle="Track material consumption, product shipments, and inventory flow throughout the year, week, and day"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            to="/activity"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition"
                        >
                            <span>📜</span> View Full Activity Log
                        </Link>
                    </div>
                }
            />

            {/* Timeframe & Entity Filter Controls */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                {/* Period Selector Tabs */}
                <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 shadow-xs">
                    {(
                        [
                            { id: 'day', label: 'Today (Day)' },
                            { id: 'week', label: 'This Week' },
                            { id: 'month', label: 'This Month' },
                            { id: 'year', label: 'This Year' },
                        ] as const
                    ).map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => setPeriod(p.id)}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                period === p.id
                                    ? 'bg-white text-indigo-700 shadow-sm'
                                    : 'text-gray-600 hover:text-gray-900'
                            }`}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>

                {/* Entity Scope Toggle */}
                <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 shadow-xs">
                    {(
                        [
                            { id: 'all', label: 'All Stock' },
                            { id: 'item', label: 'Materials (Items)' },
                            { id: 'product', label: 'Products' },
                        ] as const
                    ).map((e) => (
                        <button
                            key={e.id}
                            type="button"
                            onClick={() => setEntity(e.id)}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                entity === e.id
                                    ? 'bg-indigo-600 text-white shadow-sm'
                                    : 'text-gray-600 hover:text-gray-900'
                            }`}
                        >
                            {e.label}
                        </button>
                    ))}
                </div>
            </div>

            {isLoading || !data ? (
                <div className="flex justify-center p-12">
                    <Spinner className="size-8 text-indigo-600" />
                </div>
            ) : (
                <>
                    {/* Summary Stat Cards */}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Total Stock In (+)"
                            value={`+${number(data.summary.total_in)}`}
                            tone="green"
                            sub="Restocks, additions, & returns"
                        />
                        <StatCard
                            label="Total Stock Out (-)"
                            value={`-${number(data.summary.total_out)}`}
                            tone="red"
                            sub="Orders confirmed & deducted"
                        />
                        <StatCard
                            label="Net Stock Delta"
                            value={`${data.summary.net_change >= 0 ? '+' : ''}${number(data.summary.net_change)}`}
                            tone={data.summary.net_change >= 0 ? 'green' : 'amber'}
                            sub="Net inventory flow change"
                        />
                        <StatCard
                            label="Active Reserved"
                            value={number(data.summary.total_reserved)}
                            tone="blue"
                            sub={`${number(data.summary.total_released)} units released from cancelled`}
                        />
                    </div>

                    {/* Timeline Visual Chart Card */}
                    <div className="mt-6">
                        <Card
                            title={
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span>📊</span>
                                        <span className="font-semibold text-gray-900">
                                            Movement Flow Trend — {data.period_label}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-4 text-xs font-medium">
                                        <div className="flex items-center gap-1.5">
                                            <span className="h-3 w-3 rounded-xs bg-emerald-500"></span>
                                            <span className="text-gray-600">Stock In (+)</span>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <span className="h-3 w-3 rounded-xs bg-indigo-600"></span>
                                            <span className="text-gray-600">Stock Out (-)</span>
                                        </div>
                                    </div>
                                </div>
                            }
                        >
                            {data.timeline.length === 0 ? (
                                <p className="text-sm text-gray-500">No movements recorded in this period.</p>
                            ) : (
                                <div className="pt-4">
                                    <div className="grid grid-flow-col auto-cols-fr gap-2 sm:gap-3 items-end h-48 border-b border-gray-200 pb-2">
                                        {data.timeline.map((bucket, idx) => {
                                            const inHeight = Math.round((bucket.in_qty / maxTimelineVolume) * 100);
                                            const outHeight = Math.round((bucket.out_qty / maxTimelineVolume) * 100);

                                            return (
                                                <div
                                                    key={idx}
                                                    className="group relative flex flex-col items-center justify-end h-full"
                                                >
                                                    {/* Tooltip on hover */}
                                                    <div className="absolute -top-16 left-1/2 -translate-x-1/2 hidden group-hover:flex flex-col items-center rounded-md bg-gray-900 px-2 py-1 text-xs text-white z-20 shadow-md pointer-events-none whitespace-nowrap">
                                                        <span className="font-bold">{bucket.full_label || bucket.label}</span>
                                                        <span className="text-emerald-400">In: +{number(bucket.in_qty)}</span>
                                                        <span className="text-indigo-300">Out: -{number(bucket.out_qty)}</span>
                                                    </div>

                                                    {/* Side-by-side bars for In and Out */}
                                                    <div className="flex items-end gap-0.5 sm:gap-1 w-full justify-center h-full">
                                                        <div
                                                            style={{ height: `${Math.max(inHeight, bucket.in_qty > 0 ? 8 : 2)}%` }}
                                                            className={`w-2.5 sm:w-4 rounded-t-sm transition-all ${
                                                                bucket.in_qty > 0 ? 'bg-emerald-500' : 'bg-gray-100'
                                                            }`}
                                                            title={`In: +${bucket.in_qty}`}
                                                        />
                                                        <div
                                                            style={{ height: `${Math.max(outHeight, bucket.out_qty > 0 ? 8 : 2)}%` }}
                                                            className={`w-2.5 sm:w-4 rounded-t-sm transition-all ${
                                                                bucket.out_qty > 0 ? 'bg-indigo-600' : 'bg-gray-100'
                                                            }`}
                                                            title={`Out: -${bucket.out_qty}`}
                                                        />
                                                    </div>

                                                    <span className="mt-2 text-[10px] sm:text-xs font-medium text-gray-500 truncate w-full text-center">
                                                        {bucket.label}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </Card>
                    </div>

                    {/* Breakdown Table Card */}
                    <div className="mt-6">
                        <Card
                            title={
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex items-center gap-2">
                                        <span>📦</span>
                                        <span className="font-semibold text-gray-900">
                                            Item &amp; Product Movement Breakdown ({filteredBreakdown.length})
                                        </span>
                                    </div>
                                    <div className="w-full sm:w-64">
                                        <Input
                                            placeholder="Search by name or SKU..."
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            className="text-xs py-1.5"
                                        />
                                    </div>
                                </div>
                            }
                        >
                            <Table
                                head={[
                                    'Entity',
                                    'Type',
                                    'Stock In (+)',
                                    'Stock Out (-)',
                                    'Net Delta',
                                    'On Hand',
                                    'Reserved',
                                    '',
                                ]}
                                empty={
                                    filteredBreakdown.length === 0 ? (
                                        <EmptyState
                                            title="No stock movements found"
                                            hint="No order or adjustment movements match the current filter and timeframe."
                                        />
                                    ) : undefined
                                }
                            >
                                {filteredBreakdown.map((row) => (
                                    <tr key={`${row.entity_type}-${row.entity_id}`} className="hover:bg-gray-50">
                                        <td className="px-4 py-2.5">
                                            <div className="font-medium text-gray-900">{row.name}</div>
                                            {row.sku && <div className="text-xs text-gray-500">SKU: {row.sku}</div>}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {row.entity_type === 'item' ? (
                                                <Badge tone="purple">Material</Badge>
                                            ) : (
                                                <Badge tone="blue">Product</Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-sm font-semibold text-emerald-600">
                                            {row.in_qty > 0 ? `+${number(row.in_qty)}` : '0'}
                                            {row.unit && <span className="ml-1 text-xs font-normal text-gray-500">{row.unit}</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-sm font-semibold text-red-600">
                                            {row.out_qty > 0 ? `-${number(row.out_qty)}` : '0'}
                                            {row.unit && <span className="ml-1 text-xs font-normal text-gray-500">{row.unit}</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-sm font-bold">
                                            <span className={row.net_qty >= 0 ? 'text-emerald-700' : 'text-red-700'}>
                                                {row.net_qty >= 0 ? `+${number(row.net_qty)}` : number(row.net_qty)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-sm text-gray-800">
                                            {number(row.current_on_hand)}
                                            {row.unit && <span className="ml-1 text-xs text-gray-500">{row.unit}</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-sm text-gray-500">
                                            {number(row.current_reserved)}
                                        </td>
                                        <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                            <Link
                                                to={`/activity?q=${encodeURIComponent(row.sku || row.name)}`}
                                                className="inline-flex items-center text-xs font-semibold text-indigo-600 hover:text-indigo-900"
                                            >
                                                History &rarr;
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    </div>
                </>
            )}
        </div>
    );
}
