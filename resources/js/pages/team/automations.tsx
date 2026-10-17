import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import CommentAutomationController from '@/actions/App/Http/Controllers/Team/CommentAutomationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/automations';

type Account = { id: number; provider: 'facebook' | 'instagram'; name: string };

type Run = {
    id: number;
    comment_id: string;
    status: 'pending' | 'sent' | 'failed' | 'skipped';
    error: string | null;
    created_at: string | null;
};

type Automation = {
    id: number;
    social_account_id: number;
    name: string;
    post_remote_id: string | null;
    keywords: string[];
    dm_message: string;
    public_reply: string | null;
    active: boolean;
    account: Pick<Account, 'provider' | 'name'>;
    runs: Run[];
};

type Props = {
    automations: Automation[];
    accounts: Account[];
    canManage: boolean;
};

const providerLabel = { facebook: 'Facebook Page', instagram: 'Instagram' };

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
    account: undefined,
    runs: undefined,
});

function AutomationForm({
    accounts,
    automation,
}: {
    accounts: Account[];
    automation?: Automation;
}) {
    const action = automation
        ? CommentAutomationController.update.form(automation.id)
        : CommentAutomationController.store.form();
    const key = automation?.id ?? 'new';

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
                active: automation?.active ?? true,
            })}
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
                        <Label htmlFor={`account-${key}`}>Account</Label>
                        <Select
                            name="social_account_id"
                            defaultValue={automation?.social_account_id.toString()}
                        >
                            <SelectTrigger id={`account-${key}`}>
                                <SelectValue placeholder="Pick an account" />
                            </SelectTrigger>
                            <SelectContent>
                                {accounts.map((account) => (
                                    <SelectItem
                                        key={account.id}
                                        value={account.id.toString()}
                                    >
                                        {account.name} (
                                        {providerLabel[account.provider]})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.social_account_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor={`post-${key}`}>
                            Post ID (optional)
                        </Label>
                        <Input
                            id={`post-${key}`}
                            name="post_remote_id"
                            defaultValue={automation?.post_remote_id ?? ''}
                            placeholder="Empty: every post of the account"
                        />
                        <InputError message={errors.post_remote_id} />
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
                            <DialogContent>
                                <DialogTitle>New automation</DialogTitle>
                                <DialogDescription>
                                    Each comment gets the DM at most once.
                                </DialogDescription>
                                <AutomationForm accounts={accounts} />
                            </DialogContent>
                        </Dialog>

                        <Button
                            variant="secondary"
                            disabled={accounts.length === 0}
                            onClick={() =>
                                router.post(
                                    CommentAutomationController.subscribe.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Enable comment automation
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
                                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                            <Badge variant="secondary">
                                                {
                                                    providerLabel[
                                                        automation.account
                                                            .provider
                                                    ]
                                                }
                                            </Badge>
                                            <span>
                                                {automation.account.name}
                                            </span>
                                            <span>
                                                {automation.post_remote_id
                                                    ? `post ${automation.post_remote_id}`
                                                    : 'every post'}
                                            </span>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Keywords:{' '}
                                            {automation.keywords.join(', ')}
                                        </p>
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
                                                    <DialogContent>
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
