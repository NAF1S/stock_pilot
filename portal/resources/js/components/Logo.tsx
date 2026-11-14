import { cn } from '../lib/cn';

/**
 * StockPilot wordmark + mark. Pure SVG so it themes cleanly.
 * Wayfind: the mark is a square ink plate and the wordmark the body face.
 * No gradient — depth is a border.
 */
export function Logo({
    className,
    showText = true,
    size = 'md',
}: {
    className?: string;
    showText?: boolean;
    size?: 'sm' | 'md' | 'lg';
}) {
    const mark = { sm: 'size-7', md: 'size-8', lg: 'size-10' }[size];
    const text = { sm: 'text-base', md: 'text-lg', lg: 'text-xl' }[size];

    return (
        <span className={cn('inline-flex items-center gap-2', className)}>
            <span
                className={cn(
                    'relative grid shrink-0 place-items-center bg-plate text-plate-ink',
                    mark,
                )}
                aria-hidden="true"
            >
                <svg viewBox="0 0 24 24" fill="none" className="size-[62%]">
                    <path
                        d="M12 3.25 20.5 7v10L12 20.75 3.5 17V7L12 3.25Z"
                        stroke="currentColor"
                        strokeWidth="1.6"
                        strokeLinejoin="round"
                    />
                    <path
                        d="M12 20.5V12M20.25 7.15 12 11.9 3.75 7.15"
                        stroke="currentColor"
                        strokeWidth="1.6"
                        strokeLinejoin="round"
                        opacity=".85"
                    />
                </svg>
            </span>
            {showText && (
                <span className={cn('font-bold tracking-tight text-ink', text)}>
                    Stock<span className="text-brand-600 dark:text-brand-400">Pilot</span>
                </span>
            )}
        </span>
    );
}
