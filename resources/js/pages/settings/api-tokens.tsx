import { Form, Head, router } from '@inertiajs/react';
import { Copy, KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ApiTokenController, {
    destroy,
} from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import TextLink from '@/components/text-link';
import { edit } from '@/routes/api-tokens';

type Token = {
    id: number;
    name: string;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

type Props = {
    tokens: Token[];
    newApiToken?: string | null;
};

export default function ApiTokens({ tokens, newApiToken }: Props) {
    const [copied, setCopied] = useState(false);

    const copyToken = async () => {
        if (!newApiToken) {
            return;
        }

        await navigator.clipboard.writeText(newApiToken);
        setCopied(true);
    };

    return (
        <>
            <Head title="API token settings" />

            <h1 className="sr-only">API token settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="API tokens"
                    description="Create bearer tokens for the Social Media Manager API"
                />

                <p className="text-sm text-muted-foreground">
                    Endpoints and schemas are in the{' '}
                    <TextLink href="/docs/api">API docs</TextLink>.
                </p>

                <Form
                    {...ApiTokenController.store.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnSuccess
                    onSuccess={() => setCopied(false)}
                    className="flex items-end gap-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="name">Token name</Label>

                                <Input
                                    id="name"
                                    name="name"
                                    placeholder="e.g. Content Machine"
                                    required
                                />

                                <InputError message={errors.name} />
                            </div>

                            <Button disabled={processing}>Create token</Button>
                        </>
                    )}
                </Form>

                {newApiToken && (
                    <div className="space-y-2 rounded-lg border border-border p-4">
                        <p className="text-sm font-medium">
                            Copy this token now. It will not be shown again.
                        </p>

                        <div className="flex items-center gap-2">
                            <code className="flex-1 rounded-md bg-muted px-3 py-2 font-mono text-xs break-all">
                                {newApiToken}
                            </code>

                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={copyToken}
                            >
                                <Copy className="h-4 w-4" />
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                )}

                <div className="overflow-hidden rounded-lg border border-border">
                    {tokens.length > 0 ? (
                        tokens.map((token) => (
                            <div
                                key={token.id}
                                className="flex items-center justify-between border-b p-4 last:border-b-0"
                            >
                                <div className="flex items-center gap-4">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <KeyRound className="h-5 w-5 text-muted-foreground" />
                                    </div>

                                    <div className="space-y-1">
                                        <p className="font-medium tracking-tight">
                                            {token.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Created {token.created_at_diff}
                                            <span className="mx-1 text-muted-foreground/50">
                                                /
                                            </span>
                                            Last used{' '}
                                            {token.last_used_at_diff ?? 'never'}
                                        </p>
                                    </div>
                                </div>

                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                            <span className="sr-only">
                                                Revoke
                                            </span>
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>Revoke token</DialogTitle>
                                        <DialogDescription>
                                            Revoke the "{token.name}" token? Any
                                            client using it will stop working
                                            immediately.
                                        </DialogDescription>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    Cancel
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    router.delete(
                                                        destroy.url(token.id),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Revoke token
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        ))
                    ) : (
                        <p className="p-8 text-center text-sm text-muted-foreground">
                            No API tokens yet.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

ApiTokens.layout = {
    breadcrumbs: [
        {
            title: 'API tokens',
            href: edit(),
        },
    ],
};
