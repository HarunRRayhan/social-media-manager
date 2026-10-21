import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ListVideo, Trash2 } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Team/PostController';
import PostListController from '@/actions/App/Http/Controllers/Team/PostListController';
import Heading from '@/components/heading';
import PlatformIcon from '@/components/platform-icon';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PlatformKey } from '@/lib/platforms';
import { cn } from '@/lib/utils';
import { index } from '@/routes/calendar';

type CalendarPost = {
    id: number;
    platform: PlatformKey;
    status:
        | 'draft'
        | 'scheduled'
        | 'queued'
        | 'published'
        | 'failed'
        | 'deleted';
    preview: string;
    at: string;
    social_set: string;
    lists: { id: number; name: string; position: number }[];
};

type ListOption = { id: number; name: string };

type Props = {
    month: string;
    set: number | null;
    posts: CalendarPost[];
    socialSets: { id: number; name: string }[];
    lists: ListOption[];
    canManage: boolean;
};

const statusColors: Record<CalendarPost['status'], string> = {
    draft: 'border-muted-foreground/30 bg-muted text-muted-foreground',
    scheduled:
        'border-blue-500/40 bg-blue-500/10 text-blue-700 dark:text-blue-300',
    queued: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    published:
        'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    failed: 'border-destructive/40 bg-destructive/10 text-destructive',
    deleted:
        'border-muted-foreground/30 bg-muted text-muted-foreground line-through',
};

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Local calendar day, so posts land on the day the viewer sees on their own clock.
const dayKey = (date: Date) =>
    `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;

const monthParam = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

function DeletePost({ post }: { post: CalendarPost }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="shrink-0 rounded p-0.5 hover:bg-destructive/10 hover:text-destructive"
                >
                    <Trash2 className="size-3" />
                    <span className="sr-only">Delete post</span>
                </button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Delete this post from the platform?</DialogTitle>
                <DialogDescription>
                    It is removed from every account it went out to, along with
                    its likes and comments. This cannot be undone. It stays here
                    marked deleted.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <DialogClose asChild>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    PostController.destroy.url(post.id),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Delete
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// Every list the post is in, with its part number, plus adding it to another list.
function PostListDialog({
    post,
    lists,
}: {
    post: CalendarPost;
    lists: ListOption[];
}) {
    const inIds = new Set(post.lists.map((list) => list.id));
    const available = lists.filter((list) => !inIds.has(list.id));

    return (
        <Dialog>
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="shrink-0 rounded p-0.5 hover:bg-foreground/10"
                    title={
                        post.lists.length
                            ? post.lists
                                  .map(
                                      (list) =>
                                          `${list.name}, part ${list.position}`,
                                  )
                                  .join('; ')
                            : 'Add to a list'
                    }
                >
                    <ListVideo className="size-3" />
                    <span className="sr-only">Lists</span>
                </button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Lists</DialogTitle>
                <DialogDescription>
                    A post can be in several lists; its place in each is its
                    part number. Adding appends it as the last part.
                </DialogDescription>
                {post.lists.length > 0 && (
                    <ul className="divide-y rounded-md border text-sm">
                        {post.lists.map((list) => (
                            <li
                                key={list.id}
                                className="flex items-center justify-between gap-2 px-3 py-2"
                            >
                                <span className="truncate">{list.name}</span>
                                <span className="shrink-0 text-muted-foreground tabular-nums">
                                    Part {list.position}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    onClick={() =>
                                        router.delete(
                                            PostListController.removePost.url({
                                                list: list.id,
                                                post: post.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                {available.length > 0 && (
                    <Select
                        value=""
                        onValueChange={(value) =>
                            router.put(
                                PostController.updateList.url(post.id),
                                { list_id: Number(value) },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <SelectTrigger aria-label="Add to list">
                            <SelectValue placeholder="Add to a list" />
                        </SelectTrigger>
                        <SelectContent>
                            {available.map((list) => (
                                <SelectItem
                                    key={list.id}
                                    value={String(list.id)}
                                >
                                    {list.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">Done</Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Calendar({
    month,
    set,
    posts,
    socialSets,
    lists,
    canManage,
}: Props) {
    const [year, monthNumber] = month.split('-').map(Number);
    const first = new Date(year, monthNumber - 1, 1);
    // Weeks start on Monday.
    const start = new Date(
        year,
        monthNumber - 1,
        1 - ((first.getDay() + 6) % 7),
    );
    const daysInMonth = new Date(year, monthNumber, 0).getDate();
    const weeks = Math.ceil((((first.getDay() + 6) % 7) + daysInMonth) / 7);
    const days = Array.from(
        { length: weeks * 7 },
        (_, i) =>
            new Date(
                start.getFullYear(),
                start.getMonth(),
                start.getDate() + i,
            ),
    );

    const byDay = new Map<string, CalendarPost[]>();

    for (const post of posts) {
        const key = dayKey(new Date(post.at));
        byDay.set(key, [...(byDay.get(key) ?? []), post]);
    }

    const query = (date: Date) => ({
        query: { month: monthParam(date), ...(set ? { set } : {}) },
    });
    const today = dayKey(new Date());

    return (
        <>
            <Head title="Calendar" />

            <h1 className="sr-only">Calendar</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Calendar"
                    description="The team's posts by the day they go out, in your local time"
                />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={index(
                                    query(new Date(year, monthNumber - 2, 1)),
                                )}
                                preserveScroll
                            >
                                <ChevronLeft className="h-4 w-4" />
                                <span className="sr-only">Previous month</span>
                            </Link>
                        </Button>
                        <p className="min-w-36 text-center font-medium">
                            {first.toLocaleDateString(undefined, {
                                month: 'long',
                                year: 'numeric',
                            })}
                        </p>
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={index(
                                    query(new Date(year, monthNumber, 1)),
                                )}
                                preserveScroll
                            >
                                <ChevronRight className="h-4 w-4" />
                                <span className="sr-only">Next month</span>
                            </Link>
                        </Button>
                    </div>

                    <Select
                        value={set ? String(set) : 'all'}
                        onValueChange={(value) =>
                            router.get(
                                index.url({
                                    query: {
                                        month,
                                        ...(value === 'all'
                                            ? {}
                                            : { set: value }),
                                    },
                                }),
                            )
                        }
                    >
                        <SelectTrigger className="w-48" aria-label="Social set">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All social sets</SelectItem>
                            {socialSets.map((socialSet) => (
                                <SelectItem
                                    key={socialSet.id}
                                    value={String(socialSet.id)}
                                >
                                    {socialSet.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="overflow-x-auto">
                    <div className="grid min-w-[42rem] grid-cols-7 overflow-hidden rounded-lg border border-border">
                        {weekdays.map((weekday) => (
                            <div
                                key={weekday}
                                className="border-b bg-muted/50 px-2 py-1 text-xs font-medium text-muted-foreground"
                            >
                                {weekday}
                            </div>
                        ))}

                        {days.map((day) => {
                            const key = dayKey(day);
                            const inMonth = day.getMonth() === first.getMonth();

                            return (
                                <div
                                    key={key}
                                    role="group"
                                    aria-label={day.toLocaleDateString(
                                        undefined,
                                        { dateStyle: 'full' },
                                    )}
                                    className={cn(
                                        'min-h-24 space-y-1 border-b border-l p-1 [&:nth-child(7n+1)]:border-l-0',
                                        !inMonth && 'bg-muted/30',
                                    )}
                                >
                                    <p
                                        className={cn(
                                            'px-1 text-xs',
                                            inMonth
                                                ? 'text-foreground'
                                                : 'text-muted-foreground',
                                            key === today &&
                                                'font-semibold text-primary',
                                        )}
                                    >
                                        {day.getDate()}
                                    </p>

                                    {/* Neighbouring months' days stay empty; their posts show on their own month. */}
                                    {(inMonth
                                        ? (byDay.get(key) ?? [])
                                        : []
                                    ).map((post) => (
                                        <div
                                            key={post.id}
                                            title={`${post.status}, ${post.social_set}: ${post.preview}`}
                                            className={cn(
                                                'flex items-center gap-1 rounded border px-1 py-0.5 text-xs',
                                                statusColors[post.status],
                                            )}
                                        >
                                            <PlatformIcon
                                                platform={post.platform}
                                                className="size-4 rounded"
                                            />
                                            <span className="shrink-0 tabular-nums">
                                                {new Date(
                                                    post.at,
                                                ).toLocaleTimeString(
                                                    undefined,
                                                    {
                                                        hour: 'numeric',
                                                        minute: '2-digit',
                                                    },
                                                )}
                                            </span>
                                            <span className="mr-auto truncate">
                                                {post.preview}
                                            </span>
                                            <span className="sr-only">
                                                {post.status}
                                            </span>
                                            {post.lists[0] && (
                                                <span
                                                    className="shrink-0 rounded bg-foreground/10 px-1 text-[10px] tabular-nums"
                                                    title={post.lists[0].name}
                                                >
                                                    Part{' '}
                                                    {post.lists[0].position}
                                                </span>
                                            )}
                                            {canManage && (
                                                <PostListDialog
                                                    post={post}
                                                    lists={lists}
                                                />
                                            )}
                                            {canManage &&
                                                post.status === 'published' && (
                                                    <DeletePost post={post} />
                                                )}
                                        </div>
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </>
    );
}

Calendar.layout = {
    breadcrumbs: [
        {
            title: 'Calendar',
            href: index(),
        },
    ],
};
