import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, PackageCheck, ShieldAlert, Sparkles, X } from 'lucide-react';
import { apiGet, apiPost, errMsg } from '../lib/api';
import { date, dateTime, number } from '../lib/format';
import { riskTone, urgencyTone } from '../lib/status';
import {
    Badge,
    Button,
    Card,
    EmptyState,
    Flash,
    PageHeader,
    Progress,
    Spinner,
    StatCard,
    Tabs,
    Tooltip,
} from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';

interface SummaryData {
    counts: {
        critical: number;
        warning: number;
        healthy: number;
        urgent_recommendations: number;
    };
    top_risks: any[];
    last_updated: string | null;
}

interface RiskItem {
    id: number;
    entity_type: 'item' | 'product';
    entity_id: number;
    entity_name: string;
    entity_sku?: string;
    entity_unit?: string;
    current_stock: number;
    daily_burn_rate: number;
    days_until_stockout: number | null;
    risk_level: 'critical' | 'warning' | 'healthy';
    estimated_stockout_date: string | null;
    model_version?: string;
    calculated_at: string;
}

interface RecommendationItem {
    id: number;
    entity_type: 'item' | 'product';
    entity_id: number;
    entity_name: string;
    entity_sku?: string;
    entity_unit?: string;
    recommended_reorder_qty: number;
    urgency: 'high' | 'medium' | 'low';
    reasoning?: string;
    status: 'pending' | 'accepted' | 'dismissed';
    created_at: string;
}

interface ComputeRun {
    id: number;
    status: 'queued' | 'running' | 'succeeded' | 'failed';
    trigger_source?: string;
    progress_pct: number;
    done_entities: number;
    total_entities: number;
    eta_seconds?: number | null;
    error?: string | null;
    run_id?: string | null;
    started_at?: string | null;
    finished_at?: string | null;
}

interface ForecastEntity {
    entity_type: 'item' | 'product';
    entity_id: number;
    entity_name: string;
    entity_sku?: string | null;
    entity_unit?: string;
    model_version: string;
    forecast_days: number;
    total_quantity: string | number;
    daily_average: string | number;
    first_forecast_date: string;
    last_forecast_date: string;
    lowest_bound?: string | number | null;
    highest_bound?: string | number | null;
}

interface ForecastPoint {
    id: number;
    forecast_date: string;
    predicted_quantity: number;
    lower_bound: number | null;
    upper_bound: number | null;
    model_version: string;
}

interface PipelineHealth {
    status: 'ok' | 'stale';
    problems: string[];
    last_feature_date: string | null;
    feature_lag_days: number | null;
    forecast_rows: number;
    past_forecast_rows: number;
    first_forecast_date: string | null;
    last_forecast_date: string | null;
    forecast_age_hours: number | null;
}

type TabId = 'risks' | 'restock' | 'forecast' | 'engine';

const isRunning = (run: ComputeRun | null) => run !== null && ['queued', 'running'].includes(run.status);

const forecastRowKey = (entity: ForecastEntity) =>
    `${entity.entity_type}-${entity.entity_id}-${entity.model_version}`;

