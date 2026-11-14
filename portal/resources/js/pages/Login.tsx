import React, { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { errMsg } from '../lib/api';
import { AuthLayout } from '../layouts/AuthLayout';
import { Button, Checkbox, Flash, Input } from '../components/ui';

export default function Login() {
    const { login, user } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [remember, setRemember] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    if (user) return <Navigate to="/app" replace />;

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        try {
            await login(email, password);
            navigate('/app', { replace: true });
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthLayout
            title="Sign in"
            subtitle="Pick up where you left off."
            footer={
                <>
                    No workspace yet?{' '}
                    <Link to="/register" className="font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-300">
                        Register your shop
                    </Link>
                </>
            }
        >
            <div className="border-2 border-line bg-surface p-4 sm:p-5">
                <form onSubmit={submit} className="space-y-3.5" noValidate>
                    <Flash message={error} tone="red" />

                    <Input
                        label="Email address"
                        type="email"
                        autoComplete="email"
                        placeholder="you@company.com"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                    />

                    <div className="relative">
                        <Input
                            label="Password"
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="current-password"
                            placeholder="Your password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                            className="pr-10"
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            className="absolute right-1 bottom-0.5 grid size-8 place-items-center text-ink-subtle hover:bg-surface-sunken hover:text-ink"
                        >
                            {showPassword ? (
                                <EyeOff className="size-4" aria-hidden="true" />
                            ) : (
                                <Eye className="size-4" aria-hidden="true" />
                            )}
                        </button>
                    </div>

                    <div className="flex items-center justify-between gap-3">
                        <div className="sp-hit-row -ml-1">
                            <Checkbox
                                id="remember"
                                label="Remember me"
                                checked={remember}
                                onChange={(e) => setRemember(e.target.checked)}
                            />
                        </div>
                        <Link
                            to="/forgot-password"
                            className="text-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-300"
                        >
                            Forgot password?
                        </Link>
                    </div>

                    <Button type="submit" className="w-full" loading={loading}>
                        Sign in
                    </Button>
                </form>
            </div>
        </AuthLayout>
    );
}
