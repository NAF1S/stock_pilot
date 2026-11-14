import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Save, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { apiGet, apiPost, apiPut, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Button, Card, Flash, Input, PageHeader, Select, Switch } from '../components/ui';

interface SettingsData {
    organization: { name: string; currency: string; timezone: string; low_stock_email: string | null };
    settings: Record<string, unknown>;
}

type StatusMap = { reserve: string; confirm: string; release: string; return: string };

const DEFAULT_STATUS_MAP: StatusMap = {
    reserve: 'pending, pending-payment, on-hold, processing, confirmed',
    confirm: 'completed',
    release: 'cancelled, failed, trash',
    return: 'refunded, returned, returned-with-charge',
};

const STATUS_MAP_LABELS: { key: keyof StatusMap; label: string; hint: string }[] = [
    { key: 'reserve', label: 'Reserve stock', hint: 'Lock components when an order reaches these statuses. Reserved units are held against the order but stay on hand.' },
    { key: 'confirm', label: 'Deduct stock', hint: 'Permanently deduct components on these statuses (e.g. completed). This is the only action that lowers stock on hand.' },
    { key: 'release', label: 'Release reservation', hint: 'Free reserved stock back on these statuses (e.g. cancelled). Nothing leaves the shelf.' },
    { key: 'return', label: 'Add stock back', hint: 'Restock components on these statuses (e.g. refunded). Returned units are added back to stock on hand.' },
];

const CURRENCIES = ['BDT', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'INR', 'AED', 'SAR', 'SGD'];

const TIMEZONES = [
    'Asia/Dhaka',
    'Asia/Kolkata',
    'Asia/Karachi',
    'Asia/Dubai',
    'Asia/Singapore',
    'Europe/London',
    'Europe/Berlin',
    'America/New_York',
    'Australia/Sydney',
    'UTC',
];

const SECTIONS = [
    { id: 'company', label: 'Company' },
    { id: 'status-map', label: 'Status actions' },
    { id: 'alerts', label: 'Low-stock alerts' },
];

