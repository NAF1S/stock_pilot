import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import { errMsg } from '../lib/api';
import { Button, Card, Flash, Input, Select } from '../components/ui';

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

    if (user) {
        navigate('/', { replace: true });
        return null;
    }

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
            navigate('/', { replace: true });
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 p-4">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <div className="text-3xl font-extrabold text-indigo-700">StockPilot</div>
                    <p className="mt-1 text-sm text-gray-500">Create your independent shop workspace</p>
                </div>
                <Card>
                    <form onSubmit={submit} className="space-y-3">
                        <Flash message={error} tone="red" />

                        <Input
                            label="Your Name"
                            type="text"
                            required
                            placeholder="John Doe"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                        />

                        <Input
                            label="Email Address"
                            type="email"
                            required
                            placeholder="you@example.com"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                        />

                        <Input
                            label="Password"
                            type="password"
                            required
                            placeholder="At least 8 characters"
                            value={form.password}
                            onChange={(e) => setForm({ ...form, password: e.target.value })}
                        />

                        <Input
                            label="Shop / Organization Name"
                            type="text"
                            required
                            placeholder="My WooCommerce Store"
                            value={form.organization_name}
                            onChange={(e) => setForm({ ...form, organization_name: e.target.value })}
                        />

                        <Select
                            label="Base Currency"
                            value={form.currency}
                            onChange={(e) => setForm({ ...form, currency: e.target.value })}
                        >
                            {CURRENCIES.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </Select>

                        <Button type="submit" className="w-full mt-2" loading={loading}>
                            Create Workspace &amp; Sign in
                        </Button>

                        <div className="pt-2 text-center text-xs text-gray-500">
                            Already have an account?{' '}
                            <Link to="/login" className="font-semibold text-indigo-600 hover:text-indigo-800">
                                Sign in
                            </Link>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    );
}
