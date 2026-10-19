import type { PlatformKey } from '@/lib/platforms';

export type AutomationTypeKey = 'comment_to_dm';

/**
 * Every automation type the New automation picker shows, in display order.
 * `platforms` run it today; `soon` are listed as coming soon.
 */
export const automationTypes: {
    key: AutomationTypeKey;
    label: string;
    description: string;
    platforms: PlatformKey[];
    soon: PlatformKey[];
}[] = [
    {
        key: 'comment_to_dm',
        label: 'Comment-to-DM',
        description:
            'Send a private message to everyone who comments a keyword on a post.',
        platforms: ['facebook', 'instagram'],
        soon: ['tiktok'],
    },
];

export const automationTypeLabel = (key: string) =>
    automationTypes.find((type) => type.key === key)?.label ?? key;
