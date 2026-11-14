import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import './bootstrap';
import { AuthProvider, useAuth } from './lib/auth';
import { ThemeProvider } from './lib/theme';
import { apiGet } from './lib/api';
import AppLayout from './layouts/AppLayout';
import MarketingLayout from './layouts/MarketingLayout';
import { FullPageSpinner } from './components/ui';
import Landing from './pages/marketing/Landing';
import Setup from './pages/Setup';
import Login from './pages/Login';
import Register from './pages/Register';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import Dashboard from './pages/Dashboard';
import Items from './pages/Items';
import Products from './pages/Products';
import Orders from './pages/Orders';
import MovementAnalytics from './pages/MovementAnalytics';
import Activity from './pages/Activity';
import Reports from './pages/Reports';
import Stores from './pages/Stores';
import Settings from './pages/Settings';
import Users from './pages/Users';
import Forecasts from './pages/Forecasts';

const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false } },
});

function Protected() {
    const { user, loading } = useAuth();
    if (loading) return <FullPageSpinner />;
    if (!user) return <Navigate to="/login" replace />;
    return <AppLayout />;
}

/**
 * The public front door. A signed-in visitor goes straight to the product
 * rather than reading the marketing page they have already bought into.
 */
function RootRoute() {
    const { user, loading } = useAuth();
    if (loading) return <FullPageSpinner />;
    if (user) return <Navigate to="/app" replace />;
    return <Landing />;
}

/**
 * Old app paths predate the /app prefix. Carrying `search` across matters:
 * a bookmarked /orders?order=10428 should still open that order.
 */
function LegacyRedirect({ to }: { to: string }) {
    const { search } = useLocation();
    return <Navigate to={{ pathname: to, search }} replace />;
}

function AppRoutes() {
    return (
        <Routes>
            <Route element={<MarketingLayout />}>
                <Route path="/" element={<RootRoute />} />
            </Route>

            <Route path="/login" element={<Login />} />
            <Route path="/register" element={<Register />} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />

            <Route path="/app" element={<Protected />}>
                <Route index element={<Dashboard />} />
                <Route path="items" element={<Items />} />
                <Route path="products" element={<Products />} />
                <Route path="orders" element={<Orders />} />
                <Route path="movements" element={<MovementAnalytics />} />
                <Route path="forecasts" element={<Forecasts />} />
                <Route path="activity" element={<Activity />} />
                <Route path="reports" element={<Reports />} />
                <Route path="stores" element={<Stores />} />
                <Route path="settings" element={<Settings />} />
                <Route path="users" element={<Users />} />
            </Route>

            <Route path="/items" element={<LegacyRedirect to="/app/items" />} />
            <Route path="/products" element={<LegacyRedirect to="/app/products" />} />
            <Route path="/orders" element={<LegacyRedirect to="/app/orders" />} />
            <Route path="/movements" element={<LegacyRedirect to="/app/movements" />} />
            <Route path="/forecasts" element={<LegacyRedirect to="/app/forecasts" />} />
            <Route path="/activity" element={<LegacyRedirect to="/app/activity" />} />
            <Route path="/reports" element={<LegacyRedirect to="/app/reports" />} />
            <Route path="/stores" element={<LegacyRedirect to="/app/stores" />} />
            <Route path="/settings" element={<LegacyRedirect to="/app/settings" />} />
            <Route path="/users" element={<LegacyRedirect to="/app/users" />} />

            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}

function SetupGate({ children }: { children: React.ReactNode }) {
    const [setup, setSetup] = useState<boolean | null>(null);

    useEffect(() => {
        apiGet<{ setup: boolean }>('/setup/status')
            .then((r) => setSetup(r.setup))
            .catch(() => setSetup(true));
    }, []);

    if (setup === null) return <FullPageSpinner />;
    if (setup === false) return <Setup onDone={() => setSetup(true)} />;
    return <>{children}</>;
}

export default function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <ThemeProvider>
                <BrowserRouter>
                    <AuthProvider>
                        <SetupGate>
                            <AppRoutes />
                        </SetupGate>
                    </AuthProvider>
                </BrowserRouter>
            </ThemeProvider>
        </QueryClientProvider>
    );
}

const el = document.getElementById('app');
if (el) {
    createRoot(el).render(<App />);
}
