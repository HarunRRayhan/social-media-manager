import { Form, Head, router } from '@inertiajs/react';
import { ChevronDown, Plug, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type React from 'react';
import BlueskyConnectionController from '@/actions/App/Http/Controllers/Team/BlueskyConnectionController';
import ConnectionController from '@/actions/App/Http/Controllers/Team/ConnectionController';
import MetaConnectionController from '@/actions/App/Http/Controllers/Team/MetaConnectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PlatformIcon from '@/components/platform-icon';
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
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { metaPlatforms, platforms } from '@/lib/platforms';
import type { PlatformKey } from '@/lib/platforms';
import { index } from '@/routes/connections';

type Account = {
    id: number;
    provider: PlatformKey;
    name: string;
    username: string | null;
    avatar_url: string | null;
    social_set_ids: number[];
    reconnect: boolean;
};

type Candidate = {
    id: string;
    name: string;
    username?: string | null;
    avatar_url: string | null;
    connected: boolean;
};

type Props = {
    accounts: Account[];
    socialSets: { id: number; name: string }[];
    canManage: boolean;
    metaConfigured: boolean;
    threadsConfigured: boolean;
    linkedinConfigured: boolean;
    pending: { pages: Candidate[]; instagram: Candidate[] } | null;
};

function BlueskyForm({
    onBack,
    onSuccess,
}: {
    onBack: () => void;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...BlueskyConnectionController.store.form()}
            options={{ preserveScroll: true }}
            resetOnError={['password']}
            onSuccess={onSuccess}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="bluesky-handle">Handle</Label>
                        <Input
                            id="bluesky-handle"
                            name="handle"
                            required
                            autoFocus
                            autoComplete="username"
                            placeholder="you.bsky.social"
                        />
                        <InputError message={errors.handle} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="bluesky-password">App password</Label>
                        <Input
                            id="bluesky-password"
                            name="password"
                            type="password"
                            required
                            autoComplete="off"
                            placeholder="xxxx-xxxx-xxxx-xxxx"
                        />
                        <p className="text-sm text-muted-foreground">
                            Create one at{' '}
                            <a
                                href="https://bsky.app/settings/app-passwords"
                                target="_blank"
                                rel="noreferrer"
                                className="underline underline-offset-4"
                            >
                                bsky.app/settings/app-passwords
                            </a>
                            . Never use your main password.
                        </p>
                        <InputError message={errors.password} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={onBack}
                        >
                            Back
                        </Button>
                        <Button disabled={processing}>Connect Bluesky</Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function ConnectDialog({
    metaConfigured,
    threadsConfigured,
    linkedinConfigured,
}: {
    metaConfigured: boolean;
    threadsConfigured: boolean;
    linkedinConfigured: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [bluesky, setBluesky] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setBluesky(false);
            }}
        >
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Connect account
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {bluesky ? 'Connect Bluesky' : 'Connect an account'}
                    </DialogTitle>
                    <DialogDescription>
                        {bluesky
                            ? 'Sign in with your handle and an app password.'
                            : 'Facebook and Instagram connect together through one Facebook sign-in.'}
                    </DialogDescription>
                </DialogHeader>

                {bluesky ? (
                    <BlueskyForm
                        onBack={() => setBluesky(false)}
                        onSuccess={() => setOpen(false)}
                    />
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {platforms.map((platform) => {
                            const configured = metaPlatforms.includes(
                                platform.key,
                            )
                                ? metaConfigured
                                : platform.key === 'threads'
                                  ? threadsConfigured
                                  : platform.key !== 'linkedin' ||
                                    linkedinConfigured;
                            const href = configured ? platform.connect : null;
                            const body = (
                                <>
                                    <PlatformIcon platform={platform.key} />
                                    <span className="text-sm font-medium">
                                        {platform.label}
                                    </span>
                                    {!href && (
                                        <span className="text-xs text-muted-foreground">
                                            {platform.connect
                                                ? 'Not configured'
                                                : 'Coming soon'}
                                        </span>
                                    )}
                                </>
                            );
                            const card =
                                'flex flex-col items-center gap-2 rounded-xl border p-4 text-center';

                            const link = `${card} transition-colors hover:border-foreground/20 hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none`;

                            // Bluesky's URL takes a form post, so its card opens the form instead of linking.
                            if (platform.key === 'bluesky') {
                                return (
                                    <button
                                        key={platform.key}
                                        type="button"
                                        onClick={() => setBluesky(true)}
                                        className={link}
                                    >
                                        {body}
                                    </button>
                                );
                            }

                            return href ? (
                                <a
                                    key={platform.key}
                                    href={href}
                                    className={link}
                                >
                                    {body}
                                </a>
                            ) : (
                                <div
                                    key={platform.key}
                                    aria-disabled
                                    className={`${card} cursor-not-allowed opacity-50 grayscale`}
                                >
                                    {body}
                                </div>
                            );
                        })}
                    </div>
                )}

                {!metaConfigured && !bluesky && (
                    <p className="text-sm text-muted-foreground">
                        Facebook and Instagram can be connected once META_APP_ID
                        and META_APP_SECRET are configured.
                    </p>
                )}
                {!threadsConfigured && !bluesky && (
                    <p className="text-sm text-muted-foreground">
                        Threads can be connected once THREADS_APP_ID and
                        THREADS_APP_SECRET are configured.
                    </p>
                )}
                {!linkedinConfigured && !bluesky && (
                    <p className="text-sm text-muted-foreground">
                        LinkedIn can be connected once LINKEDIN_CLIENT_ID and
                        LINKEDIN_CLIENT_SECRET are configured.
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}

function CandidateList({
    field,
    candidates,
    selected,
    onToggle,
}: {
    field: 'facebook' | 'instagram';
    candidates: Candidate[];
    selected: string[];
    onToggle: (id: string, on: boolean) => void;
}) {
    return (
        <div className="max-h-80 overflow-y-auto rounded-lg border border-border">
            {candidates.map((candidate) => {
                const id = `pick-${field}-${candidate.id}`;

                return (
                    <Label
                        key={candidate.id}
                        htmlFor={id}
                        className="flex cursor-pointer items-center gap-3 border-b p-3 font-normal last:border-b-0 has-disabled:cursor-default"
                    >
                        <Checkbox
                            id={id}
                            checked={
                                candidate.connected ||
                                selected.includes(candidate.id)
                            }
                            disabled={candidate.connected}
                            onCheckedChange={(on) =>
                                onToggle(candidate.id, on === true)
                            }
                        />
                        <Avatar className="h-9 w-9">
                            <AvatarImage
                                src={candidate.avatar_url ?? undefined}
                                alt=""
                            />
                            <AvatarFallback>
                                {candidate.name.charAt(0).toUpperCase()}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <p className="truncate font-medium tracking-tight">
                                {candidate.name}
                            </p>
                            {candidate.username && (
                                <p className="truncate text-sm text-muted-foreground">
                                    @{candidate.username}
                                </p>
                            )}
                        </div>
                        {candidate.connected && (
                            <Badge variant="secondary">Connected</Badge>
                        )}
                    </Label>
                );
            })}
        </div>
    );
}

function PickDialog({ pending }: { pending: NonNullable<Props['pending']> }) {
    const [open, setOpen] = useState(true);
    const [step, setStep] = useState<'facebook' | 'instagram'>('facebook');
    const [picked, setPicked] = useState({
        facebook: [] as string[],
        instagram: [] as string[],
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const hasInstagram = pending.instagram.length > 0;
    const lastStep = step === 'instagram' || !hasInstagram;
    const toggle =
        (field: 'facebook' | 'instagram') => (id: string, on: boolean) =>
            setPicked((current) => ({
                ...current,
                [field]: on
                    ? [...current[field], id]
                    : current[field].filter((value) => value !== id),
            }));
    const connectedIds = (candidates: Candidate[]) =>
        candidates.filter((c) => c.connected).map((c) => c.id);

    // Closing without connecting drops the sign-in, so the dialog does not open again.
    const cancel = () => {
        setOpen(false);
        router.delete(MetaConnectionController.cancel.url(), {
            preserveScroll: true,
        });
    };

    // Already-connected accounts are sent too, so this sign-in refreshes their tokens.
    const submit = () =>
        router.post(
            MetaConnectionController.store.url(),
            {
                facebook: [...connectedIds(pending.pages), ...picked.facebook],
                instagram: [
                    ...connectedIds(pending.instagram),
                    ...picked.instagram,
                ],
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setErrors({});
                    setProcessing(true);
                },
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: () => setOpen(false),
            },
        );

    const count = picked.facebook.length + picked.instagram.length;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !processing && cancel()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <PlatformIcon platform={step} className="size-8" />
                        <DialogTitle>
                            {step === 'facebook'
                                ? 'Choose Facebook Pages'
                                : 'Choose Instagram accounts'}
                        </DialogTitle>
                    </div>
                    <DialogDescription>
                        {hasInstagram && (
                            <span className="font-medium text-foreground">
                                Step {step === 'facebook' ? 1 : 2} of 2.{' '}
                            </span>
                        )}
                        {step === 'facebook'
                            ? 'Pick the Pages the team should publish to.'
                            : 'Pick the Instagram accounts linked to your Pages.'}{' '}
                        Connected accounts stay connected; disconnect them from
                        this page.
                    </DialogDescription>
                </DialogHeader>

                {step === 'facebook' ? (
                    <CandidateList
                        field="facebook"
                        candidates={pending.pages}
                        selected={picked.facebook}
                        onToggle={toggle('facebook')}
                    />
                ) : (
                    <CandidateList
                        field="instagram"
                        candidates={pending.instagram}
                        selected={picked.instagram}
                        onToggle={toggle('instagram')}
                    />
                )}

                <InputError message={Object.values(errors)[0]} />

                <DialogFooter className="gap-2">
                    {step === 'instagram' ? (
                        <Button
                            variant="secondary"
                            onClick={() => setStep('facebook')}
                        >
                            Back
                        </Button>
                    ) : (
                        <Button
                            variant="secondary"
                            onClick={cancel}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                    )}
                    {lastStep ? (
                        <Button
                            onClick={submit}
                            disabled={processing || count === 0}
                        >
                            {count > 0
                                ? `Connect selected (${count})`
                                : 'Connect selected'}
                        </Button>
                    ) : (
                        <Button onClick={() => setStep('instagram')}>
                            Next
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SetPicker({
    account,
    socialSets,
    canManage,
    onToggle,
}: {
    account: Account;
    socialSets: Props['socialSets'];
    canManage: boolean;
    onToggle: (setId: number, on: boolean) => void;
}) {
    const [open, setOpen] = useState(false);
    const closeTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const hover = (next: boolean) => (e: React.PointerEvent) => {
        if (e.pointerType !== 'mouse') {
            return;
        }

        clearTimeout(closeTimer.current);

        // A short delay lets the pointer cross the gap between trigger and menu.
        if (next) {
            setOpen(true);
        } else {
            closeTimer.current = setTimeout(() => setOpen(false), 150);
        }
    };
    useEffect(() => () => clearTimeout(closeTimer.current), []);
    const inSets = socialSets.filter((set) =>
        account.social_set_ids.includes(set.id),
    );
    const shown = inSets.slice(0, 2);
    const rest = inSets.length - shown.length;

    // modal={false} so hovering off the trigger onto the menu does not fight a focus trap.
    return (
        <DropdownMenu open={open} onOpenChange={setOpen} modal={false}>
            <div onPointerEnter={hover(true)} onPointerLeave={hover(false)}>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        // Hover already opened it, so a mouse click must not toggle it shut.
                        onPointerDown={(e) =>
                            e.pointerType === 'mouse' &&
                            open &&
                            e.preventDefault()
                        }
                        aria-label={`Social sets for ${account.name}: ${
                            inSets.map((set) => set.name).join(', ') || 'none'
                        }`}
                        className="flex items-center gap-1.5 rounded-md px-2 py-1 transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        {shown.length === 0 ? (
                            <span className="text-sm text-muted-foreground">
                                No sets
                            </span>
                        ) : (
                            shown.map((set) => (
                                <Badge key={set.id} variant="secondary">
                                    {set.name}
                                </Badge>
                            ))
                        )}
                        {rest > 0 && <Badge variant="outline">+{rest}</Badge>}
                        <ChevronDown className="size-4 text-muted-foreground" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-48">
                    <DropdownMenuLabel>Social sets</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {socialSets.map((set) => (
                        <DropdownMenuCheckboxItem
                            key={set.id}
                            checked={account.social_set_ids.includes(set.id)}
                            disabled={!canManage}
                            onCheckedChange={(on) => onToggle(set.id, on)}
                            // Keep the menu open so several sets can be toggled in a row.
                            onSelect={(e) => e.preventDefault()}
                        >
                            {set.name}
                        </DropdownMenuCheckboxItem>
                    ))}
                </DropdownMenuContent>
            </div>
        </DropdownMenu>
    );
}

function AccountRow({
    account,
    socialSets,
    canManage,
}: {
    account: Account;
    socialSets: Props['socialSets'];
    canManage: boolean;
}) {
    // Optimistic ids, so a second toggle before the first save returns builds on the first.
    const [setIds, setSetIds] = useState(account.social_set_ids);
    const ids = useRef(setIds);
    useEffect(() => {
        ids.current = account.social_set_ids;
        setSetIds(account.social_set_ids);
    }, [account.social_set_ids]);

    const toggleSet = (setId: number, on: boolean) => {
        ids.current = on
            ? [...ids.current, setId]
            : ids.current.filter((id) => id !== setId);
        setSetIds(ids.current);
        router.put(
            ConnectionController.update.url(account.id),
            { social_set_ids: ids.current },
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-col gap-3 border-b p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 items-center gap-3">
                <Avatar className="h-10 w-10">
                    <AvatarImage src={account.avatar_url ?? undefined} alt="" />
                    <AvatarFallback>
                        {account.name.charAt(0).toUpperCase()}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <p className="truncate font-medium tracking-tight">
                        {account.name}
                    </p>
                    {account.username && (
                        <p className="truncate text-sm text-muted-foreground">
                            @{account.username}
                        </p>
                    )}
                    {account.reconnect && (
                        <Badge variant="destructive" className="mt-1">
                            Reconnect needed
                        </Badge>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-4 pl-13 sm:pl-0">
                <SetPicker
                    account={{ ...account, social_set_ids: setIds }}
                    socialSets={socialSets}
                    canManage={canManage}
                    onToggle={toggleSet}
                />

                {canManage && (
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto text-destructive hover:bg-destructive/10 hover:text-destructive"
                            >
                                <Trash2 className="h-4 w-4" />
                                <span className="sr-only">Disconnect</span>
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Disconnect "{account.name}"?
                            </DialogTitle>
                            <DialogDescription>
                                Scheduled posts in its social sets will no
                                longer go to this account. You can connect it
                                again later.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    onClick={() =>
                                        router.delete(
                                            ConnectionController.destroy.url(
                                                account.id,
                                            ),
                                            { preserveScroll: true },
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
        </div>
    );
}

export default function Connections({
    accounts,
    socialSets,
    canManage,
    metaConfigured,
    threadsConfigured,
    linkedinConfigured,
    pending,
}: Props) {
    const groups = platforms
        .map((platform) => ({
            platform,
            accounts: accounts.filter((a) => a.provider === platform.key),
        }))
        .filter((group) => group.accounts.length > 0);

    return (
        <>
            <Head title="Connections" />

            <h1 className="sr-only">Connections</h1>

            {pending && <PickDialog pending={pending} />}

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Connections"
                        description="Accounts the team publishes to; a post goes to every account of its platform in its social set"
                    />
                    {canManage && accounts.length > 0 && (
                        <ConnectDialog
                            metaConfigured={metaConfigured}
                            threadsConfigured={threadsConfigured}
                            linkedinConfigured={linkedinConfigured}
                        />
                    )}
                </div>

                {accounts.length === 0 ? (
                    <div className="flex min-h-80 flex-col items-center justify-center gap-4 rounded-xl border border-dashed p-8 text-center">
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-muted">
                            <Plug className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p className="max-w-xs text-sm text-muted-foreground">
                            {canManage
                                ? 'Connect a social account to start publishing from this team.'
                                : 'No accounts connected yet. Ask a team admin to connect one.'}
                        </p>
                        {canManage && (
                            <ConnectDialog
                                metaConfigured={metaConfigured}
                                threadsConfigured={threadsConfigured}
                                linkedinConfigured={linkedinConfigured}
                            />
                        )}
                    </div>
                ) : (
                    groups.map(({ platform, accounts: group }) => (
                        <section key={platform.key} className="space-y-3">
                            <div className="flex items-center gap-2">
                                <PlatformIcon
                                    platform={platform.key}
                                    className="size-6 rounded-md"
                                />
                                <h2 className="text-sm font-medium">
                                    {platform.label}
                                </h2>
                                <Badge variant="secondary">
                                    {group.length}
                                </Badge>
                            </div>
                            <div className="overflow-hidden rounded-lg border border-border">
                                {group.map((account) => (
                                    <AccountRow
                                        key={account.id}
                                        account={account}
                                        socialSets={socialSets}
                                        canManage={canManage}
                                    />
                                ))}
                            </div>
                        </section>
                    ))
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
