import { Head, Link, usePage } from '@inertiajs/react';
import { home } from '@/routes';

type Page = 'privacy' | 'terms' | 'data-deletion';

const pages: Record<Page, { title: string; body: string[] }> = {
    privacy: {
        title: 'Privacy policy',
        body: [
            'SMM is a personal tool for scheduling and publishing posts to social accounts its team members connect.',
            'When you connect a Facebook Page, Instagram account or Threads profile, SMM stores the account name, its ID and an access token. Tokens are encrypted at rest and used only to publish your posts, read comments on them and send the replies or direct messages you set up.',
            'For automated replies it keeps only the comment ID and commenter ID, not the comment text.',
            'SMM stores the posts, media links and schedules you create, and your email address for signing in. It does not sell or share any of this data, and it does not use it for advertising.',
            'Questions: info@bytechsoft.com.',
        ],
    },
    terms: {
        title: 'Terms of service',
        body: [
            'SMM is provided as is, for publishing content you own or are allowed to publish.',
            'You are responsible for the posts you schedule and must follow the terms of each platform you publish to.',
            'Access can be removed at any time. Questions: info@bytechsoft.com.',
        ],
    },
    'data-deletion': {
        title: 'Data deletion',
        body: [
            'To remove a connected account, open Team, then Connections, and disconnect it. Its stored token is deleted at once.',
            'To delete your SMM account and the data tied to it, open Settings, then Profile, and delete your account, or email info@bytechsoft.com and it will be deleted within 30 days.',
            'You can also remove SMM from your Facebook settings under Apps and websites.',
        ],
    },
};

export default function Legal() {
    const { page, name } = usePage<{ page: Page; name: string }>().props;
    const { title, body } = pages[page];

    return (
        <>
            <Head title={title} />

            <main className="mx-auto max-w-2xl px-6 py-16 text-foreground">
                <Link
                    href={home()}
                    className="flex items-center gap-2 font-semibold"
                >
                    <img src="/logo.svg" alt="" className="size-8" />
                    {name}
                </Link>

                <h1 className="mt-10 text-3xl font-semibold">{title}</h1>

                {body.map((text) => (
                    <p key={text} className="mt-4 text-muted-foreground">
                        {text}
                    </p>
                ))}

                <p className="mt-10 text-sm text-muted-foreground">
                    SMM is operated by Bytech, 218/3 West Kafrul, Begum Rokeya
                    Sarani, Mirpur, Dhaka 1207, Bangladesh.
                </p>
            </main>
        </>
    );
}
