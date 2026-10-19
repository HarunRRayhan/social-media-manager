import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import CommentAutomationController from '@/actions/App/Http/Controllers/Team/CommentAutomationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PlatformIcon from '@/components/platform-icon';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { platforms } from '@/lib/platforms';
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

const platformLabel = (provider: Account['provider']) =>
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

function AutomationForm({
    accounts,
    posts,
    automation,
}: {
    accounts: Account[];
    posts: PublishedPost[];
    automation?: Automation;
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
                keywords: String(data.keywords ?? '')
                    .split(',')
                    .map((keyword) => keyword.trim())
                    .filter(Boolean),
                social_account_ids: accountIds,
                post_remote_ids: scoped ? keptPostIds : null,
                active: automation?.active ?? true,
            })}
            onSuccess={() => {
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
                    description="Send a private reply to everyone who comments a keyword on a Page or Instagram post"
                />

                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button disabled={accounts.length === 0}>
                                    New automation
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                                <DialogTitle>New automation</DialogTitle>
                                <DialogDescription>
                                    Each comment gets the DM at most once.
                                </DialogDescription>
                                <AutomationForm
                                    accounts={accounts}
                                    posts={posts}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>
                )}

                {automations.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {accounts.length === 0
                            ? 'Connect a Facebook Page or Instagram account first.'
                            : 'No automations yet.'}
                    </p>
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border">
                        {automations.map((automation) => (
                            <div
                                key={automation.id}
                                className="space-y-3 border-b p-4 last:border-b-0"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div className="space-y-1">
                                        <p className="font-medium tracking-tight">
                                            {automation.name}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                                            {automation.accounts.map(
                                                (account) => (
                                                    <span
                                                        key={account.id}
                                                        className="flex items-center gap-1.5"
                                                        title={platformLabel(
                                                            account.provider,
                                                        )}
                                                    >
                                                        <PlatformIcon
                                                            platform={
                                                                account.provider
                                                            }
                                                            className="size-5 rounded-md"
                                                        />
                                                        {account.name}
                                                    </span>
                                                ),
                                            )}
                                            <span>
                                                ·{' '}
                                                {automation.post_remote_ids
                                                    ? `${automation.post_remote_ids.length} ${automation.post_remote_ids.length === 1 ? 'post' : 'posts'}`
                                                    : 'All posts'}
                                            </span>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {automation.keywords.map(
                                                (keyword) => (
                                                    <Badge
                                                        key={keyword}
                                                        variant="outline"
                                                    >
                                                        {keyword}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-1">
                                        <div className="mr-2 flex items-center gap-2">
                                            <Checkbox
                                                id={`active-${automation.id}`}
                                                checked={automation.active}
                                                disabled={!canManage}
                                                onCheckedChange={(on) =>
                                                    router.put(
                                                        CommentAutomationController.update.url(
                                                            automation.id,
                                                        ),
                                                        {
                                                            ...payload(
                                                                automation,
                                                            ),
                                                            active: on === true,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={`active-${automation.id}`}
                                                className="font-normal"
                                            >
                                                Active
                                            </Label>
                                        </div>

                                        {canManage && (
                                            <>
                                                <Dialog>
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                            <span className="sr-only">
                                                                Edit
                                                            </span>
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                                                        <DialogTitle>
                                                            Edit automation
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Comments already
                                                            answered are not
                                                            answered again.
                                                        </DialogDescription>
                                                        <AutomationForm
                                                            accounts={accounts}
                                                            posts={posts}
                                                            automation={
                                                                automation
                                                            }
                                                        />
                                                    </DialogContent>
                                                </Dialog>

                                                <Dialog>
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                            <span className="sr-only">
                                                                Delete
                                                            </span>
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogTitle>
                                                            Delete "
                                                            {automation.name}"?
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Its run history is
                                                            deleted too.
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
                                                                        CommentAutomationController.destroy.url(
                                                                            automation.id,
                                                                        ),
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Delete
                                                            </Button>
                                                        </DialogFooter>
                                                    </DialogContent>
                                                </Dialog>
                                            </>
                                        )}
                                    </div>
                                </div>

                                {automation.runs.length > 0 && (
                                    <div className="space-y-1 text-sm">
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
                                            Recent runs
                                        </p>
                                        {automation.runs.map((run) => (
                                            <div
                                                key={run.id}
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                <Badge
                                                    variant={
                                                        statusVariant[
                                                            run.status
                                                        ]
                                                    }
                                                >
                                                    {run.status}
                                                </Badge>
                                                <span className="text-muted-foreground">
                                                    {run.created_at} · comment{' '}
                                                    {run.comment_id}
                                                </span>
                                                {run.error && (
                                                    <span className="text-destructive">
                                                        {run.error}
                                                    </span>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
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
