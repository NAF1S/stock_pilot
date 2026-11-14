import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Mail } from 'lucide-react';
import { getCsrfCookie } from '../bootstrap';
import { apiPost, errMsg } from '../lib/api';
import { AuthLayout } from '../layouts/AuthLayout';
import { Button, Flash, Input } from '../components/ui';

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
        <AuthLayout
            title={message ? 'Check your inbox' : 'Reset your password'}
            subtitle={
                message
                    ? 'If an account exists for that email, a reset link is on its way.'
                    : 'Enter the email address you sign in with and we’ll send you a reset link.'
            }
            backTo="/login"
            backLabel="Back to sign in"
            footer={
                <>
                    Remembered it?{' '}
                    <Link to="/login" className="font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-300">
                        Back to sign in
                    </Link>
                </>
            }
        >
            <div className="border-2 border-line bg-surface p-4 sm:p-5">
                {message ? (
                    <div className="space-y-3">
                        <div className="flex size-10 items-center justify-center border border-line border-l-2 border-l-positive bg-surface-sunken text-positive">
                            <CheckCircle2 className="size-5" aria-hidden="true" />
                        </div>
                        <Flash message={message} tone="green" />
                        <p className="text-sm leading-relaxed text-ink-muted">
                            We sent a reset link to <span className="font-semibold text-ink">{email}</span>. It
                            expires in one hour and can only be used once.
                        </p>
                        <div className="border border-line bg-surface-sunken px-3 py-2 text-left text-xs text-ink-subtle">
                            Didn&apos;t receive it? Check your spam folder, then{' '}
                            <button
                                type="button"
                                onClick={() => {
                                    setMessage(null);
                                    setError(null);
                                }}
                                className="font-semibold text-brand-600 hover:underline dark:text-brand-400"
                            >
                                try again
                            </button>
                            .
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4" noValidate>
                        <Flash message={error} tone="red" />
                        <Input
                            label="Email address"
                            type="email"
                            autoComplete="email"
                            autoFocus
                            placeholder="you@company.com"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                        <Button type="submit" className="w-full" loading={loading}>
                            <Mail className="size-4" aria-hidden="true" />
                            Send reset link
                        </Button>
                    </form>
                )}
            </div>
        </AuthLayout>
    );
}
