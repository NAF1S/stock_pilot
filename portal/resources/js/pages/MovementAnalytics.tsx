import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowLeftRight, ArrowRight, LineChart, ScrollText, Search } from 'lucide-react';
import { apiGet } from '../lib/api';
import { number } from '../lib/format';
import {
    Badge,
    Button,
    Card,
    EmptyState,
    Input,
    PageHeader,
    Spinner,
    StatCard,
    Tabs,
    type TabItem,
} from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

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

type Period = 'day' | 'week' | 'month' | 'year';
type Entity = 'all' | 'item' | 'product';

const PERIOD_TABS: TabItem<Period>[] = [
    { id: 'day', label: 'Today' },
    { id: 'week', label: 'Week' },
    { id: 'month', label: 'Month' },
    { id: 'year', label: 'Year' },
];

const ENTITY_TABS: TabItem<Entity>[] = [
    { id: 'all', label: 'Everything' },
    { id: 'item', label: 'Materials' },
    { id: 'product', label: 'Products' },
];

function FlowChart({ buckets }: { buckets: TimelineBucket[] }) {
    const peak = Math.max(...buckets.flatMap((b) => [b.in_qty, b.out_qty]), 1);

    return (
        <figure className="m-0">
            <div className="flex items-end gap-0.5 sm:gap-1.5" style={{ height: 180 }} role="presentation">
                {buckets.map((b) => (
                    <div key={b.date} className="flex min-w-0 flex-1 flex-col justify-end gap-0.5">
                        <div className="flex items-end justify-center gap-0.5">
                            <span
                                className="w-1/2 max-w-3 bg-positive"
                                style={{ height: `${(b.in_qty / peak) * 160}px` }}
                                title={`${b.full_label || b.label} in ${number(b.in_qty)}`}
                            />
                            <span
                                className="w-1/2 max-w-3 bg-danger"
                                style={{ height: `${(b.out_qty / peak) * 160}px` }}
                                title={`${b.full_label || b.label} out ${number(b.out_qty)}`}
                            />
                        </div>
                        <span className="truncate text-center text-[10px] text-ink-subtle">{b.label}</span>
                    </div>
                ))}
            </div>
            <figcaption className="mt-3 flex flex-wrap items-center gap-4 text-xs text-ink-muted">
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 bg-positive" aria-hidden="true" /> Stock in
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 bg-danger" aria-hidden="true" /> Stock out
                </span>
                <span className="ml-auto sp-code">peak {number(peak)}</span>
            </figcaption>
        </figure>
    );
}

