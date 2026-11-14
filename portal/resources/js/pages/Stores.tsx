import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, Plug, Plus, RotateCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { apiDelete, apiGet, apiPost, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime } from '../lib/format';
import { Badge, Button, Card, EmptyState, Flash, Input, Modal, PageHeader, Tooltip } from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';
import type { Tone } from '../lib/status';

interface Store {
    id: number;
    name: string;
    store_url: string;
    plugin_version: string | null;
    is_active: boolean;
    last_seen_at: string | null;
    last_sync_at: string | null;
    last_error: string | null;
    sync_count: number;
}

const STORE_TONE: Record<'active' | 'revoked', Tone> = { active: 'green', revoked: 'red' };

const BRIDGE_STEPS = [
    {
        title: 'The plugin reads and forwards',
        body: 'StockPilot for WooCommerce reads orders and catalogue data on your store and posts it to this portal.',
    },
    {
        title: 'Every payload is signed',
        body: 'Each request carries an HMAC signature made with the store secret, and the portal refuses anything that does not verify.',
    },
    {
        title: 'Nothing is written back',
        body: 'No status updates, no stock writes, no post meta. Disconnecting leaves the store exactly as it was.',
    },
];

export default function Stores() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState({ name: '', store_url: '' });
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [credentials, setCredentials] = useState<{ api_key: string; api_secret: string } | null>(null);

    const { data } = useQuery({ queryKey: ['stores'], queryFn: () => apiGet<{ stores: Store[] }>('/stores') });

    const create = useMutation({
        mutationFn: () => apiPost<{ api_key: string; api_secret: string }>('/stores', form),
        onSuccess: (r) => {
            qc.invalidateQueries({ queryKey: ['stores'] });
            setCredentials({ api_key: r.api_key, api_secret: r.api_secret });
            setCreateOpen(false);
            setForm({ name: '', store_url: '' });
        },
        onError: (e) => setError(errMsg(e)),
    });

    const rotate = useMutation({
        mutationFn: (id: number) => apiPost<{ api_key: string; api_secret: string }>(`/stores/${id}/rotate-key`),
        onSuccess: (r) => setCredentials({ api_key: r.api_key, api_secret: r.api_secret }),
        onError: (e) => setFlash(errMsg(e)),
    });

    const revoke = useMutation({
        mutationFn: (id: number) => apiDelete(`/stores/${id}`),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['stores'] }); setFlash('Store disconnected.'); },
        onError: (e) => setFlash(errMsg(e)),
    });

    const stores = data?.stores ?? [];
    const syncing = stores.filter((s) => s.is_active).length;

    const columns: Column<Store>[] = [
        {
            head: 'Store',
            key: true,
            cell: (store) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium text-ink">{store.name}</span>
                    <span className="sp-code truncate">{store.store_url}</span>
                </span>
            ),
        },
        {
            head: 'Status',
            cell: (store) => (
                <Badge tone={store.is_active ? STORE_TONE.active : STORE_TONE.revoked} dot>
                    {store.is_active ? 'connected' : 'revoked'}
                </Badge>
            ),
        },
        {
            head: 'Last sync',
            hide: 'md',
            cell: (store) => <span className="sp-code text-xs">{dateTime(store.last_sync_at)}</span>,
        },
        {
            head: 'Orders synced',
            align: 'end',
            code: true,
            hide: 'lg',
            cell: (store) => store.sync_count,
        },
        {
            head: 'Key',
            hide: 'lg',
            cell: (store) => (
                <Tooltip label="Shown once at creation — the portal stores only a hash">
                    <span className="sp-code text-xs">sp_••••••••</span>
                </Tooltip>
            ),
        },
        {
            head: '',
            align: 'end',
            actions: true,
            cell: (store) => (
                <span className="flex items-center justify-end gap-1">
                    {user?.role === 'admin' && (
                        <>
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={`Rotate the key for ${store.name}`}
                                onClick={() => rotate.mutate(store.id)}
                            >
                                <RotateCw className="size-3.5" aria-hidden="true" />
                                Rotate key
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={`Disconnect ${store.name}`}
                                onClick={() => { if (confirm(`Disconnect ${store.name}?`)) revoke.mutate(store.id); }}
                            >
                                <Trash2 className="size-3.5" aria-hidden="true" />
                                Disconnect
                            </Button>
                        </>
                    )}
                </span>
            ),
        },
    ];

    return (
        <div>
            <PageHeader
                title="Stores"
                subtitle="Connected WooCommerce stores. The plugin reads and forwards; nothing is ever written back."
                actions={
                    user?.role === 'admin' ? (
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus className="size-4" aria-hidden="true" />
                            Connect store
                        </Button>
                    ) : undefined
                }
            />

            <Flash message={flash} />
            <Flash message={error} tone="red" />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
                <Card bodyClassName="p-0">
                    <div className="border-b border-line px-3 py-2.5">
                        <span className="text-xs text-ink-subtle">
                            {stores.length} stores · {syncing} syncing
                        </span>
                    </div>
                    <div className="px-3 py-1">
                        <DataTable
                            columns={columns}
                            rows={stores}
                            rowKey={(store) => store.id}
                            empty={
                                <EmptyState
                                    title="No stores connected"
                                    hint="Generate a key and secret, then paste them into the plugin."
                                    icon={<Plug className="size-5" aria-hidden="true" />}
                                    action={
                                        user?.role === 'admin' ? (
                                            <Button size="sm" onClick={() => setCreateOpen(true)}>
                                                Connect store
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            }
                        />
                    </div>
                </Card>

                <Card title="How the bridge works">
                    <ol className="space-y-3">
                        {BRIDGE_STEPS.map((step, i) => (
                            <li key={step.title} className="flex gap-3">
                                <span className="sp-figure shrink-0 text-lg text-brand-600">{i + 1}</span>
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-ink">{step.title}</span>
                                    <span className="mt-0.5 block text-xs text-ink-muted">{step.body}</span>
                                </span>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-4 border border-line bg-surface-sunken px-3 py-2">
                        <div className="flex items-center gap-1.5 text-xs text-ink-muted">
                            <Plug className="size-3.5" aria-hidden="true" />
                            Read-only by design
                        </div>
                        <p className="mt-1 text-xs text-ink-subtle">
                            The bridge never changes a store. Orders and catalogue data flow one way, into this portal.
                        </p>
                    </div>
                </Card>
            </div>

            <Modal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                title="Connect a WooCommerce store"
                description="The plugin needs the key and secret this produces."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
                        <Button loading={create.isPending} onClick={() => create.mutate()}>Generate credentials</Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <Input
                        label="Store name *"
                        placeholder="KitCraft Supplies"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                    />
                    <Input
                        label="Store URL *"
                        placeholder="https://yourshop.com"
                        type="url"
                        value={form.store_url}
                        onChange={(e) => setForm({ ...form, store_url: e.target.value })}
                    />
                </div>
            </Modal>

            <Modal
                open={!!credentials}
                onClose={() => setCredentials(null)}
                title="Store credentials"
                description="Copy these now. The secret is not shown again."
                wide
                footer={<Button onClick={() => setCredentials(null)}>I've saved it</Button>}
            >
                <div className="space-y-2">
                    <div className="border border-line p-2.5">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs text-ink-subtle">API key</span>
                        </div>
                        <div className="sp-code mt-1 break-all text-ink">{credentials?.api_key}</div>
                    </div>
                    <div className="border border-line p-2.5">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs text-ink-subtle">API secret</span>
                        </div>
                        <div className="sp-code mt-1 break-all text-ink">{credentials?.api_secret}</div>
                    </div>
                    <p className="flex items-start gap-1.5 border border-line bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                        <KeyRound className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        The secret is shown once and cannot be retrieved later. Paste the key and secret into the
                        WordPress plugin settings; rotating invalidates the old pair at once.
                    </p>
                </div>
            </Modal>
        </div>
    );
}
