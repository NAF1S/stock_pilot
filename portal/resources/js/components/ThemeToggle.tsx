import { Moon, Sun } from 'lucide-react';
import { useTheme } from '../lib/theme';
import { cn } from '../lib/cn';

export function ThemeToggle({ className }: { className?: string }) {
    const { theme, toggle } = useTheme();
    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
            title={theme === 'dark' ? 'Light mode' : 'Dark mode'}
            className={cn(
                'inline-flex size-9 items-center justify-center rounded-none text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink',
                className,
            )}
        >
            {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
        </button>
    );
}
