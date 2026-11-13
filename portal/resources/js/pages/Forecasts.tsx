import React, { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, errMsg } from '../lib/api';
import { Badge, Button, Card, StatCard, Spinner, Table, EmptyState } from '../components/ui';

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

export default function Forecasts() {
    const queryClient = useQueryClient();
    const [activeTab, setActiveTab] = useState<'forecasts' | 'risks' | 'recommendations' | 'engine'>('forecasts');
    const [computeRun, setComputeRun] = useState<ComputeRun | null>(null);
    const [computeError, setComputeError] = useState<string | null>(null);
    const [selected, setSelected] = useState<{ entity_type: string; entity_id: number; name: string } | null>(null);

    const { data: summary, isLoading: loadingSummary } = useQuery<SummaryData>({
        queryKey: ['forecast-summary'],
        queryFn: () => apiGet('/api/forecasts/summary'),
    });

    const { data: risksData, isLoading: loadingRisks } = useQuery<{ risks: RiskItem[] }>({
        queryKey: ['forecast-risks'],
        queryFn: () => apiGet('/api/forecasts/risks'),
    });

    const { data: recsData, isLoading: loadingRecs } = useQuery<{ recommendations: RecommendationItem[] }>({
        queryKey: ['forecast-recs'],
        queryFn: () => apiGet('/api/forecasts/recommendations?status=pending'),
    });

    const { data: forecastsData, isLoading: loadingForecasts } = useQuery<{ entities: ForecastEntity[]; count: number }>({
        queryKey: ['forecast-index'],
        queryFn: () => apiGet('/api/forecasts/index'),
    });

    const { data: pipeline } = useQuery<PipelineHealth>({
        queryKey: ['forecast-pipeline'],
        queryFn: () => apiGet('/api/forecasts/pipeline'),
        refetchInterval: 60000,
    });

    const { data: seriesData, isLoading: loadingSeries } = useQuery<{ forecasts: ForecastPoint[]; count: number }>({
        queryKey: ['forecast-series', selected?.entity_type, selected?.entity_id],
        queryFn: () =>
            apiGet(
                `/api/forecasts/demand?entity_type=${selected?.entity_type}&entity_id=${selected?.entity_id}`
            ),
        enabled: selected !== null,
    });

    const actionMutation = useMutation({
        mutationFn: ({ id, status }: { id: number; status: string }) =>
            apiPost(`/api/forecasts/recommendations/${id}/action`, { status }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['forecast-recs'] });
            queryClient.invalidateQueries({ queryKey: ['forecast-summary'] });
        },
    });

    const computeMutation = useMutation({
        mutationFn: () => apiPost<{ run_id: number; status: string }>('/api/forecasts/compute'),
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
                const latest = await apiGet<ComputeRun>(`/api/forecasts/compute/${computeRun.id}`);
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

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-gray-900">AI Forecasting & Inventory Intelligence</h1>
                    <p className="text-sm text-gray-500">
                        Predictive stockout alerts, velocity-based burn rates, and automated restock suggestions powered by PostgreSQL & Python ML.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {computeRun && (
                        <span className="text-xs text-gray-500">
                            {computeRun.status === 'queued'
                                ? 'Queued…'
                                : computeRun.status === 'running'
                                ? `Computing ${computeRun.progress_pct}%${computeRun.eta_seconds ? ` (~${Math.ceil(computeRun.eta_seconds / 60)} min left)` : ''}`
                                : computeRun.status}
                        </span>
                    )}
                    <Button
                        size="sm"
                        variant="primary"
                        onClick={() => computeMutation.mutate()}
                        disabled={
                            computeMutation.isPending ||
                            (computeRun !== null && ['queued', 'running'].includes(computeRun.status))
                        }
                    >
                        {computeRun && ['queued', 'running'].includes(computeRun.status)
                            ? 'Computing…'
                            : 'Compute now'}
                    </Button>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        Supabase ML Schema Active
                    </span>
                </div>
            </div>

            {computeError && (
                <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {computeError}
                </div>
            )}

            {pipeline && pipeline.status !== 'ok' && (
                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <span className="font-medium">Forecasts may be stale.</span>{' '}
                    {pipeline.problems.join(', ')}. Latest feature date:{' '}
                    {pipeline.last_feature_date ?? 'none'}.
                </div>
            )}

            {/* Stat Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Critical Stockout Risk"
                    value={loadingSummary ? '...' : summary?.counts.critical ?? 0}
                    sub="Runway &lt; 3 days"
                    tone="red"
                />
                <StatCard
                    label="Stockout Warnings"
                    value={loadingSummary ? '...' : summary?.counts.warning ?? 0}
                    sub="Runway 3–7 days"
                    tone="amber"
                />
                <StatCard
                    label="Healthy Inventory Runway"
                    value={loadingSummary ? '...' : summary?.counts.healthy ?? 0}
                    sub="Runway &gt; 7 days"
                    tone="green"
                />
                <StatCard
                    label="Urgent Restock Actions"
                    value={loadingSummary ? '...' : summary?.counts.urgent_recommendations ?? 0}
                    sub="High priority recommendations"
                    tone="purple"
                />
            </div>

            {/* Navigation Tabs */}
            <div className="border-b border-gray-200">
                <nav className="-mb-px flex space-x-6">
                    <button
                        onClick={() => setActiveTab('forecasts')}
                        className={`border-b-2 pb-3 text-sm font-medium transition ${
                            activeTab === 'forecasts'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                        }`}
                    >
                        Demand Forecasts ({forecastsData?.count ?? 0})
                    </button>
                    <button
                        onClick={() => setActiveTab('risks')}
                        className={`border-b-2 pb-3 text-sm font-medium transition ${
                            activeTab === 'risks'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                        }`}
                    >
                        Stockout Risk & Runway ({risksData?.risks.length ?? 0})
                    </button>
                    <button
                        onClick={() => setActiveTab('recommendations')}
                        className={`border-b-2 pb-3 text-sm font-medium transition ${
                            activeTab === 'recommendations'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                        }`}
                    >
                        Smart Restock Recommendations ({recsData?.recommendations.length ?? 0})
                    </button>
                    <button
                        onClick={() => setActiveTab('engine')}
                        className={`border-b-2 pb-3 text-sm font-medium transition ${
                            activeTab === 'engine'
                                ? 'border-indigo-600 text-indigo-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                        }`}
                    >
                        ML Python Pipeline Status
                    </button>
                </nav>
            </div>

            {/* Tab: Demand Forecasts */}
            {activeTab === 'forecasts' && (
                <div className="space-y-6">
                    <Card title="Computed Demand Forecast">
                        {loadingForecasts ? (
                            <div className="py-12 text-center"><Spinner className="mx-auto" /></div>
                        ) : (
                            <Table
                                head={['Entity', 'Type', 'Model', 'Horizon', 'Total Units', 'Daily Avg', 'Range']}
                                empty={
                                    !forecastsData?.entities.length ? (
                                        <EmptyState
                                            title="No forecasts computed yet"
                                            hint="Press Compute now to train the models and write a forecast horizon."
                                        />
                                    ) : undefined
                                }
                            >
                                {forecastsData?.entities.map((row) => (
                                    <tr
                                        key={`${row.entity_type}-${row.entity_id}-${row.model_version}`}
                                        className="cursor-pointer hover:bg-gray-50"
                                        onClick={() =>
                                            setSelected({
                                                entity_type: row.entity_type,
                                                entity_id: row.entity_id,
                                                name: row.entity_name,
                                            })
                                        }
                                    >
                                        <td className="px-4 py-2">
                                            <div className="font-medium text-gray-900">{row.entity_name}</div>
                                            {row.entity_sku && (
                                                <div className="text-xs text-gray-400">SKU: {row.entity_sku}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="capitalize text-xs text-gray-500">{row.entity_type}</span>
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="rounded bg-indigo-50 px-2 py-0.5 font-mono text-xs text-indigo-700">
                                                {row.model_version}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-xs text-gray-600">
                                            {row.forecast_days} days
                                            <div className="text-gray-400">
                                                {String(row.first_forecast_date).slice(0, 10)} →{' '}
                                                {String(row.last_forecast_date).slice(0, 10)}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2 font-medium text-gray-900">
                                            {Number(row.total_quantity).toFixed(1)} {row.entity_unit}
                                        </td>
                                        <td className="px-4 py-2 text-gray-700">
                                            {Number(row.daily_average).toFixed(2)} / day
                                        </td>
                                        <td className="px-4 py-2 text-xs text-gray-500">
                                            {row.lowest_bound != null && row.highest_bound != null
                                                ? `${Number(row.lowest_bound).toFixed(1)} – ${Number(row.highest_bound).toFixed(1)}`
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </Card>

                    {selected && (
                        <Card title={`Daily series — ${selected.name}`}>
                            {loadingSeries ? (
                                <div className="py-8 text-center"><Spinner className="mx-auto" /></div>
                            ) : (
                                <Table
                                    head={['Date', 'Predicted', 'Lower', 'Upper', 'Model']}
                                    empty={
                                        <EmptyState
                                            title="No forecast days for this entity"
                                            hint="The model produced an empty horizon for this entity."
                                        />
                                    }
                                >
                                    {seriesData?.forecasts.map((point) => (
                                        <tr key={point.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-2 text-gray-900">
                                                {String(point.forecast_date).slice(0, 10)}
                                            </td>
                                            <td className="px-4 py-2 font-medium text-gray-900">
                                                {Number(point.predicted_quantity).toFixed(2)}
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {point.lower_bound != null
                                                    ? Number(point.lower_bound).toFixed(2)
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {point.upper_bound != null
                                                    ? Number(point.upper_bound).toFixed(2)
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2 font-mono text-xs text-indigo-700">
                                                {point.model_version}
                                            </td>
                                        </tr>
                                    ))}
                                </Table>
                            )}
                        </Card>
                    )}
                </div>
            )}

            {/* Tab: Stockout Risks */}
            {activeTab === 'risks' && (
                <Card title="Inventory Depletion Runway">
                    {loadingRisks ? (
                        <div className="py-12 text-center"><Spinner className="mx-auto" /></div>
                    ) : (
                        <Table
                            head={['Entity', 'Type', 'Current Stock', 'Daily Burn Rate', 'Runway Remaining', 'Est. Stockout Date', 'Risk Level']}
                            empty={
                                !risksData?.risks.length ? (
                                    <EmptyState
                                        title="No active stockout risks detected"
                                        hint="As sales and movements record, the forecasting engine computes burn rates and runway estimates."
                                    />
                                ) : undefined
                            }
                        >
                            {risksData?.risks.map((risk) => (
                                <tr key={risk.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-2">
                                        <div className="font-medium text-gray-900">{risk.entity_name}</div>
                                        {risk.entity_sku && <div className="text-xs text-gray-400">SKU: {risk.entity_sku}</div>}
                                    </td>
                                    <td className="px-4 py-2">
                                        <span className="capitalize text-xs text-gray-500">{risk.entity_type}</span>
                                    </td>
                                    <td className="px-4 py-2">{risk.current_stock} {risk.entity_unit}</td>
                                    <td className="px-4 py-2">{risk.daily_burn_rate} / day</td>
                                    <td className="px-4 py-2">
                                        {risk.days_until_stockout != null
                                            ? `${risk.days_until_stockout} days`
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2">{risk.estimated_stockout_date ?? 'Indefinite'}</td>
                                    <td className="px-4 py-2">
                                        <Badge
                                            tone={
                                                risk.risk_level === 'critical'
                                                    ? 'red'
                                                    : risk.risk_level === 'warning'
                                                    ? 'amber'
                                                    : 'green'
                                            }
                                        >
                                            {risk.risk_level.toUpperCase()}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </Card>
            )}

            {/* Tab: Restock Recommendations */}
            {activeTab === 'recommendations' && (
                <Card title="Prescriptive Restock Recommendations">
                    {loadingRecs ? (
                        <div className="py-12 text-center"><Spinner className="mx-auto" /></div>
                    ) : !recsData?.recommendations.length ? (
                        <EmptyState
                            title="All inventory recommendations are up to date"
                            hint="As the Python engine processes historical lead times and sales trends, suggestions will appear here."
                        />
                    ) : (
                        <div className="space-y-4">
                            {recsData.recommendations.map((rec) => (
                                <div
                                    key={rec.id}
                                    className="flex flex-col justify-between gap-4 rounded-lg border border-gray-200 p-4 sm:flex-row sm:items-center"
                                >
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <h4 className="font-semibold text-gray-900">{rec.entity_name}</h4>
                                            <Badge tone={rec.urgency === 'high' ? 'red' : rec.urgency === 'medium' ? 'amber' : 'blue'}>
                                                {rec.urgency.toUpperCase()} URGENCY
                                            </Badge>
                                            <span className="text-xs text-gray-400 capitalize">({rec.entity_type})</span>
                                        </div>
                                        <p className="text-sm text-gray-600">
                                            Recommended Batch: <span className="font-bold text-indigo-700">{rec.recommended_reorder_qty} {rec.entity_unit}</span>
                                        </p>
                                        {rec.reasoning && (
                                            <p className="text-xs italic text-gray-500">“{rec.reasoning}”</p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => actionMutation.mutate({ id: rec.id, status: 'dismissed' })}
                                        >
                                            Dismiss
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="primary"
                                            onClick={() => actionMutation.mutate({ id: rec.id, status: 'accepted' })}
                                        >
                                            Approve Purchase
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            )}

            {/* Tab: Python ML Architecture Status */}
            {activeTab === 'engine' && (
                <div className="space-y-6">
                    <Card title="Python ML Forecasting Service Contract">
                        <div className="space-y-4 text-sm text-gray-600">
                            <p>
                                The StockPilot PostgreSQL database is equipped with dedicated ML tables. A standalone Python forecasting server or cron batch process connects directly to Supabase to read sales velocity and compute forecasts:
                            </p>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                                    <div className="font-mono text-xs font-bold text-indigo-600">sp_demand_forecasts</div>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Time-series daily/weekly consumption volumes with 95% confidence bounds.
                                    </p>
                                </div>
                                <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                                    <div className="font-mono text-xs font-bold text-indigo-600">sp_stockout_risks</div>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Dynamic daily burn rate calculations and stockout runway horizons.
                                    </p>
                                </div>
                                <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                                    <div className="font-mono text-xs font-bold text-indigo-600">sp_restock_recommendations</div>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Economic Order Quantity (EOQ) and safety stock replenishment actions.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
            )}
        </div>
    );
}
