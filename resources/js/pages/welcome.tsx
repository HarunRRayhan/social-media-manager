import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, KeyRound, Layers, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const features = [
    {
        icon: CalendarClock,
        title: 'Schedule once',
        text: 'Write a post, pick a time, and it goes out on its own.',
    },
    {
        icon: Layers,
        title: 'Social sets',
        text: 'Group the accounts that post together, like a brand or a language.',
    },
    {
        icon: Users,
        title: 'Teams',
        text: 'Invite people, give them a role, keep each team separate.',
    },
    {
        icon: KeyRound,
        title: 'API first',
        text: 'Every post can be created by your own tools with a team token.',
    },
];

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title={name} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
                    <div className="flex items-center gap-2 font-semibold">
                        <img src="/logo.svg" alt="" className="size-8" />
                        {name}
                    </div>

                    <Button asChild variant="outline" size="sm">
                        {auth.user ? (
                            <Link href={dashboard()}>Dashboard</Link>
                        ) : (
                            <Link href={login()}>Log in</Link>
                        )}
                    </Button>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-16">
                    <img src="/logo.svg" alt="" className="mb-8 size-16" />

                    <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                        Plan, schedule and publish your social posts from one
                        place.
                    </h1>

                    <p className="mt-4 max-w-xl text-lg text-muted-foreground">
                        Write once, choose where it goes and when, and let it
                        publish on time. Built for small teams and for the tools
                        they already use.
                    </p>

                    <div className="mt-8 flex flex-wrap gap-3">
                        <Button asChild size="lg">
                            {auth.user ? (
                                <Link href={dashboard()}>Open dashboard</Link>
                            ) : (
                                <Link href={login()}>Log in</Link>
                            )}
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <a href="/docs/api">API docs</a>
                        </Button>
                    </div>

                    <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {features.map(({ icon: Icon, title, text }) => (
                            <div
                                key={title}
                                className="rounded-xl border border-border p-5"
                            >
                                <div className="mb-3 flex size-9 items-center justify-center rounded-lg bg-violet-600/10 text-violet-700 dark:text-violet-300">
                                    <Icon className="size-5" />
                                </div>
                                <p className="font-medium">{title}</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {text}
                                </p>
                            </div>
                        ))}
                    </div>
                </main>

                <footer className="mx-auto w-full max-w-5xl px-6 py-6 text-sm text-muted-foreground">
                    © {new Date().getFullYear()} {name}
                </footer>
            </div>
        </>
    );
}
