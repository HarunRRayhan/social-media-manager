import { Form, Head, Link } from '@inertiajs/react';
import { ListVideo } from 'lucide-react';
import PostListController from '@/actions/App/Http/Controllers/Team/PostListController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/lists';

type PostList = {
    id: number;
    name: string;
    description: string | null;
    social_set_id: number | null;
    social_set: string | null;
    posts_count: number;
};

type Props = {
    lists: PostList[];
    socialSets: { id: number; name: string }[];
    canManage: boolean;
};

export default function Lists({ lists, socialSets, canManage }: Props) {
    return (
        <>
            <Head title="Lists" />

            <h1 className="sr-only">Lists</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Lists"
                    description="Ordered groups of posts, such as the parts of a series. A post's place in a list is its part number."
                />

                {canManage && (
                    <Form
                        {...PostListController.store.form()}
                        resetOnSuccess
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="name">New list</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="e.g. Docker basics, part 1 to 5"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="social_set_id">
                                        Social set (optional)
                                    </Label>
                                    <select
                                        id="social_set_id"
                                        name="social_set_id"
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        defaultValue=""
                                    >
                                        <option value="">Whole team</option>
                                        {socialSets.map((set) => (
                                            <option key={set.id} value={set.id}>
                                                {set.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.social_set_id}
                                    />
                                </div>

                                <Button disabled={processing}>
                                    Create list
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                <div className="overflow-hidden rounded-lg border border-border">
                    {lists.length === 0 && (
                        <p className="p-4 text-sm text-muted-foreground">
                            No lists yet.
                        </p>
                    )}
                    {lists.map((list) => (
                        <Link
                            key={list.id}
                            href={show(list.id)}
                            className="flex items-center justify-between border-b p-4 last:border-b-0 hover:bg-muted/50"
                        >
                            <div className="flex items-center gap-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                    <ListVideo className="h-5 w-5 text-muted-foreground" />
                                </div>

                                <div className="space-y-1">
                                    <p className="font-medium tracking-tight">
                                        {list.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {list.posts_count === 1
                                            ? '1 part'
                                            : `${list.posts_count} parts`}
                                        {list.social_set && (
                                            <>
                                                <span className="mx-1 text-muted-foreground/50">
                                                    /
                                                </span>
                                                {list.social_set}
                                            </>
                                        )}
                                    </p>
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}

Lists.layout = {
    breadcrumbs: [{ title: 'Lists', href: index() }],
};
