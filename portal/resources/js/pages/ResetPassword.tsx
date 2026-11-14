import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, KeyRound } from 'lucide-react';
import { getCsrfCookie } from '../bootstrap';
import { apiPost, errMsg } from '../lib/api';
import { AuthLayout } from '../layouts/AuthLayout';
import { Button, Flash, Input } from '../components/ui';

export default function ResetPassword() {
    const [params] = useSearchParams();
    const token = params.get('token') ?? '';

    const [email, setEmail] = useState(params.get('email') ?? '');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [done, setDone] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (password !== confirmation) {
            setError('The two passwords do not match.');
            return;
        }

        setLoading(true);
        setError(null);
        try {
            await getCsrfCookie();
            await apiPost('/auth/reset', {
                token,
                email,
                password,
                password_confirmation: confirmation,
            });
            setDone(true);
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthLayout
            title="Choose a new password"
            subtitle="Every other signed-in session will be signed out for security."
            backTo="/login"
            backLabel="Back to sign in"
            footer={
                <>
                    Link expired?{' '}
                    <Link
                        to="/forgot-password"
                        className="font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-300"
                    >
                        Request a new one
                    </Link>
                </>
            }
        >
            <div className="border-2 border-line bg-surface p-4 sm:p-5">
                {done ? (
                    <div className="space-y-3">
                        <div className="flex size-10 items-center justify-center border border-line border-l-2 border-l-positive bg-surface-sunken text-positive">
                            <CheckCircle2 className="size-5" aria-hidden="true" />
                        </div>
                        <p className="text-sm leading-relaxed text-ink-muted">
                            Your password has been reset. Sign in again with your new password.
                        </p>
                        <Link to="/login" className="block">
                            <Button className="w-full">Go to sign in</Button>
                        </Link>
                    </div>
                ) : !token ? (
                    <div className="space-y-4">
                        <Flash message="This reset link is incomplete or invalid." tone="red" />
                        <p className="text-sm leading-relaxed text-ink-muted">
                            Password reset links expire after one hour. Request a fresh one and try again.
                        </p>
                        <Link to="/forgot-password" className="block">
                            <Button variant="secondary" className="w-full">
                                Request a new link
                            </Button>
                        </Link>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4" noValidate>
                        <Flash message={error} tone="red" />
                        <Input
                            label="Email address"
                            type="email"
                            autoComplete="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                        <Input
                            label="New password"
                            type="password"
                            autoComplete="new-password"
                            autoFocus
                            placeholder="At least 8 characters"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                        <Input
                            label="Confirm new password"
                            type="password"
                            autoComplete="new-password"
                            value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)}
                            required
                        />
                        <Button type="submit" className="w-full" loading={loading}>
                            <KeyRound className="size-4" aria-hidden="true" />
                            Set new password
                        </Button>
                    </form>
                )}
            </div>
        </AuthLayout>
    );
}
