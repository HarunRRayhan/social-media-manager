import type { ReactNode } from 'react';
import type { PlatformKey } from '@/lib/platforms';
import { cn } from '@/lib/utils';

// Simplified brand marks, drawn white on a tile in the brand's color.
const marks: Record<PlatformKey, { tile: string; glyph: ReactNode }> = {
    facebook: {
        tile: 'bg-[#1877F2]',
        glyph: (
            <path
                fill="currentColor"
                d="M13.4 21v-7.3h2.5l.4-2.9h-2.9V9c0-.8.3-1.4 1.4-1.4h1.6V5.1c-.3 0-1.2-.1-2.3-.1-2.3 0-3.8 1.4-3.8 3.9v2H7.7v2.9h2.6V21z"
            />
        ),
    },
    instagram: {
        tile: 'bg-linear-to-tr from-[#F58529] via-[#DD2A7B] to-[#8134AF]',
        glyph: (
            <g fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="4" y="4" width="16" height="16" rx="4.5" />
                <circle cx="12" cy="12" r="3.6" />
                <circle
                    cx="16.6"
                    cy="7.4"
                    r=".6"
                    fill="currentColor"
                    stroke="none"
                />
            </g>
        ),
    },
    threads: {
        tile: 'bg-black',
        glyph: (
            <g fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="3.4" />
                <path
                    strokeLinecap="round"
                    d="M15.4 12v1.2a2.6 2.6 0 0 0 5.2 0V12A8.6 8.6 0 1 0 17 18.9"
                />
            </g>
        ),
    },
    x: {
        tile: 'bg-black',
        glyph: (
            <g fill="currentColor">
                <path d="M4.5 4.5h4.2l10.8 15H15.3z" />
                <path
                    stroke="currentColor"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                    d="M18.8 4.5 13.4 10.7M10.6 13.3 5.2 19.5"
                />
            </g>
        ),
    },
    linkedin: {
        tile: 'bg-[#0A66C2]',
        glyph: (
            <g fill="currentColor">
                <circle cx="6.5" cy="6.6" r="1.8" />
                <rect x="5" y="9.6" width="3" height="9.6" />
                <path d="M10.4 9.6h2.9V11c.5-.9 1.7-1.7 3.3-1.7 3 0 3.6 2 3.6 4.5v5.4h-3v-4.8c0-1.2-.1-2.6-1.7-2.6s-2.1 1.2-2.1 2.6v4.8h-3z" />
            </g>
        ),
    },
    tiktok: {
        tile: 'bg-black',
        glyph: (
            <path
                fill="currentColor"
                d="M13.6 3.5h2.8a4.4 4.4 0 0 0 3.9 3.9v2.9a7.2 7.2 0 0 1-3.9-1.2v5.8a5.5 5.5 0 1 1-5.5-5.5h.3v3a2.6 2.6 0 1 0 2.4 2.5z"
            />
        ),
    },
    youtube: {
        tile: 'bg-[#FF0000]',
        glyph: <path fill="currentColor" d="M9.8 8.2v7.6l6.4-3.8z" />,
    },
    bluesky: {
        tile: 'bg-[#0085FF]',
        glyph: (
            <path
                fill="currentColor"
                d="M12 11.2C11 9.2 8.2 5.6 5.8 4.8 4.4 4.3 3.5 4.9 3.5 6.6c0 .9.4 4.4.9 5.2.9 1.7 3.1 2 4.9 1.8-3.1.5-4 2.2-2.2 3.9 2.9 2.7 4.2-.6 4.9-2.6.7 2 2 5.3 4.9 2.6 1.8-1.7.9-3.4-2.2-3.9 1.8.2 4-.1 4.9-1.8.5-.8.9-4.3.9-5.2 0-1.7-.9-2.3-2.3-1.8-2.4.8-5.2 4.4-6.2 6.4z"
            />
        ),
    },
};

export default function PlatformIcon({
    platform,
    className,
}: {
    platform: PlatformKey;
    className?: string;
}) {
    const { tile, glyph } = marks[platform];

    return (
        <span
            className={cn(
                'inline-flex size-10 shrink-0 items-center justify-center rounded-xl text-white',
                tile,
                className,
            )}
            aria-hidden
        >
            <svg viewBox="0 0 24 24" className="size-[60%]">
                {glyph}
            </svg>
        </span>
    );
}
