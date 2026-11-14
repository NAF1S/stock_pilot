import React, { useState } from 'react';
import { Database, ShieldCheck } from 'lucide-react';
import { apiPost, errMsg } from '../lib/api';
import { AuthLayout } from '../layouts/AuthLayout';
import { Button, Flash, Input } from '../components/ui';
import { cn } from '../lib/cn';

const STEPS = ['Database', 'Admin account'];

export default function Setup({ onDone }: { onDone: () => void }) {
    const [step, setStep] = useState(1);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [db, setDb] = useState({ host: '127.0.0.1', port: '3306', database: '', username: '', password: '' });
    const [admin, setAdmin] = useState({
        organization_name: '',
        name: '',
        email: '',
        password: '',
        currency: 'BDT',
    });

    const connect = async () => {
        setLoading(true);
        setError(null);
        try {
            await apiPost('/setup/database', db);
            setStep(2);
        } catch (e) {
            setError(errMsg(e));
        } finally {
            setLoading(false);
        }
    };

    const finish = async () => {
        setLoading(true);
        setError(null);
        try {
            await apiPost('/setup/admin', admin);
            onDone();
        } catch (e) {
            setError(errMsg(e));
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthLayout
            title={step === 1 ? 'Connect your database' : 'Create the administrator'}
            subtitle={
                step === 1
                    ? 'StockPilot creates all of its own tables. Enter your MySQL or MariaDB credentials.'
                    : 'The first account owns the workspace and can invite everyone else.'
            }
            badge={`Step ${step} of 2`}
            wide
        >
            <div className="border-2 border-line bg-surface p-4 sm:p-5">
                <ol className="mb-4 flex items-stretch gap-1 border border-line">
                    {STEPS.map((label, i) => {
                        const index = i + 1;
                        const active = step === index;
                        const done = step > index;
                        return (
                            <li
                                key={label}
                                aria-current={active ? 'step' : undefined}
                                className={cn(
                                    'flex min-w-0 flex-1 items-center gap-2 px-2.5 py-2',
                                    i > 0 && 'border-l border-line',
                                    active && 'sp-cell-on',
                                )}
                            >
                                <span
                                    className={cn(
                                        'sp-code text-xs',
                                        active ? 'text-plate-ink opacity-75' : 'text-ink-subtle',
                                    )}
                                >
                                    {index}
                                </span>
                                <span
                                    className={cn(
                                        'truncate text-xs font-semibold',
                                        active ? 'text-plate-ink' : done ? 'text-ink' : 'text-ink-subtle',
                                    )}
                                >
                                    {label}
                                </span>
                            </li>
                        );
                    })}
                </ol>

                <Flash message={error} tone="red" />

                {step === 1 && (
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="sm:col-span-2">
                                <Input
                                    label="Database host"
                                    autoComplete="off"
                                    value={db.host}
                                    onChange={(e) => setDb({ ...db, host: e.target.value })}
                                />
                            </div>
                            <Input
                                label="Port"
                                inputMode="numeric"
                                autoComplete="off"
                                value={db.port}
                                onChange={(e) => setDb({ ...db, port: e.target.value })}
                            />
                        </div>
                        <Input
                            label="Database name"
                            placeholder="stockpilot"
                            autoComplete="off"
                            value={db.database}
                            onChange={(e) => setDb({ ...db, database: e.target.value })}
                        />
                        <Input
                            label="Username"
                            autoComplete="off"
                            value={db.username}
                            onChange={(e) => setDb({ ...db, username: e.target.value })}
                        />
                        <Input
                            label="Password"
                            type="password"
                            autoComplete="off"
                            value={db.password}
                            onChange={(e) => setDb({ ...db, password: e.target.value })}
                        />
                        <Button className="w-full" loading={loading} onClick={connect}>
                            <Database className="size-4" aria-hidden="true" />
                            Connect &amp; create tables
                        </Button>
                    </div>
                )}

                {step === 2 && (
                    <div className="space-y-4">
                        <Input
                            label="Company / organization name"
                            placeholder="My WooCommerce Store"
                            value={admin.organization_name}
                            onChange={(e) => setAdmin({ ...admin, organization_name: e.target.value })}
                        />
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="sm:col-span-2">
                                <Input
                                    label="Your name"
                                    autoComplete="name"
                                    value={admin.name}
                                    onChange={(e) => setAdmin({ ...admin, name: e.target.value })}
                                />
                            </div>
                            <Input
                                label="Currency"
                                maxLength={3}
                                autoComplete="off"
                                value={admin.currency}
                                onChange={(e) => setAdmin({ ...admin, currency: e.target.value.toUpperCase() })}
                            />
                        </div>
                        <Input
                            label="Email"
                            type="email"
                            autoComplete="email"
                            placeholder="you@company.com"
                            value={admin.email}
                            onChange={(e) => setAdmin({ ...admin, email: e.target.value })}
                        />
                        <Input
                            label="Password"
                            type="password"
                            autoComplete="new-password"
                            placeholder="At least 8 characters"
                            value={admin.password}
                            onChange={(e) => setAdmin({ ...admin, password: e.target.value })}
                        />
                        <Button className="w-full" loading={loading} onClick={finish}>
                            <ShieldCheck className="size-4" aria-hidden="true" />
                            Finish setup
                        </Button>
                    </div>
                )}
            </div>
        </AuthLayout>
    );
}