export default function MovementAnalytics() {
    const [period, setPeriod] = useState<Period>('week');
    const [entity, setEntity] = useState<Entity>('all');
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

    const movementTotal = filteredBreakdown.reduce((sum, row) => sum + row.in_qty + row.out_qty, 0) || 1;
    const shownEvents = filteredBreakdown.reduce((sum, row) => sum + row.events, 0);

    const columns: Column<EntityMovement>[] = [
        {
            head: 'Entity',
            key: true,
            cell: (row) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">{row.name}</span>
                    <span className="sp-code">{row.sku || 'no SKU'}</span>
                </span>
            ),
        },
        {
            head: 'Type',
            hide: 'lg',
            cell: (row) =>
                row.entity_type === 'item' ? (
                    <Badge tone="purple">Material</Badge>
                ) : (
                    <Badge tone="blue">Product</Badge>
                ),
        },
        {
            head: 'Stock in',
            align: 'end',
            cell: (row) => (
                <span className="sp-code text-positive">
                    {row.in_qty > 0 ? `+${number(row.in_qty)}` : '0'}
                    {row.unit && <span className="ml-1 text-xs text-ink-subtle">{row.unit}</span>}
                </span>
            ),
        },
        {
            head: 'Stock out',
            align: 'end',
            cell: (row) => (
                <span className="sp-code text-danger">
                    {row.out_qty > 0 ? `−${number(row.out_qty)}` : '0'}
                    {row.unit && <span className="ml-1 text-xs text-ink-subtle">{row.unit}</span>}
                </span>
            ),
        },
        {
            head: 'Net',
            align: 'end',
            cell: (row) => (
                <span className={row.net_qty < 0 ? 'sp-code text-danger' : 'sp-code text-positive'}>
                    {row.net_qty > 0 ? '+' : ''}
                    {number(row.net_qty)}
                </span>
            ),
        },
        {
            head: 'On hand',
            align: 'end',
            cell: (row) => <span className="sp-code">{number(row.current_on_hand)}</span>,
        },
        {
            head: 'Reserved',
            align: 'end',
            hide: 'lg',
            cell: (row) => <span className="sp-code">{number(row.current_reserved)}</span>,
        },
        {
            head: 'Share of movement',
            align: 'end',
            hide: 'md',
            cell: (row) => {
                const pct = Math.round(((row.in_qty + row.out_qty) / movementTotal) * 100);
                return (
                    <span className="flex items-center justify-end gap-2">
                        <span className="h-1 w-16 bg-line" aria-hidden="true">
                            <span className="block h-full bg-brand-600" style={{ width: `${pct}%` }} />
                        </span>
                        <span className="sp-code text-xs">{pct}%</span>
                    </span>
                );
            },
        },
        {
            head: '',
            align: 'end',
            actions: true,
            cell: (row) => (
                <Link
                    to={`/app/activity?q=${encodeURIComponent(row.sku || row.name)}`}
                    className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-800"
                    aria-label={`Movement history for ${row.name}`}
                >
                    History
                    <ArrowRight className="size-3" aria-hidden="true" />
                </Link>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Movement analytics"
                subtitle="What came in, what went out, and which stock is carrying the flow across the day, week, month and year."
                actions={
                    <>
                        <Tabs items={PERIOD_TABS} value={period} onChange={(id) => setPeriod(id)} />
                        <Link
                            to="/app/activity"
                            className="inline-flex h-9 items-center gap-2 border-2 border-field-line bg-surface px-3.5 text-sm font-semibold text-ink transition-colors hover:bg-surface-sunken active:bg-line"
                        >
                            <ScrollText className="size-4 text-ink-subtle" aria-hidden="true" />
                            Activity log
                        </Link>
                    </>
                }
            />

            {isLoading || !data ? (
                <div className="grid place-items-center border border-line bg-surface px-4 py-16">
                    <Spinner className="size-6 text-brand-600" />
                    <span className="mt-3 text-xs text-ink-subtle">Reading the ledger…</span>
                </div>
            ) : (
                <>
                    <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Stock in"
                            value={number(data.summary.total_in)}
                            sub={`incl. ${number(data.summary.total_returned)} returned`}
                            tone="green"
                        />
                        <StatCard
                            label="Stock out"
                            value={number(data.summary.total_out)}
                            sub="orders confirmed & deducted"
                            tone="red"
                        />
                        <StatCard
                            label="Net delta"
                            value={`${data.summary.net_change > 0 ? '+' : ''}${number(data.summary.net_change)}`}
                            sub={data.summary.net_change < 0 ? 'drawing down' : 'building up'}
                            tone={data.summary.net_change < 0 ? 'amber' : 'green'}
                        />
                        <StatCard
                            label="Ledger events"
                            value={number(data.summary.total_events)}
                            sub={`${number(data.summary.total_reserved)} reserved · ${number(data.summary.total_released)} released`}
                            tone="gray"
                        />
                    </div>

                    <Card
                        title="Flow through the period"
                        description={`Stock in against stock out — ${data.period_label}.`}
                        className="mb-4"
                    >
                        {data.timeline.length === 0 ? (
                            <p className="py-6 text-sm text-ink-subtle">
                                No movements were recorded in this period.
                            </p>
                        ) : (
                            <FlowChart buckets={data.timeline} />
                        )}
                    </Card>

                    <Card bodyClassName="p-0">
                        <div className="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2.5">
                            <Tabs items={ENTITY_TABS} value={entity} onChange={(id) => setEntity(id)} />
                            <label className="relative ml-auto flex min-w-0 flex-1 items-center sm:max-w-xs">
                                <Search className="absolute left-2 size-4 text-ink-subtle" aria-hidden="true" />
                                <span className="visually-hidden">Search the breakdown</span>
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Name or SKU…"
                                    className="pl-8"
                                />
                            </label>
                        </div>

                        <div className="px-3 py-1">
                            <DataTable
                                columns={columns}
                                rows={filteredBreakdown}
                                rowKey={(row) => `${row.entity_type}-${row.entity_id}`}
                                empty={
                                    <EmptyState
                                        title="Nothing in this period"
                                        hint="Try a wider window, or clear the filter."
                                        icon={<LineChart className="size-5" aria-hidden="true" />}
                                        action={
                                            <Button
                                                variant="secondary"
                                                onClick={() => {
                                                    setSearch('');
                                                    setEntity('all');
                                                }}
                                            >
                                                Clear filters
                                            </Button>
                                        }
                                    />
                                }
                            />
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line px-3 py-2 text-xs text-ink-subtle">
                            <span>
                                {filteredBreakdown.length} of {data.breakdown.length} entities
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <ArrowLeftRight className="size-3" aria-hidden="true" />
                                <span className="sp-code">{number(shownEvents)}</span> events
                            </span>
                        </div>
                    </Card>

                    <p className="mt-3 text-xs text-ink-subtle">
                        The ledger is append-only. A movement is never edited in place: a correction is a new row
                        pointing at the row it fixes, so the history here is never rewritten.
                    </p>
                </>
            )}
        </>
    );
}
