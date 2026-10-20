import BlueskyConnectionController from '@/actions/App/Http/Controllers/Team/BlueskyConnectionController';
import MetaConnectionController from '@/actions/App/Http/Controllers/Team/MetaConnectionController';
import ThreadsConnectionController from '@/actions/App/Http/Controllers/Team/ThreadsConnectionController';

export type PlatformKey =
    | 'facebook'
    | 'instagram'
    | 'threads'
    | 'x'
    | 'linkedin'
    | 'tiktok'
    | 'youtube'
    | 'bluesky';

/**
 * Every platform the Connections page knows, in display order. `connect` is the
 * sign-in URL, or null while the platform is not supported yet. One Meta login
 * connects both Facebook and Instagram, so they share a URL. Bluesky has no
 * sign-in page: its URL takes a handle and app password posted from a form.
 */
export const platforms: {
    key: PlatformKey;
    label: string;
    connect: string | null;
}[] = [
    {
        key: 'facebook',
        label: 'Facebook',
        connect: MetaConnectionController.redirect.url(),
    },
    {
        key: 'instagram',
        label: 'Instagram',
        connect: MetaConnectionController.redirect.url(),
    },
    {
        key: 'threads',
        label: 'Threads',
        connect: ThreadsConnectionController.redirect.url(),
    },
    { key: 'x', label: 'X', connect: null },
    { key: 'linkedin', label: 'LinkedIn', connect: null },
    { key: 'tiktok', label: 'TikTok', connect: null },
    { key: 'youtube', label: 'YouTube', connect: null },
    {
        key: 'bluesky',
        label: 'Bluesky',
        connect: BlueskyConnectionController.store.url(),
    },
];

// Platforms whose connect route only works once Meta app credentials are configured.
export const metaPlatforms: PlatformKey[] = ['facebook', 'instagram'];