export default function Settings() {
    const qc = useQueryClient();
    const { refresh } = useAuth();
    const [form, setForm] = useState({ name: '', currency: '', timezone: '', low_stock_email: '' });
    const [cooldown, setCooldown] = useState('24');
    const [allowNegative, setAllowNegative] = useState(false);
    const [alertsOn, setAlertsOn] = useState(true);
    const [statusMap, setStatusMap] = useState<StatusMap>(DEFAULT_STATUS_MAP);
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['settings'],
        queryFn: () => apiGet<SettingsData>('/settings'),
    });

    useEffect(() => {
        if (!data) return;

        setForm({
            name: data.organization.name,
            currency: data.organization.currency,
            timezone: data.organization.timezone,
            low_stock_email: data.organization.low_stock_email ?? '',
        });
        setCooldown(String((data.settings.warning_cooldown_hours as number) ?? 24));
        setAllowNegative(Boolean(data.settings.allow_negative_stock));
        setAlertsOn(Boolean(data.organization.low_stock_email));

        if (data.settings.status_map) {
            const m = data.settings.status_map as Record<string, string[]>;
            setStatusMap({
                reserve: (m.reserve ?? []).join(', '),
                confirm: (m.confirm ?? []).join(', '),
                release: (m.release ?? []).join(', '),
                return: (m.return ?? []).join(', '),
            });
        }
    }, [data]);

    const save = useMutation({
        mutationFn: () =>
            apiPut('/settings', {
                ...form,
                low_stock_email: form.low_stock_email || null,
                warning_cooldown_hours: Number(cooldown),
                allow_negative_stock: allowNegative,
                status_map: Object.fromEntries(
                    Object.entries(statusMap).map(([k, v]) => [
                        k,
                        v.split(',').map((s) => s.trim().toLowerCase()).filter(Boolean),
                    ]),
                ),
            }),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['settings'] });
            await refresh();
            setError(null);
            setFlash('Settings saved.');
        },
        onError: (e) => {
            setFlash(null);
            setError(errMsg(e));
        },
    });

    const testMail = useMutation({
        mutationFn: () => apiPost<{ sent_to: string }>('/settings/test-email'),
        onSuccess: (r) => {
            setError(null);
            setFlash(`Test email sent to ${r.sent_to}. Check your inbox / spam.`);
        },
        onError: (e) => {
            setFlash(null);
            setError(errMsg(e));
        },
    });

    if (isLoading || !data) return null;

    const currencyOptions = CURRENCIES.includes(form.currency)
        ? CURRENCIES
        : [form.currency, ...CURRENCIES].filter(Boolean);

    const timezoneOptions = TIMEZONES.includes(form.timezone)
        ? TIMEZONES
        : [form.timezone, ...TIMEZONES].filter(Boolean);

    return (
        <div>
            <PageHeader
                title="Settings"
                subtitle="Company details, how each order status moves stock, and when to warn you."
                actions={
                    <Button loading={save.isPending} onClick={() => save.mutate()}>
                        <Save className="size-4" aria-hidden="true" />
                        Save changes
                    </Button>
                }
            />

            <Flash message={flash} />
            <Flash message={error} tone="red" />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,9fr)]">
                <nav aria-label="Settings sections" className="hidden lg:block">
                    <ul className="sticky top-20 space-y-0.5 border-l border-line">
                        {SECTIONS.map((section) => (
                            <li key={section.id}>
                                <a
                                    href={`#${section.id}`}
                                    className="-ml-px block border-l-2 border-transparent px-3 py-1.5 text-sm text-ink-muted hover:border-l-brand-600 hover:bg-surface hover:text-ink"
                                >
                                    {section.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="space-y-4">
                    <Card title="Company" description="Shown on exports and used for money formatting.">
                        <div id="company" className="grid gap-3 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <Input
                                    label="Company name"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                />
                            </div>
                            <Select
                                label="Currency"
                                value={form.currency}
                                onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })}
                            >
                                {currencyOptions.map((code) => (
                                    <option key={code} value={code}>{code}</option>
                                ))}
                            </Select>
                            <Select
                                label="Timezone"
                                value={form.timezone}
                                onChange={(e) => setForm({ ...form, timezone: e.target.value })}
                            >
                                {timezoneOptions.map((zone) => (
                                    <option key={zone} value={zone}>{zone}</option>
                                ))}
                            </Select>
                        </div>
                    </Card>

                    <Card
                        title="Order status to inventory action"
                        description="Map your WooCommerce statuses to what happens to inventory. Statuses are comma-separated; add any custom status your store uses (e.g. confirmed)."
                    >
                        <div id="status-map" className="divide-y divide-line">
                            {STATUS_MAP_LABELS.map(({ key, label, hint }) => (
                                <div
                                    key={key}
                                    className="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-start"
                                >
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium text-ink">{label}</div>
                                        <p className="mt-0.5 text-xs text-ink-muted">{hint}</p>
                                    </div>
                                    <Input
                                        aria-label={label}
                                        className="sp-code"
                                        value={statusMap[key]}
                                        onChange={(e) => setStatusMap({ ...statusMap, [key]: e.target.value })}
                                    />
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card title="Low-stock warnings" description="When a component falls to or below its threshold.">
                        <div id="alerts" className="space-y-4">
                            <Switch
                                checked={alertsOn}
                                onChange={(next) => {
                                    setAlertsOn(next);
                                    if (!next) setForm({ ...form, low_stock_email: '' });
                                }}
                                label="Email me when a component drops below its threshold"
                                description="One message per component per cooldown window. Warnings clear when stock recovers."
                            />

                            <div className="grid gap-3 sm:grid-cols-2">
                                <Input
                                    label="Send to"
                                    type="email"
                                    placeholder="you@example.com"
                                    disabled={!alertsOn}
                                    value={form.low_stock_email}
                                    onChange={(e) => setForm({ ...form, low_stock_email: e.target.value })}
                                />
                                <Input
                                    label="Cooldown (hours)"
                                    type="number"
                                    min="0"
                                    inputMode="numeric"
                                    disabled={!alertsOn}
                                    value={cooldown}
                                    onChange={(e) => setCooldown(e.target.value)}
                                />
                            </div>

                            <p className="text-xs text-ink-subtle">
                                Sent through the SMTP server configured in .env (e.g. Gmail SMTP).
                            </p>

                            <div className="border-t border-line pt-4">
                                <Switch
                                    checked={allowNegative}
                                    onChange={setAllowNegative}
                                    label="Allow negative stock"
                                    description="Off by default: deductions clamp at zero and the shortfall is flagged instead. Turn this on to record the true oversell below zero."
                                />
                            </div>

                            <div className="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                                <Button variant="secondary" loading={testMail.isPending} onClick={() => testMail.mutate()}>
                                    <Send className="size-4" aria-hidden="true" />
                                    Send a test email
                                </Button>
                                <span className="text-xs text-ink-subtle">
                                    Uses the address above. Verifies SMTP delivery, so check spam too.
                                </span>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    );
}
