import React, { useMemo, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { Check } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { errMsg } from '../lib/api';
import { cn } from '../lib/cn';
import { AuthLayout } from '../layouts/AuthLayout';
import { Button, Flash, Input, Select } from '../components/ui';

const CURRENCIES = [
    { value: 'BDT', label: 'BDT (৳) - Bangladeshi Taka' },
    { value: 'USD', label: 'USD ($) - US Dollar' },
    { value: 'EUR', label: 'EUR (€) - Euro' },
    { value: 'GBP', label: 'GBP (£) - British Pound' },
    { value: 'AUD', label: 'AUD ($) - Australian Dollar' },
    { value: 'CAD', label: 'CAD ($) - Canadian Dollar' },
    { value: 'INR', label: 'INR (₹) - Indian Rupee' },
    { value: 'AED', label: 'AED (د.إ) - UAE Dirham' },
    { value: 'SAR', label: 'SAR (﷼) - Saudi Riyal' },
    { value: 'SGD', label: 'SGD ($) - Singapore Dollar' },
];

function passwordScore(pw: string) {
    let score = 0;
    if (pw.length >= 8) score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    return Math.min(score, 4);
}

const STRENGTH = [
    { label: 'Too short', tone: 'bg-line', text: 'text-ink-subtle' },
    { label: 'Weak', tone: 'bg-danger', text: 'text-danger' },
    { label: 'Fair', tone: 'bg-figure', text: 'text-ink' },
    { label: 'Good', tone: 'bg-brand-600', text: 'text-brand-700 dark:text-brand-300' },
    { label: 'Strong', tone: 'bg-positive', text: 'text-positive' },
];

export default function Register() {
    const { register, user } = useAuth();
    const navigate = useNavigate();

    const [form, setForm] = useState({
        name: '',
        email: '',
        password: '',
        organization_name: '',
        currency: 'BDT',
    });
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const score = useMemo(() => passwordScore(form.password), [form.password]);
    const strength = STRENGTH[score];

    if (user) return <Navigate to="/app" replace />;

    const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        setForm((f) => ({ ...f, [key]: e.target.value }));

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        try {
            await register({
                name: form.name,
                email: form.email,
                password: form.password,
                organization_name: form.organization_name,
                currency: form.currency,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            });
            navigate('/app', { replace: true });
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthLayout
            title="Create your workspace"
            subtitle="A private workspace for your shop, independent of every other store."
            wide
            footer={
                <>
                    Already have an account?{' '}
                    <Link to="/login" className="font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-300">
                        Sign in
                    </Link>
                </>
            }
        >
            <div className="border-2 border-line bg-surface p-4 sm:p-5">
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <Flash message={error} tone="red" />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label="Your name"
                            placeholder="John Doe"
                            autoComplete="name"
                            value={form.name}
                            onChange={set('name')}
                            required
                        />
                        <Input
                            label="Email address"
                            type="email"
                            placeholder="you@example.com"
                            autoComplete="email"
                            value={form.email}
                            onChange={set('email')}
                            required
                        />
                    </div>

                    <div>
                        <Input
                            label="Password"
                            type="password"
                            placeholder="At least 8 characters"
                            autoComplete="new-password"
                            value={form.password}
                            onChange={set('password')}
                            hint="8+ chars, a number and a symbol"
                            required
                        />
                        {form.password.length > 0 && (
                            <div className="mt-2 flex items-center gap-2">
                                <div className="flex flex-1 gap-1">
                                    {[0, 1, 2, 3].map((i) => (
                                        <span
                                            key={i}
                                            className={cn('h-1 flex-1', i < score ? strength.tone : 'bg-line')}
                                        />
                                    ))}
                                </div>
                                <span className={cn('text-xs font-medium', strength.text)}>
                                    {strength.label}
                                </span>
                            </div>
                        )}
                    </div>

                    <div className="border border-line bg-surface-sunken p-3">
                        <div className="mb-2.5 text-xs font-medium text-ink-subtle">Your shop</div>
                        <div className="space-y-4">
                            <Input
                                label="Shop / organization name"
                                placeholder="My WooCommerce Store"
                                value={form.organization_name}
                                onChange={set('organization_name')}
                                required
                            />
                            <Select label="Base currency" value={form.currency} onChange={set('currency')}>
                                {CURRENCIES.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    </div>

                    <ul className="space-y-1.5 text-xs text-ink-muted">
                        {['Items, products and multi-level recipes', 'An append-only stock ledger', 'Connect WooCommerce in minutes'].map(
                            (f) => (
                                <li key={f} className="flex items-center gap-2">
                                    <Check className="size-3.5 shrink-0 text-positive" aria-hidden="true" />
                                    {f}
                                </li>
                            ),
                        )}
                    </ul>

                    <Button type="submit" size="lg" className="w-full" loading={loading}>
                        Create Workspace &amp; Sign in
                    </Button>
                </form>
            </div>
        </AuthLayout>
    );
}
