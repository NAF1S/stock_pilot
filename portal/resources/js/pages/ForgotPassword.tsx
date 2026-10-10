import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { getCsrfCookie } from '../bootstrap';
import { apiPost, errMsg } from '../lib/api';
import { Button, Card, Flash, Input } from '../components/ui';

const GENERIC_MESSAGE =
    'If an account exists for that email address, a password reset link is on its way.';

export default function ForgotPassword() {
    const [email, setEmail] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        try {
            await getCsrfCookie();
            const res = await apiPost<{ ok: boolean; message: string }>('/auth/forgot', { email });
            setMessage(res.message || GENERIC_MESSAGE);
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
                    <p className="text-sm text-gray-500">Reset your password</p>
                </div>
                <Card>
                    {message ? (
                        <div className="space-y-4">
                            <Flash message={message} tone="green" />
                            <p className="text-xs text-gray-500">
                                The link expires in one hour and can only be used once. Didn&apos;t get it? Check
                                your spam folder, then try again.
                            </p>
                            <Link
                                to="/login"
                                className="block text-center text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                            >
                                Back to sign in
                            </Link>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-3">
                            <Flash message={error} tone="red" />
                            <p className="text-xs text-gray-500">
                                Enter the email address you use to sign in and we&apos;ll send you a reset link.
                            </p>
                            <Input
                                label="Email"
                                type="email"
                                required
                                autoFocus
                                autoComplete="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                            />
                            <Button type="submit" className="w-full" loading={loading}>
                                Send reset link
                            </Button>

                            <div className="pt-2 text-center text-xs text-gray-500">
                                Remembered it?{' '}
                                <Link to="/login" className="font-semibold text-indigo-600 hover:text-indigo-800">
                                    Sign in
                                </Link>
                            </div>
                        </form>
                    )}
                </Card>
            </div>
        </div>
    );
}
