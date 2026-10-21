import { Form, Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Pencil, Trash2, X } from 'lucide-react';
import PostListController from '@/actions/App/Http/Controllers/Team/PostListController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PlatformIcon from '@/components/platform-icon';
import { Badge } from '@/components/ui/badge';
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
import type { PlatformKey } from '@/lib/platforms';
import { index } from '@/routes/lists';

type ListPost = {
    id: number;
    position: number;
    platform: PlatformKey;
    status: string;
    preview: string;
    at: string;
    social_set: string;
};

type Props = {
    list: {
        id: number;
        name: string;
        description: string | null;
        posts: ListPost[];
    };
    canManage: boolean;
};

export default function List({ list, canManage }: Props) {
    const move = (from: number, to: number) => {
        const ids = list.posts.map((post) => post.id);
        ids.splice(to, 0, ...ids.splice(from, 1));
        router.put(
            PostListController.reorder.url(list.id),
            { post_ids: ids },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={list.name} />

            <h1 className="sr-only">{list.name}</h1>

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        variant="small"
                        title={list.name}
                        description={
                            list.description ??
                            `${list.posts.length} part(s), in order`
                        }
                    />

                    {canManage && (
                        <div className="flex items-center gap-1">
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="ghost" size="sm">
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>Edit list</DialogTitle>
                                    <DialogDescription>
                                        Posts in the list keep their order.
                                    </DialogDescription>
                                    <Form
                                        {...PostListController.update.form(
                                            list.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                        className="space-y-6"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="name">
                                                        Name
                                                    </Label>
                                                    <Input
                                                        id="name"
                                                        name="name"
                                                        defaultValue={list.name}
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="description">
                                                        Description
                                                    </Label>
                                                    <Input
                                                        id="description"
                                                        name="description"
                                                        defaultValue={
                                                            list.description ??
                                                            ''
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.description
                                                        }
                                                    />
                                                </div>
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button variant="secondary">
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        disabled={processing}
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
                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                        Delete
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Delete "{list.name}"?
                                    </DialogTitle>
                                    <DialogDescription>
                                        The posts in it are kept; only the
                                        grouping goes.
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
                                                    PostListController.destroy.url(
                                                        list.id,
                                                    ),
                                                )
                                            }
                                        >
                                            Delete list
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </div>
                    )}
                </div>

                <div className="overflow-hidden rounded-lg border border-border">
                    {list.posts.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">
                            No posts yet. Add one from the calendar, or with
                            list_id when creating a post through the API.
                        </p>
                    )}
                    {list.posts.map((post, i) => (
                        <div
                            key={post.id}
                            className="flex items-center gap-3 border-b p-3 last:border-b-0"
                        >
                            <Badge variant="secondary" className="tabular-nums">
                                Part {post.position}
                            </Badge>
                            <PlatformIcon
                                platform={post.platform}
                                className="size-5 rounded"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    {post.preview || (
                                        <span className="text-muted-foreground">
                                            (no text)
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {post.status}
                                    <span className="mx-1 text-muted-foreground/50">
                                        /
                                    </span>
                                    {new Date(post.at).toLocaleString(
                                        undefined,
                                        {
                                            dateStyle: 'medium',
                                            timeStyle: 'short',
                                        },
                                    )}
                                    <span className="mx-1 text-muted-foreground/50">
                                        /
                                    </span>
                                    {post.social_set}
                                </p>
                            </div>

                            {canManage && (
                                <div className="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={i === 0}
                                        onClick={() => move(i, i - 1)}
                                    >
                                        <ArrowUp className="h-4 w-4" />
                                        <span className="sr-only">Move up</span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={i === list.posts.length - 1}
                                        onClick={() => move(i, i + 1)}
                                    >
                                        <ArrowDown className="h-4 w-4" />
                                        <span className="sr-only">
                                            Move down
                                        </span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() =>
                                            router.delete(
                                                PostListController.removePost.url(
                                                    {
                                                        list: list.id,
                                                        post: post.id,
                                                    },
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <X className="h-4 w-4" />
                                        <span className="sr-only">
                                            Remove from list
                                        </span>
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

List.layout = {
    breadcrumbs: [{ title: 'Lists', href: index() }],
};