export default function Forecasts() {
    const queryClient = useQueryClient();
    const [activeTab, setActiveTab] = useState<TabId>('risks');
    const [computeRun, setComputeRun] = useState<ComputeRun | null>(null);
    const [computeError, setComputeError] = useState<string | null>(null);
    const [selected, setSelected] = useState<{ entity_type: string; entity_id: number; name: string } | null>(null);
    const [decided, setDecided] = useState<Record<number, 'accepted' | 'dismissed'>>({});

    const { data: summary, isLoading: loadingSummary } = useQuery<SummaryData>({
        queryKey: ['forecast-summary'],
        queryFn: () => apiGet('/forecasts/summary'),
    });

    const { data: risksData, isLoading: loadingRisks } = useQuery<{ risks: RiskItem[] }>({
        queryKey: ['forecast-risks'],
        queryFn: () => apiGet('/forecasts/risks'),
    });

    const { data: recsData, isLoading: loadingRecs } = useQuery<{ recommendations: RecommendationItem[] }>({
        queryKey: ['forecast-recs'],
        queryFn: () => apiGet('/forecasts/recommendations?status=pending'),
    });

    const { data: forecastsData, isLoading: loadingForecasts } = useQuery<{ entities: ForecastEntity[]; count: number }>({
        queryKey: ['forecast-index'],
        queryFn: () => apiGet('/forecasts/index'),
    });

    const { data: pipeline } = useQuery<PipelineHealth>({
        queryKey: ['forecast-pipeline'],
        queryFn: () => apiGet('/forecasts/pipeline'),
        refetchInterval: 60000,
    });

    const { data: seriesData, isLoading: loadingSeries } = useQuery<{ forecasts: ForecastPoint[]; count: number }>({
        queryKey: ['forecast-series', selected?.entity_type, selected?.entity_id],
        queryFn: () =>
            apiGet(
                `/forecasts/demand?entity_type=${selected?.entity_type}&entity_id=${selected?.entity_id}`
            ),
        enabled: selected !== null,
    });

    const actionMutation = useMutation({
        mutationFn: ({ id, status }: { id: number; status: string }) =>
            apiPost(`/forecasts/recommendations/${id}/action`, { status }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['forecast-recs'] });
            queryClient.invalidateQueries({ queryKey: ['forecast-summary'] });
        },
    });

    const computeMutation = useMutation({
        mutationFn: () => apiPost<{ run_id: number; status: string }>('/forecasts/compute'),
        onSuccess: (result) => {
            setComputeError(null);
            setComputeRun({
                id: result.run_id,
                status: 'queued',
                progress_pct: 0,
                done_entities: 0,
                total_entities: 0,
            });
        },
        onError: (error) => setComputeError(errMsg(error)),
    });

    useEffect(() => {
        if (!computeRun || !['queued', 'running'].includes(computeRun.status)) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            try {
                const latest = await apiGet<ComputeRun>(`/forecasts/compute/${computeRun.id}`);
                if (cancelled) return;

                setComputeRun(latest);

                if (latest.status === 'succeeded' || latest.status === 'failed') {
                    queryClient.invalidateQueries({ queryKey: ['forecast-summary'] });
                    queryClient.invalidateQueries({ queryKey: ['forecast-risks'] });
                    queryClient.invalidateQueries({ queryKey: ['forecast-recs'] });

                    if (latest.status === 'failed') {
                        setComputeError(latest.error ?? 'Forecast computation failed.');
                    }
                }
            } catch (error) {
                if (!cancelled) setComputeError(errMsg(error));
            }
        };

        void poll();
        const timer = window.setInterval(poll, 2000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [computeRun?.id, computeRun?.status, queryClient]);

    const decide = (id: number, status: 'accepted' | 'dismissed') => {
        setDecided((previous) => ({ ...previous, [id]: status }));
        actionMutation.mutateAsync({ id, status }).catch(() => {
            setDecided((previous) => {
                const next = { ...previous };
                delete next[id];
                return next;
            });
        });
    };

    const loading = (
        <div className="grid place-items-center py-12">
            <Spinner className="size-5 text-ink-subtle" />
        </div>
    );

    const activeRow = selected
        ? (forecastsData?.entities ?? []).find(
              (entity) =>
                  entity.entity_type === selected.entity_type && entity.entity_id === selected.entity_id
          )
        : undefined;

    const activeForecastKey = activeRow ? forecastRowKey(activeRow) : null;

    const riskColumns: Column<RiskItem>[] = [
        {
            head: 'Entity',
            key: true,
            cell: (r) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">{r.entity_name}</span>
                    <span className="sp-code">{r.entity_sku ?? r.entity_type}</span>
                </span>
            ),
        },
        {
            head: 'Days to stockout',
            cell: (r) =>
                r.days_until_stockout == null ? (
                    <span className="text-xs text-ink-subtle">no burn recorded</span>
                ) : (
                    <span className="flex items-center gap-1.5">
                        <span
                            className={
                                r.risk_level === 'critical'
                                    ? 'sp-figure text-xl text-danger'
                                    : 'sp-figure text-xl text-ink'
                            }
                        >
                            {number(r.days_until_stockout, 1)}
                        </span>
                        <span className="text-xs text-ink-subtle">
                            {r.days_until_stockout === 1 ? 'day' : 'days'}
                        </span>
                    </span>
                ),
        },
        {
            head: 'Daily burn',
            align: 'end',
            cell: (r) => (
                <span className="sp-code">
                    {number(r.daily_burn_rate, 2)}
                    {r.entity_unit ? ` ${r.entity_unit}` : ''}/day
                </span>
            ),
        },
        {
            head: 'Current stock',
            align: 'end',
            code: true,
            cell: (r) => (
                <span className="flex items-baseline justify-end gap-1">
                    {number(r.current_stock, 2)}
                    {r.entity_unit && <span className="text-xs text-ink-subtle">{r.entity_unit}</span>}
                </span>
            ),
        },
        {
            head: 'Est. stockout',
            cell: (r) => (
                <span className="sp-code text-xs">
                    {r.estimated_stockout_date ? date(r.estimated_stockout_date) : 'no date claimed'}
                </span>
            ),
        },
        {
            head: 'Model',
            cell: (r) => <span className="sp-code text-xs">{r.model_version ?? '—'}</span>,
            hide: 'md',
        },
        {
            head: 'Risk',
            align: 'end',
            cell: (r) => (
                <Tooltip label={`calculated ${dateTime(r.calculated_at)}`}>
                    <Badge tone={riskTone(r.risk_level)}>{r.risk_level}</Badge>
                </Tooltip>
            ),
        },
    ];

    const restockColumns: Column<RecommendationItem>[] = [
        {
            head: 'Order',
            key: true,
            cell: (r) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">
                        <span className="sp-figure text-sm text-brand-700">{number(r.recommended_reorder_qty, 2)}</span>
                        <span className="text-ink-subtle"> × </span>
                        {r.entity_name}
                    </span>
                    <span className="sp-code">{r.entity_sku ?? r.entity_type}</span>
                </span>
            ),
        },
        {
            head: 'Urgency',
            cell: (r) => <Badge tone={urgencyTone(r.urgency)}>{r.urgency}</Badge>,
        },
        {
            head: 'Why',
            cell: (r) => <span className="text-xs text-ink-muted">{r.reasoning ?? 'No reasoning recorded.'}</span>,
            hide: 'lg',
        },
        {
            head: 'Raised',
            cell: (r) => <span className="sp-code text-xs">{date(r.created_at)}</span>,
            hide: 'md',
        },
        {
            head: '',
            align: 'end',
            actions: true,
            cell: (r) => {
                const settled: 'accepted' | 'dismissed' | undefined =
                    decided[r.id] ?? (r.status === 'pending' ? undefined : r.status);

                if (settled) {
                    return (
                        <Badge tone={settled === 'accepted' ? 'green' : 'gray'}>{settled}</Badge>
                    );
                }

                return (
                    <span className="flex items-center justify-end gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={actionMutation.isPending}
                            aria-label={`Dismiss the recommendation for ${r.entity_name}`}
                            onClick={() => decide(r.id, 'dismissed')}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={actionMutation.isPending}
                            onClick={() => decide(r.id, 'accepted')}
                        >
                            <Check className="size-3.5" aria-hidden="true" />
                            Approve
                        </Button>
                    </span>
                );
            },
        },
    ];

    const forecastColumns: Column<ForecastEntity>[] = [
        {
            head: 'Entity',
            key: true,
            cell: (r) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">{r.entity_name}</span>
                    <span className="sp-code">{r.entity_sku ?? r.entity_type}</span>
                </span>
            ),
        },
        {
            head: 'Type',
            cell: (r) => (
                <Badge tone={r.entity_type === 'item' ? 'purple' : 'blue'}>{r.entity_type}</Badge>
            ),
            hide: 'lg',
        },
        {
            head: 'Model',
            cell: (r) => <span className="sp-code text-xs">{r.model_version}</span>,
            hide: 'md',
        },
        {
            head: 'Horizon',
            cell: (r) => (
                <span className="flex min-w-0 flex-col">
                    <span className="text-sm text-ink">{r.forecast_days} days</span>
                    <span className="sp-code text-xs">
                        {date(r.first_forecast_date)} → {date(r.last_forecast_date)}
                    </span>
                </span>
            ),
        },
        {
            head: 'Total',
            align: 'end',
            cell: (r) => (
                <span className="flex items-baseline justify-end gap-1">
                    <span className="sp-figure text-sm">{number(r.total_quantity, 1)}</span>
                    {r.entity_unit && <span className="text-xs text-ink-subtle">{r.entity_unit}</span>}
                </span>
            ),
        },
        {
            head: 'Daily avg',
            align: 'end',
            cell: (r) => <span className="sp-code">{number(r.daily_average, 2)}/day</span>,
        },
        {
            head: 'Bounds',
            align: 'end',
            cell: (r) => (
                <span className="sp-code text-xs">
                    {r.lowest_bound != null && r.highest_bound != null
                        ? `${number(r.lowest_bound, 1)} – ${number(r.highest_bound, 1)}`
                        : '—'}
                </span>
            ),
            hide: 'lg',
        },
    ];

    const seriesColumns: Column<ForecastPoint>[] = [
        {
            head: 'Date',
            key: true,
            cell: (p) => <span className="sp-code text-xs">{date(p.forecast_date)}</span>,
        },
        {
            head: 'Predicted',
            align: 'end',
            cell: (p) => (
                <span className="sp-figure text-sm text-ink">{number(p.predicted_quantity, 2)}</span>
            ),
        },
        {
            head: 'Lower',
            align: 'end',
            cell: (p) => (
                <span className="sp-code">{p.lower_bound != null ? number(p.lower_bound, 2) : '—'}</span>
            ),
        },
        {
            head: 'Upper',
            align: 'end',
            cell: (p) => (
                <span className="sp-code">{p.upper_bound != null ? number(p.upper_bound, 2) : '—'}</span>
            ),
        },
        {
            head: 'Model',
            cell: (p) => <span className="sp-code text-xs">{p.model_version}</span>,
            hide: 'md',
        },
    ];

    return (
        <>
            <PageHeader
                title="Forecasting"
                subtitle="Stockout runway, burn rate and restock decisions computed from your own movement ledger by the PostgreSQL and Python ML pipeline. No external model, no data leaves the workspace."
                actions={
                    <>
                        {isRunning(computeRun) && (
                            <span className="sp-code text-xs">
                                {computeRun?.status === 'queued'
                                    ? 'queued'
                                    : `run ${computeRun?.id} · ${computeRun?.progress_pct}%`}
                            </span>
                        )}
                        <Badge
                            dot
                            tone={pipeline ? (pipeline.status === 'ok' ? 'green' : 'amber') : 'gray'}
                        >
                            {pipeline ? (pipeline.status === 'ok' ? 'pipeline current' : 'pipeline stale') : 'pipeline unknown'}
                        </Badge>
                        <Button
                            variant="secondary"
                            loading={computeMutation.isPending}
                            disabled={isRunning(computeRun)}
                            onClick={() => computeMutation.mutate()}
                        >
                            <Sparkles className="size-4" aria-hidden="true" />
                            {isRunning(computeRun) ? 'Computing…' : 'Recompute'}
                        </Button>
                    </>
                }
            />

            {isRunning(computeRun) && computeRun && (
                <div className="mb-4 border border-line bg-surface px-3 py-2.5">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-subtle">
                        <span>
                            {computeRun.status === 'queued'
                                ? 'Queued — waiting for a worker to pick up the run.'
                                : `Computing ${computeRun.done_entities} of ${computeRun.total_entities} entities` +
                                  (computeRun.eta_seconds
                                      ? ` · about ${Math.ceil(computeRun.eta_seconds / 60)} min left`
                                      : '')}
                        </span>
                        <span className="sp-code">{computeRun.progress_pct}%</span>
                    </div>
                    <div className="mt-2">
                        <Progress value={computeRun.progress_pct} tone="blue" />
                    </div>
                </div>
            )}

            <Flash message={computeError} tone="red" />

            {pipeline && pipeline.status !== 'ok' && (
                <Flash
                    tone="info"
                    message={`Forecasts may be stale: ${pipeline.problems.join(', ')}. Latest feature date: ${
                        pipeline.last_feature_date ?? 'none'
                    }.`}
                />
            )}

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Critical"
                    value={loadingSummary ? '—' : number(summary?.counts.critical)}
                    sub="runway under 3 days"
                    tone="red"
                />
                <StatCard
                    label="Warning"
                    value={loadingSummary ? '—' : number(summary?.counts.warning)}
                    sub="runway 3 to 7 days"
                    tone="amber"
                />
                <StatCard
                    label="Healthy runway"
                    value={loadingSummary ? '—' : number(summary?.counts.healthy)}
                    sub="stored risk assessments in the healthy band"
                    tone="green"
                />
                <StatCard
                    label="Open restocks"
                    value={loadingSummary ? '—' : number(summary?.counts.urgent_recommendations)}
                    sub="high urgency, pending a decision"
                    tone="blue"
                />
            </div>

            <Card bodyClassName="p-0">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-3 py-2.5">
                    <Tabs
                        value={activeTab}
                        onChange={setActiveTab}
                        items={[
                            { id: 'risks' as const, label: 'Risks', count: risksData?.risks.length ?? 0 },
                            { id: 'restock' as const, label: 'Restock', count: recsData?.recommendations.length ?? 0 },
                            { id: 'forecast' as const, label: 'Forecast', count: forecastsData?.count ?? 0 },
                            { id: 'engine' as const, label: 'Engine' },
                        ]}
                    />
                    <span className="sp-code text-xs">
                        {summary?.last_updated ? `computed ${dateTime(summary.last_updated)}` : 'never computed'}
                    </span>
                </div>

                <div className="px-3 py-1">
                    {activeTab === 'risks' &&
                        (loadingRisks ? (
                            loading
                        ) : (
                            <DataTable
                                columns={riskColumns}
                                rows={risksData?.risks ?? []}
                                rowKey={(r) => r.id}
                                empty={
                                    <EmptyState
                                        title="No stockout risks stored"
                                        hint="A risk is written once the engine has movement history for a component — press Recompute to queue a run."
                                        icon={<ShieldAlert className="size-5" aria-hidden="true" />}
                                    />
                                }
                            />
                        ))}

                    {activeTab === 'restock' && (
                        <>
                            {actionMutation.isError && (
                                <Flash message={errMsg(actionMutation.error)} tone="red" />
                            )}
                            {loadingRecs ? (
                                loading
                            ) : (
                                <DataTable
                                    columns={restockColumns}
                                    rows={recsData?.recommendations ?? []}
                                    rowKey={(r) => r.id}
                                    empty={
                                        <EmptyState
                                            title="Nothing needs restocking"
                                            hint="A recommendation appears when a component's runway drops under its reorder point — press Recompute to recompute the model."
                                            icon={<PackageCheck className="size-5" aria-hidden="true" />}
                                        />
                                    }
                                />
                            )}
                        </>
                    )}

                    {activeTab === 'forecast' && (
                        <>
                            {loadingForecasts ? (
                                loading
                            ) : (
                                <DataTable
                                    columns={forecastColumns}
                                    rows={forecastsData?.entities ?? []}
                                    rowKey={forecastRowKey}
                                    activeKey={activeForecastKey}
                                    onRowClick={(row) =>
                                        setSelected({
                                            entity_type: row.entity_type,
                                            entity_id: row.entity_id,
                                            name: row.entity_name,
                                        })
                                    }
                                    empty={
                                        <EmptyState
                                            title="No forecast horizon stored"
                                            hint="Press Recompute to queue a run; the model writes one daily horizon per component and product."
                                            icon={<Sparkles className="size-5" aria-hidden="true" />}
                                        />
                                    }
                                />
                            )}

                            {selected && (
                                <div className="mt-3 border border-line bg-surface-sunken p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="text-xs font-semibold text-ink">
                                                Daily series — {selected.name}
                                            </div>
                                            <div className="sp-code mt-0.5">
                                                {seriesData?.count ?? 0} forecast days, {selected.entity_type}
                                            </div>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setSelected(null)}
                                            aria-label="Hide the daily series"
                                        >
                                            <X className="size-3.5" aria-hidden="true" />
                                            Hide
                                        </Button>
                                    </div>
                                    <div className="mt-2">
                                        {loadingSeries ? (
                                            <div className="grid place-items-center py-6">
                                                <Spinner className="size-5 text-ink-subtle" />
                                            </div>
                                        ) : (
                                            <DataTable
                                                columns={seriesColumns}
                                                rows={seriesData?.forecasts ?? []}
                                                rowKey={(p) => p.id}
                                                empty={
                                                    <EmptyState
                                                        title="No forecast days for this entity"
                                                        hint="The stored horizon is empty. A completed recompute writes one row per day per entity, so run it again or pick another row."
                                                        icon={<Sparkles className="size-5" aria-hidden="true" />}
                                                    />
                                                }
                                            />
                                        )}
                                    </div>
                                </div>
                            )}
                        </>
                    )}

                    {activeTab === 'engine' && (
                        <div className="grid gap-3 py-3 sm:grid-cols-2">
                            <dl className="border border-line p-3">
                                <dt className="text-xs text-ink-subtle">Burn rate</dt>
                                <dd className="mt-1 text-sm text-ink">
                                    Mean daily consumption per component, taken from confirmed deductions in
                                    your movement ledger over the window the job loads. Stored on the risk row
                                    as daily_burn_rate.
                                </dd>
                            </dl>
                            <dl className="border border-line p-3">
                                <dt className="text-xs text-ink-subtle">Runway</dt>
                                <dd className="mt-1 text-sm text-ink">
                                    Current stock divided by burn rate, stored as days_until_stockout. When no
                                    burn has been observed the value is null, so no stockout date is claimed.
                                </dd>
                            </dl>
                            <dl className="border border-line p-3">
                                <dt className="text-xs text-ink-subtle">Reorder point</dt>
                                <dd className="mt-1 text-sm text-ink">
                                    Burn rate multiplied by supplier lead time, so a restock lands before the
                                    shelf empties. Crossing it raises a recommendation with a reorder quantity
                                    and a reasoning line.
                                </dd>
                            </dl>
                            <dl className="border border-line p-3">
                                <dt className="text-xs text-ink-subtle">Data source</dt>
                                <dd className="mt-1 text-sm text-ink">
                                    Your own database only: daily features, demand forecasts and stockout risks
                                    written by the Python job and read back through this API. Nothing is sent
                                    anywhere for analysis.
                                </dd>
                            </dl>

                            {pipeline && (
                                <div className="border border-line bg-surface-sunken p-3 sm:col-span-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge dot tone={pipeline.status === 'ok' ? 'green' : 'amber'}>
                                            {pipeline.status === 'ok' ? 'current' : 'stale'}
                                        </Badge>
                                        <span className="sp-code text-xs">
                                            features {date(pipeline.last_feature_date)}
                                        </span>
                                        {pipeline.feature_lag_days != null && (
                                            <span className="sp-code text-xs">
                                                lag {number(pipeline.feature_lag_days, 0)} d
                                            </span>
                                        )}
                                        <span className="sp-code text-xs">
                                            {number(pipeline.forecast_rows, 0)} forecast rows
                                        </span>
                                        <span className="sp-code text-xs">
                                            {number(pipeline.past_forecast_rows, 0)} past rows
                                        </span>
                                        {pipeline.forecast_age_hours != null && (
                                            <span className="sp-code text-xs">
                                                written {number(pipeline.forecast_age_hours, 1)} h ago
                                            </span>
                                        )}
                                        <span className="sp-code text-xs">
                                            horizon {date(pipeline.first_forecast_date)} → {date(pipeline.last_forecast_date)}
                                        </span>
                                    </div>
                                    {pipeline.problems.length > 0 && (
                                        <div className="mt-1.5 text-xs text-ink-muted">
                                            {pipeline.problems.join(' · ')}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </Card>
        </>
    );
}
