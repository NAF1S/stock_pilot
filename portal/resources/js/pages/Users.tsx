import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, UserPlus, Users as UsersIcon } from 'lucide-react';
import { useState } from 'react';
import { apiDelete, apiGet, apiPatch, apiPost, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Avatar, Badge, Button, Card, EmptyState, Flash, Input, Modal, PageHeader, Select } from '../components/ui';
import { DataTable, type Column } from '../components/ui/DataTable';
import { roleTone } from '../lib/status';

interface User {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'manager' | 'staff';
    is_active: boolean;
    last_login_at: string | null;
}

const roles = ['admin', 'manager', 'staff'] as const;

const ROLE_HINT: Record<User['role'], string> = {
    admin: 'Everything, including team accounts and store keys',
    manager: 'Stock, orders and reports, no team or keys',
    staff: 'Stock and orders only',
};

export default function Users() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin';
    const qc = useQueryClient();
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', password: '', role: 'staff' });

    const { data, error: queryError } = useQuery({ queryKey: ['users'], queryFn: () => apiGet<{ users: User[] }>('/users') });

    const create = useMutation({
        mutationFn: () => apiPost('/users', form),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['users'] }); setCreateOpen(false); setFlash('Team member added.'); setForm({ name: '', email: '', password: '', role: 'staff' }); },
        onError: (e) => setError(errMsg(e)),
    });

    const setRole = useMutation({
        mutationFn: ({ id, role }: { id: number; role: string }) => apiPatch(`/users/${id}/role`, { role }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
    });

    const toggle = useMutation({
        mutationFn: ({ id, active }: { id: number; active: boolean }) => apiPatch(`/users/${id}/${active ? 'deactivate' : 'activate'}`),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
    });

    const remove = useMutation({
        mutationFn: (id: number) => apiDelete(`/users/${id}`),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['users'] }); setFlash('User removed.'); },
        onError: (e) => setFlash(errMsg(e)),
    });

    const members = data?.users ?? [];
    const activeCount = members.filter((m) => m.is_active).length;

    const columns: Column<User>[] = [
        {
            head: 'Member',
            key: true,
            cell: (member) => (
                <span className="flex min-w-0 items-center gap-2">
                    <Avatar name={member.name} className="size-7" />
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate font-medium text-ink">{member.name}</span>
                        <span className="sp-code truncate">{member.email}</span>
                    </span>
                </span>
            ),
        },
        {
            head: 'Role',
            cell: (member) => <Badge tone={roleTone(member.role)}>{member.role}</Badge>,
        },
        {
            head: 'Status',
            cell: (member) => (
                <Badge tone={member.is_active ? 'green' : 'gray'} dot>
                    {member.is_active ? 'active' : 'deactivated'}
                </Badge>
            ),
        },
        {
            head: 'Last login',
            hide: 'md',
            cell: (member) => (
                <span className="sp-code text-xs">
                    {member.last_login_at ? new Date(member.last_login_at).toLocaleString() : 'never'}
                </span>
            ),
        },
        {
            head: '',
            align: 'end',
            actions: true,
            cell: (member) =>
                isAdmin ? (
                    <span className="flex items-center justify-end gap-1">
                        <Select
                            aria-label={`Change the role for ${member.name}`}
                            value={member.role}
                            onChange={(e) => setRole.mutate({ id: member.id, role: e.target.value })}
                            className="w-28 py-1 text-xs"
                        >
                            {roles.map((r) => (
                                <option key={r} value={r}>{r}</option>
                            ))}
                        </Select>
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label={member.is_active ? `Deactivate ${member.name}` : `Activate ${member.name}`}
                            onClick={() => toggle.mutate({ id: member.id, active: member.is_active })}
                        >
                            {member.is_active ? 'Deactivate' : 'Activate'}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label={`Remove ${member.name}`}
                            onClick={() => { if (confirm(`Remove ${member.name}?`)) remove.mutate(member.id); }}
                        >
                            Remove
                        </Button>
                    </span>
                ) : null,
        },
    ];

    return (
        <div>
            <PageHeader
                title="Team"
                subtitle="Staff accounts and what each role can reach."
                actions={
                    isAdmin ? (
                        <Button onClick={() => setCreateOpen(true)}>
                            <UserPlus className="size-4" aria-hidden="true" />
                            Add member
                        </Button>
                    ) : undefined
                }
            />

            <Flash message={flash} />
            <Flash message={error} tone="red" />
            <Flash message={queryError ? errMsg(queryError) : null} tone="red" />

            {queryError && (
                <p className="mb-4 text-sm text-ink-muted">
                    Team management is limited to administrators. Ask an admin in your workspace to
                    change roles or invite people.
                </p>
            )}

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                {roles.map((role) => (
                    <div key={role} className="border border-line bg-surface p-3">
                        <div className="flex items-baseline justify-between gap-2">
                            <Badge tone={roleTone(role)}>{role}</Badge>
                            <span className="sp-figure text-lg text-ink">
                                {members.filter((m) => m.role === role).length}
                            </span>
                        </div>
                        <p className="mt-2 text-xs text-ink-muted">{ROLE_HINT[role]}</p>
                    </div>
                ))}
            </div>

            <Card bodyClassName="p-0">
                <div className="border-b border-line px-3 py-2.5">
                    <span className="text-xs text-ink-subtle">
                        {members.length} members · {activeCount} active
                    </span>
                </div>
                <div className="px-3 py-1">
                    <DataTable
                        columns={columns}
                        rows={members}
                        rowKey={(member) => member.id}
                        empty={
                            <EmptyState
                                title="No members yet"
                                hint={
                                    isAdmin
                                        ? 'Add a team member to give someone access to this portal.'
                                        : 'Only an administrator can see or invite team members.'
                                }
                                icon={<UsersIcon className="size-5" aria-hidden="true" />}
                                action={
                                    isAdmin ? (
                                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                                            Add member
                                        </Button>
                                    ) : undefined
                                }
                            />
                        }
                    />
                </div>
            </Card>

            <Modal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                title="Add a team member"
                description="They sign in with the email and password you set here."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
                        <Button loading={create.isPending} onClick={() => create.mutate()}>
                            <Plus className="size-4" aria-hidden="true" />
                            Add member
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <Input label="Name *" placeholder="Full name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <Input label="Email *" type="email" placeholder="name@company.com" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                    <Input label="Password *" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                    <Select label="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                        {roles.map((r) => (
                            <option key={r} value={r}>{r} · {ROLE_HINT[r]}</option>
                        ))}
                    </Select>
                </div>
            </Modal>
        </div>
    );
}
