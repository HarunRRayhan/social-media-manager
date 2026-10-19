import { Form, Head, router } from '@inertiajs/react';
import { MoreHorizontal, Pencil, Power, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import CommentAutomationController from '@/actions/App/Http/Controllers/Team/CommentAutomationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PlatformIcon from '@/components/platform-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { automationTypeLabel, automationTypes } from '@/lib/automation-types';
import type { AutomationTypeKey } from '@/lib/automation-types';
import { platforms } from '@/lib/platforms';
import type { PlatformKey } from '@/lib/platforms';
import { index } from '@/routes/automations';

type Account = { id: number; provider: 'facebook' | 'instagram'; name: string };

type Run = {
    id: number;
    comment_id: string;
    status: 'pending' | 'sent' | 'failed' | 'skipped';
    error: string | null;
    created_at: string | null;
};

type PublishedPost = {
    remote_id: string;
    social_account_id: number;
    provider: Account['provider'];
    account: string;
    excerpt: string;
    published_at: string | null;
};

type Automation = {
    id: number;
    type: AutomationTypeKey;
    social_account_ids: number[];
    name: string;
    post_remote_ids: string[] | null;
    keywords: string[];
    dm_message: string;
    public_reply: string | null;
    active: boolean;
    accounts: Account[];
    runs: Run[];
};

type Props = {
    automations: Automation[];
    accounts: Account[];
    posts: PublishedPost[];
    canManage: boolean;
};

const platformLabel = (provider: PlatformKey) =>
    platforms.find((platform) => platform.key === provider)?.label ?? provider;

const statusVariant = {
    sent: 'default',
    pending: 'secondary',
    skipped: 'outline',
    failed: 'destructive',
} as const;

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const payload = (automation: Automation) => ({
    ...automation,
    accounts: undefined,
    runs: undefined,
});

const toggle = <T,>(list: T[], item: T, on: boolean) =>
    on ? [...list, item] : list.filter((other) => other !== item);

function AccountChecklist({
    accounts,
    selected,
    onToggle,
    idPrefix,
}: {
    accounts: Account[];
    selected: number[];
    onToggle: (id: number, on: boolean) => void;
    idPrefix: string;
}) {
    const groups = platforms
        .map((platform) => ({
            platform,
            accounts: accounts.filter(
                (account) => account.provider === platform.key,
            ),
        }))
        .filter((group) => group.accounts.length > 0);

    return (
        <div className="max-h-60 overflow-y-auto rounded-lg border border-border">
            {groups.map(({ platform, accounts }) => (
                <div key={platform.key} className="border-b last:border-b-0">
                    <p className="flex items-center gap-2 bg-muted/50 px-3 py-2 text-xs font-medium text-muted-foreground">
                        <PlatformIcon
                            platform={platform.key}
                            className="size-5 rounded-md"
                        />
                        {platform.label}
                    </p>
                    {accounts.map((account) => {
                        const id = `${idPrefix}-account-${account.id}`;

                        return (
                            <Label
                                key={account.id}
                                htmlFor={id}
                                className="flex cursor-pointer items-center gap-3 border-t px-3 py-2.5 font-normal"
                            >
                                <Checkbox
                                    id={id}
                                    checked={selected.includes(account.id)}
                                    onCheckedChange={(on) =>
                                        onToggle(account.id, on === true)
                                    }
                                />
                                <span className="truncate">{account.name}</span>
                            </Label>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

function PostChecklist({
    posts,
    selected,
    onToggle,
    idPrefix,
}: {
    posts: PublishedPost[];
    selected: string[];
    onToggle: (id: string, on: boolean) => void;
    idPrefix: string;
}) {
    if (posts.length === 0) {
        return (
            <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                No published posts on the picked accounts yet.
            </p>
        );
    }

    return (
        <div className="max-h-60 overflow-y-auto rounded-lg border border-border">
            {posts.map((post) => {
                const id = `${idPrefix}-post-${post.remote_id}`;

                return (
                    <Label
                        key={post.remote_id}
                        htmlFor={id}
                        className="flex cursor-pointer items-center gap-3 border-b px-3 py-2.5 font-normal last:border-b-0"
                    >
                        <Checkbox
                            id={id}
                            checked={selected.includes(post.remote_id)}
                            onCheckedChange={(on) =>
                                onToggle(post.remote_id, on === true)
                            }
                        />
                        <PlatformIcon
                            platform={post.provider}
                            className="size-7 rounded-lg"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm">
                                {post.excerpt || 'No caption'}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {post.account}
                                {post.published_at && ` · ${post.published_at}`}
                            </p>
                        </div>
                    </Label>
                );
            })}
        </div>
    );
}

function TypePicker({ onPick }: { onPick: (type: AutomationTypeKey) => void }) {
    return (
        <div className="grid gap-2">
            {automationTypes.map((type) => (
                <button
                    key={type.key}
                    type="button"
                    onClick={() => onPick(type.key)}
                    className="grid gap-2 rounded-lg border p-4 text-left transition-colors outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span className="font-medium">{type.label}</span>
                    <span className="text-sm text-muted-foreground">
                        {type.description}
                    </span>
                    <span className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        {type.platforms.map((platform) => (
                            <PlatformIcon
                                key={platform}
                                platform={platform}
                                className="size-5 rounded"
                            />
                        ))}
                        {type.platforms.map(platformLabel).join(', ')}
                        {type.soon.length > 0 &&
                            ` · ${type.soon.map(platformLabel).join(', ')} soon`}
                    </span>
                </button>
            ))}
            <p className="text-center text-xs text-muted-foreground">
                More automation types are coming.
            </p>
        </div>
    );
}

const statusDots = {
    active: { label: 'Active', dot: 'bg-green-500' },
    disabled: { label: 'Disabled', dot: 'bg-gray-400' },
    warning: { label: 'Warning', dot: 'bg-yellow-500' },
    error: { label: 'Error', dot: 'bg-red-500' },
};

// The latest run decides: failed is an error, skipped is a warning.
function automationStatus(automation: Automation) {
    const latest = automation.runs[0];

    if (!automation.active) {
        return { ...statusDots.disabled, detail: null };
    }

    if (latest?.status === 'failed') {
        return { ...statusDots.error, detail: latest.error };
    }

    if (latest?.status === 'skipped') {
        return { ...statusDots.warning, detail: latest.error };
    }

    return { ...statusDots.active, detail: null };
}

function StatusDot({ automation }: { automation: Automation }) {
    const status = automationStatus(automation);
    const badge = (
        <span className="inline-flex items-center gap-2 whitespace-nowrap">
            <span aria-hidden className={`size-2 rounded-full ${status.dot}`} />
            {status.label}
        </span>
    );

    if (!status.detail) {
        return badge;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="outline-none">
                    {badge}
                </span>
            </TooltipTrigger>
            <TooltipContent>{status.detail}</TooltipContent>
        </Tooltip>
    );
}

function RowActions({
    automation,
    accounts,
    posts,
}: {
    automation: Automation;
    accounts: Account[];
    posts: PublishedPost[];
}) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm">
                        <MoreHorizontal className="h-4 w-4" />
                        <span className="sr-only">Actions</span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setEditing(true)}>
                        <Pencil />
                        Edit
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() =>
                            router.put(
                                CommentAutomationController.update.url(
                                    automation.id,
                                ),
                                {
                                    ...payload(automation),
                                    active: !automation.active,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Power />
                        {automation.active ? 'Deactivate' : 'Activate'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setDeleting(true)}
                    >
                        <Trash2 />
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <AutomationDialog
                open={editing}
                onOpenChange={setEditing}
                title="Edit automation"
                description="Comments already answered are not answered again."
                accounts={accounts}
                posts={posts}
                automation={automation}
            />

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <DialogTitle>Delete "{automation.name}"?</DialogTitle>
                    <DialogDescription>
                        Its run history is deleted too.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    CommentAutomationController.destroy.url(
                                        automation.id,
                                    ),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function AutomationDialog({
    trigger,
    open: controlledOpen,
    onOpenChange,
    title,
    description,
    ...form
}: {
    trigger?: React.ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description: string;
    accounts: Account[];
    posts: PublishedPost[];
    automation?: Automation;
}) {
    const [ownOpen, setOwnOpen] = useState(false);
    const open = controlledOpen ?? ownOpen;
    const setOpen = onOpenChange ?? setOwnOpen;
    // A new automation starts with its type; an existing one keeps its own.
    const [type, setType] = useState<AutomationTypeKey | null>(
        form.automation?.type ?? null,
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next && !form.automation) {
                    setType(null);
                }
            }}
        >
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                {type === null ? (
                    <>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>
                            Choose what this automation does.
                        </DialogDescription>
                        <TypePicker onPick={setType} />
                    </>
                ) : (
                    <>
                        <DialogTitle>
                            {form.automation
                                ? title
                                : `New ${automationTypeLabel(type)} automation`}
                        </DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                        <AutomationForm
                            {...form}
                            type={type}
                            onSaved={() => {
                                setOpen(false);

                                if (!form.automation) {
                                    setType(null);
                                }
                            }}
                        />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function AutomationForm({
    accounts,
    posts,
    automation,
    type,
    onSaved,
}: {
    accounts: Account[];
    posts: PublishedPost[];
    automation?: Automation;
    type: AutomationTypeKey;
    onSaved: () => void;
}) {
    const action = automation
        ? CommentAutomationController.update.form(automation.id)
        : CommentAutomationController.store.form();
    const key = automation?.id ?? 'new';
    const [accountIds, setAccountIds] = useState(
        automation?.social_account_ids ?? [],
    );
    const [postIds, setPostIds] = useState(automation?.post_remote_ids ?? []);
    const [scoped, setScoped] = useState(postIds.length > 0);
    const visiblePosts = posts.filter((post) =>
        accountIds.includes(post.social_account_id),
    );
    // A saved post on an unpicked account drops out; one older than the list stays.
    const keptPostIds = postIds.filter(
        (id) =>
            visiblePosts.some((post) => post.remote_id === id) ||
            !posts.some((post) => post.remote_id === id),
    );

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            transform={(data) => ({
                ...data,
                type,
                keywords: String(data.keywords ?? '')
                    .split(',')
                    .map((keyword) => keyword.trim())
                    .filter(Boolean),
                social_account_ids: accountIds,
                post_remote_ids: scoped ? keptPostIds : null,
                active: automation?.active ?? true,
            })}
            onSuccess={() => {
                onSaved();

                if (!automation) {
                    setAccountIds([]);
                    setPostIds([]);
                    setScoped(false);
                }
            }}
            resetOnSuccess={!automation}
            className="space-y-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`name-${key}`}>Name</Label>
                        <Input
                            id={`name-${key}`}
                            name="name"
                            defaultValue={automation?.name}
                            placeholder="e.g. Send the guide"
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Accounts</Label>
                        <AccountChecklist
                            accounts={accounts}
                            selected={accountIds}
                            onToggle={(id, on) =>
                                setAccountIds(toggle(accountIds, id, on))
                            }
                            idPrefix={String(key)}
                        />
                        <InputError
                            message={
                                errors.social_account_ids ??
                                Object.entries(errors).find(([field]) =>
                                    field.startsWith('social_account_ids.'),
                                )?.[1]
                            }
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label>Posts</Label>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={scoped ? 'outline' : 'default'}
                                onClick={() => setScoped(false)}
                            >
                                All posts
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={scoped ? 'default' : 'outline'}
                                onClick={() => setScoped(true)}
                            >
                                Pick posts
                            </Button>
                        </div>
                        {scoped && (
                            <PostChecklist
                                posts={visiblePosts}
                                selected={postIds}
                                onToggle={(id, on) =>
                                    setPostIds(toggle(postIds, id, on))
                                }
                                idPrefix={String(key)}
                            />
                        )}
                        <InputError message={errors.post_remote_ids} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor={`keywords-${key}`}>Keywords</Label>
                        <Input
                            id={`keywords-${key}`}
                            name="keywords"
                            defaultValue={automation?.keywords.join(', ')}
                            placeholder="guide, link"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Comma separated. A comment containing any of them,
                            in any case, gets the DM.
                        </p>
                        <InputError
                            message={errors.keywords ?? errors['keywords.0']}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor={`dm-${key}`}>DM message</Label>
                        <textarea
                            id={`dm-${key}`}
                            name="dm_message"
                            defaultValue={automation?.dm_message}
                            className={textareaClass}
                            required
                        />
                        <InputError message={errors.dm_message} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor={`reply-${key}`}>
                            Public reply (optional)
                        </Label>
                        <textarea
                            id={`reply-${key}`}
                            name="public_reply"
                            defaultValue={automation?.public_reply ?? ''}
                            className={textareaClass}
                            placeholder="e.g. Sent you a DM!"
                        />
                        <InputError message={errors.public_reply} />
                    </div>

                    <Button disabled={processing}>
                        {automation ? 'Save' : 'Create automation'}
                    </Button>
                </>
            )}
        </Form>
    );
}

export default function Automations({
    automations,
    accounts,
    posts,
    canManage,
}: Props) {
    return (
        <>
            <Head title="Automations" />

            <h1 className="sr-only">Automations</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Automations"
                    description="Automations that run on your connected accounts"
                />

                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <AutomationDialog
                            trigger={
                                <Button disabled={accounts.length === 0}>
                                    New automation
                                </Button>
                            }
                            title="New automation"
                            description="Each comment gets the DM at most once."
                            accounts={accounts}
                            posts={posts}
                        />
                        <Button
                            variant="outline"
                            disabled={accounts.length === 0}
                            onClick={() =>
                                router.post(
                                    CommentAutomationController.webhooks.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Check webhooks
                        </Button>
                    </div>
                )}

                {automations.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {accounts.length === 0
                            ? 'Connect a Facebook Page or Instagram account first.'
                            : 'No automations yet.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Keywords
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Accounts
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Posts
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {automations.map((automation) => (
                                    <Fragment key={automation.id}>
                                        <tr className="border-t align-middle">
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {automation.keywords.map(
                                                        (keyword) => (
                                                            <Badge
                                                                key={keyword}
                                                                className="text-sm"
                                                            >
                                                                {keyword}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {automation.name}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                {automationTypeLabel(
                                                    automation.type,
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1">
                                                    {automation.accounts.map(
                                                        (account) => (
                                                            <Tooltip
                                                                key={account.id}
                                                            >
                                                                <TooltipTrigger
                                                                    asChild
                                                                >
                                                                    <span
                                                                        role="img"
                                                                        tabIndex={
                                                                            0
                                                                        }
                                                                        aria-label={`${account.name} (${platformLabel(account.provider)})`}
                                                                        className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                                    >
                                                                        <PlatformIcon
                                                                            platform={
                                                                                account.provider
                                                                            }
                                                                            className="size-6 rounded-md"
                                                                        />
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    {
                                                                        account.name
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {platformLabel(
                                                                        account.provider,
                                                                    )}
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        ),
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                {automation.post_remote_ids
                                                    ? `${automation.post_remote_ids.length} ${automation.post_remote_ids.length === 1 ? 'post' : 'posts'}`
                                                    : 'All posts'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusDot
                                                    automation={automation}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end">
                                                    {canManage && (
                                                        <RowActions
                                                            automation={
                                                                automation
                                                            }
                                                            accounts={accounts}
                                                            posts={posts}
                                                        />
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                        {automation.runs.length > 0 && (
                                            <tr>
                                                <td
                                                    colSpan={7}
                                                    className="px-4 pb-3"
                                                >
                                                    <div className="space-y-1 text-sm">
                                                        <p className="text-xs font-medium text-muted-foreground uppercase">
                                                            Recent runs
                                                        </p>
                                                        {automation.runs.map(
                                                            (run) => (
                                                                <div
                                                                    key={run.id}
                                                                    className="flex flex-wrap items-center gap-2"
                                                                >
                                                                    <Badge
                                                                        variant={
                                                                            statusVariant[
                                                                                run
                                                                                    .status
                                                                            ]
                                                                        }
                                                                    >
                                                                        {
                                                                            run.status
                                                                        }
                                                                    </Badge>
                                                                    <span className="text-muted-foreground">
                                                                        {
                                                                            run.created_at
                                                                        }{' '}
                                                                        ·
                                                                        comment{' '}
                                                                        {
                                                                            run.comment_id
                                                                        }
                                                                    </span>
                                                                    {run.error && (
                                                                        <span className="text-destructive">
                                                                            {
                                                                                run.error
                                                                            }
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

Automations.layout = {
    breadcrumbs: [
        {
            title: 'Automations',
            href: index(),
        },
    ],
};
