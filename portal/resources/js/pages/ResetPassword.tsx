import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { getCsrfCookie } from '../bootstrap';
import { apiPost, errMsg } from '../lib/api';
import { Button, Card, Flash, Input } from '../components/ui';

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
        <div className="flex min-h-screen items-center justify-center bg-gray-100 p-4">
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <div className="text-2xl font-bold text-indigo-700">StockPilot</div>
                    <p className="text-sm text-gray-500">Choose a new password</p>
                </div>
                <Card>
                    {done ? (
                        <div className="space-y-4">
                            <Flash message="Your password has been reset." tone="green" />
                            <p className="text-xs text-gray-500">
                                For your security, every other signed-in session has been signed out. Sign in again
                                with your new password.
                            </p>
                            <Link
                                to="/login"
                                className="block text-center text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                Sign in
                            </Link>
                        </div>
                    ) : !token ? (
                        <div className="space-y-4">
                            <Flash message="This reset link is incomplete or invalid." tone="red" />
                            <p className="text-xs text-gray-500">
                                Password reset links expire after one hour. Request a fresh one and try again.
                            </p>
                            <Link
                                to="/forgot-password"
                                className="block text-center text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                Request a new link
                            </Link>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-3">
                            <Flash message={error} tone="red" />
                            <Input
                                label="Email"
                                type="email"
                                required
                                autoComplete="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                            />
                            <Input
                                label="New password"
                                type="password"
                                required
                                autoFocus
                                autoComplete="new-password"
                                placeholder="At least 8 characters"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                            <Input
                                label="Confirm new password"
                                type="password"
                                required
                                autoComplete="new-password"
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                            />
                            <Button type="submit" className="w-full" loading={loading}>
                                Set new password
                            </Button>

                            <div className="pt-2 text-center text-xs text-gray-500">
                                Link expired?{' '}
                                <Link
                                    to="/forgot-password"
                                    className="font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    Request a new one
                                </Link>
                            </div>
                        </form>
                    )}
                </Card>
            </div>
        </div>
    );
}
