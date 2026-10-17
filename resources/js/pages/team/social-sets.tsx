import { Form, Head, router } from '@inertiajs/react';
import { Layers, Pencil, Trash2 } from 'lucide-react';
import SocialSetController from '@/actions/App/Http/Controllers/Team/SocialSetController';
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
import { index } from '@/routes/social-sets';

type SocialSet = {
    id: number;
    name: string;
    posts_count: number;
};

type Props = {
    socialSets: SocialSet[];
    canManage: boolean;
};

export default function SocialSets({ socialSets, canManage }: Props) {
    const isLast = socialSets.length === 1;

    return (
        <>
            <Head title="Social sets" />

            <h1 className="sr-only">Social sets</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Social sets"
                    description="Group the team's posts into sets; an API token can be limited to one set"
                />

                {canManage && (
                    <Form
                        {...SocialSetController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="flex items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="name">New set</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="e.g. Client work"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <Button disabled={processing}>
                                    Create set
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                <div className="overflow-hidden rounded-lg border border-border">
                    {socialSets.map((set) => (
                        <div
                            key={set.id}
                            className="flex items-center justify-between border-b p-4 last:border-b-0"
                        >
                            <div className="flex items-center gap-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                    <Layers className="h-5 w-5 text-muted-foreground" />
                                </div>

                                <div className="space-y-1">
                                    <p className="font-medium tracking-tight">
                                        {set.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {set.posts_count === 1
                                            ? '1 post'
                                            : `${set.posts_count} posts`}
                                    </p>
                                </div>
                            </div>

                            {canManage && (
                                <div className="flex items-center gap-1">
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button variant="ghost" size="sm">
                                                <Pencil className="h-4 w-4" />
                                                <span className="sr-only">
                                                    Rename
                                                </span>
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogTitle>
                                                Rename set
                                            </DialogTitle>
                                            <DialogDescription>
                                                Posts and tokens in the set keep
                                                working.
                                            </DialogDescription>
                                            <Form
                                                {...SocialSetController.update.form(
                                                    set.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="space-y-6"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <div className="grid gap-2">
                                                            <Label
                                                                htmlFor={`name-${set.id}`}
                                                            >
                                                                Name
                                                            </Label>
                                                            <Input
                                                                id={`name-${set.id}`}
                                                                name="name"
                                                                defaultValue={
                                                                    set.name
                                                                }
                                                                required
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.name
                                                                }
                                                            />
                                                        </div>
                                                        <DialogFooter className="gap-2">
                                                            <DialogClose
                                                                asChild
                                                            >
                                                                <Button variant="secondary">
                                                                    Cancel
                                                                </Button>
                                                            </DialogClose>
                                                            <Button
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                        </DialogFooter>
                                                    </>
                                                )}
                                            </Form>
                                        </DialogContent>
                                    </Dialog>

                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={isLast}
                                                title={
                                                    isLast
                                                        ? 'A team needs at least one social set'
                                                        : undefined
                                                }
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
                                                Delete "{set.name}"?
                                            </DialogTitle>
                                            <DialogDescription>
                                                Its {set.posts_count} post(s)
                                                are deleted, and API tokens
                                                limited to this set are revoked.
                                                This cannot be undone.
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
                                                            SocialSetController.destroy.url(
                                                                set.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Delete set
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

SocialSets.layout = {
    breadcrumbs: [
        {
            title: 'Social sets',
            href: index(),
        },
    ],
};
