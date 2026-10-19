import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import ConnectionController from '@/actions/App/Http/Controllers/Team/ConnectionController';
import MetaConnectionController from '@/actions/App/Http/Controllers/Team/MetaConnectionController';
import Heading from '@/components/heading';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/connections';

type Account = {
    id: number;
    provider: 'facebook' | 'instagram';
    name: string;
    username: string | null;
    avatar_url: string | null;
    social_set_ids: number[];
};

type Props = {
    accounts: Account[];
    socialSets: { id: number; name: string }[];
    canManage: boolean;
    metaConfigured: boolean;
};

const providerLabel = { facebook: 'Facebook Page', instagram: 'Instagram' };

export default function Connections({
    accounts,
    socialSets,
    canManage,
    metaConfigured,
}: Props) {
    const toggleSet = (account: Account, setId: number, on: boolean) =>
        router.put(
            ConnectionController.update.url(account.id),
            {
                social_set_ids: on
                    ? [...account.social_set_ids, setId]
                    : account.social_set_ids.filter((id) => id !== setId),
            },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Connections" />

            <h1 className="sr-only">Connections</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Connections"
                    description="Accounts the team publishes to; a post goes to every account of its platform in its social set"
                />

                {canManage &&
                    (metaConfigured ? (
                        <Button asChild>
                            <a href={MetaConnectionController.redirect.url()}>
                                Connect Facebook & Instagram
                            </a>
                        </Button>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Facebook and Instagram can be connected once
                            META_APP_ID and META_APP_SECRET are configured.
                        </p>
                    ))}

                {accounts.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        No accounts connected yet.
                    </p>
                ) : (
                    (
                        [
                            ['facebook', 'Facebook Pages'],
                            ['instagram', 'Instagram'],
                        ] as const
                    ).map(([provider, title]) => {
                        const group = accounts.filter(
                            (account) => account.provider === provider,
                        );

                        return (
                            group.length > 0 && (
                                <section key={provider} className="space-y-3">
                                    <h2 className="text-sm font-medium">
                                        {title}
                                    </h2>
                                    <div className="overflow-hidden rounded-lg border border-border">
                                        {group.map((account) => (
                                            <div
                                                key={account.id}
                                                className="space-y-3 border-b p-4 last:border-b-0"
                                            >
                                                <div className="flex items-center justify-between gap-4">
                                                    <div className="flex items-center gap-4">
                                                        <Avatar className="h-10 w-10">
                                                            <AvatarImage
                                                                src={
                                                                    account.avatar_url ??
                                                                    undefined
                                                                }
                                                                alt=""
                                                            />
                                                            <AvatarFallback>
                                                                {account.name
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                            </AvatarFallback>
                                                        </Avatar>

                                                        <div className="space-y-1">
                                                            <p className="font-medium tracking-tight">
                                                                {account.name}
                                                            </p>
                                                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                                <Badge variant="secondary">
                                                                    {
                                                                        providerLabel[
                                                                            account
                                                                                .provider
                                                                        ]
                                                                    }
                                                                </Badge>
                                                                {account.username && (
                                                                    <span>
                                                                        @
                                                                        {
                                                                            account.username
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {canManage && (
                                                        <Dialog>
                                                            <DialogTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                    <span className="sr-only">
                                                                        Disconnect
                                                                    </span>
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent>
                                                                <DialogTitle>
                                                                    Disconnect "
                                                                    {
                                                                        account.name
                                                                    }
                                                                    "?
                                                                </DialogTitle>
                                                                <DialogDescription>
                                                                    Scheduled
                                                                    posts in its
                                                                    social sets
                                                                    will no
                                                                    longer go to
                                                                    this
                                                                    account. You
                                                                    can connect
                                                                    it again
                                                                    later.
                                                                </DialogDescription>
                                                                <DialogFooter className="gap-2">
                                                                    <DialogClose
                                                                        asChild
                                                                    >
                                                                        <Button variant="secondary">
                                                                            Cancel
                                                                        </Button>
                                                                    </DialogClose>
                                                                    <Button
                                                                        variant="destructive"
                                                                        onClick={() =>
                                                                            router.delete(
                                                                                ConnectionController.destroy.url(
                                                                                    account.id,
                                                                                ),
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        Disconnect
                                                                    </Button>
                                                                </DialogFooter>
                                                            </DialogContent>
                                                        </Dialog>
                                                    )}
                                                </div>

                                                <div className="flex flex-wrap gap-x-4 gap-y-2 pl-14">
                                                    {socialSets.map((set) => {
                                                        const id = `account-${account.id}-set-${set.id}`;

                                                        return (
                                                            <div
                                                                key={set.id}
                                                                className="flex items-center gap-2"
                                                            >
                                                                <Checkbox
                                                                    id={id}
                                                                    checked={account.social_set_ids.includes(
                                                                        set.id,
                                                                    )}
                                                                    disabled={
                                                                        !canManage
                                                                    }
                                                                    onCheckedChange={(
                                                                        on,
                                                                    ) =>
                                                                        toggleSet(
                                                                            account,
                                                                            set.id,
                                                                            on ===
                                                                                true,
                                                                        )
                                                                    }
                                                                />
                                                                <Label
                                                                    htmlFor={id}
                                                                    className="font-normal"
                                                                >
                                                                    {set.name}
                                                                </Label>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            )
                        );
                    })
                )}
            </div>
        </>
    );
}

Connections.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: index(),
        },
    ],
};
