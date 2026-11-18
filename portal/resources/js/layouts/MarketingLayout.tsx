import { useEffect, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import { Menu, Moon, Sun, X } from 'lucide-react';
import { useTheme } from '../lib/theme';
import { cn } from '../lib/cn';

const LINKS = [
    { to: '/#features', label: 'Features' },
    { to: '/#how', label: 'How it works' },
    { to: '/#faq', label: 'FAQ' },
];

const FOOTER = [
    {
        title: 'Product',
        links: [
            { label: 'Features', to: '/#features' },
            { label: 'How it works', to: '/#how' },
            { label: 'Integrations', to: '/#bridge' },
            { label: 'FAQ', to: '/#faq' },
        ],
    },
    {
        title: 'Get started',
        links: [
            { label: 'Open a workspace', to: '/register' },
            { label: 'Sign in', to: '/login' },
        ],
    },
];

function Wordmark({ tone = 'ink' }: { tone?: 'ink' | 'plate' }) {
    return (
        <span className={cn('wm-wordmark', tone === 'plate' && 'wm-wordmark--plate')}>
            <span className="wm-wordmark__mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" className="size-4">
                    <path
                        d="M12 3.25 20.5 7v10L12 20.75 3.5 17V7L12 3.25Z"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        strokeLinejoin="round"
                    />
                    <path
                        d="M12 20.5V12M20.25 7.15 12 11.9 3.75 7.15"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        strokeLinejoin="round"
                        opacity=".85"
                    />
                </svg>
            </span>
            <span className="wm-wordmark__text">StockPilot</span>
        </span>
    );
}

export default function MarketingLayout() {
    const [open, setOpen] = useState(false);
    const [retracted, setRetracted] = useState(false);
    const { theme, toggle } = useTheme();
    const { pathname } = useLocation();

    useEffect(() => {
        let last = window.scrollY;
        const onScroll = () => {
            const y = window.scrollY;
            setRetracted(y > 200 && y > last);
            last = y;
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => setOpen(false), [pathname]);

    return (
        <div className="wm">
            <a className="wm-skip" href="#main">
                Skip to content
            </a>

            <div className="wm-banner">
                <div className="container wm-banner__inner">
                    <p>The WooCommerce bridge only reads. Nothing is ever written back to your store.</p>
                    <Link to="/register">Open a workspace</Link>
                </div>
            </div>

            <header className={cn('wm-bar', retracted && 'is-retracted')}>
                <div className="container wm-bar__inner">
                    <Link to="/" aria-label="StockPilot home">
                        <Wordmark />
                    </Link>

                    <nav className="wm-nav" aria-label="Main">
                        {LINKS.map((l) => (
                            <a key={l.to} href={l.to}>
                                {l.label}
                            </a>
                        ))}
                    </nav>

                    <div className="wm-bar__end">
                        <button
                            type="button"
                            onClick={toggle}
                            aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
                            className="wm-icon-btn"
                        >
                            {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                        </button>
                        <Link to="/login" className="wm-signin">
                            Sign in
                        </Link>
                        <Link to="/register" className="btn btn--accent btn--sm">
                            Start free
                        </Link>
                        <button
                            type="button"
                            onClick={() => setOpen((v) => !v)}
                            aria-label={open ? 'Close menu' : 'Open menu'}
                            aria-expanded={open}
                            className="wm-icon-btn wm-burger"
                        >
                            {open ? <X className="size-5" /> : <Menu className="size-5" />}
                        </button>
                    </div>
                </div>

                {open && (
                    <div className="wm-menu">
                        <nav className="container" aria-label="Mobile">
                            {LINKS.map((l) => (
                                <a key={l.to} href={l.to} onClick={() => setOpen(false)}>
                                    {l.label}
                                </a>
                            ))}
                            <div className="wm-menu__actions">
                                <Link to="/login" className="btn btn--quiet" onClick={() => setOpen(false)}>
                                    Sign in
                                </Link>
                                <Link to="/register" className="btn btn--accent" onClick={() => setOpen(false)}>
                                    Start free
                                </Link>
                            </div>
                        </nav>
                    </div>
                )}
            </header>

            <main id="main" key={pathname}>
                <Outlet />
            </main>

            <footer className="foot">
                <div className="container">
                    <div className="foot__grid">
                        <div className="foot__about">
                            <Link to="/" aria-label="StockPilot home">
                                <Wordmark />
                            </Link>
                            <p>
                                Inventory and warehouse management built for businesses that assemble and sell
                                kit products on WooCommerce.
                            </p>
                        </div>
                        {FOOTER.map((col) => (
                            <div className="foot__col" key={col.title}>
                                <h3>{col.title}</h3>
                                <ul>
                                    {col.links.map((l) => (
                                        <li key={l.label}>
                                            <a href={l.to}>{l.label}</a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                    <div className="foot__legal">
                        <p>© {new Date().getFullYear()} StockPilot</p>
                        <p>Inventory and order records for kit sellers on WooCommerce.</p>
                    </div>
                </div>
            </footer>
        </div>
    );
}
