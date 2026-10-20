import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import Heading from '@/components/heading';
import PlatformIcon from '@/components/platform-icon';
import { Button } from '@/components/ui/button';
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
    status: 'draft' | 'scheduled' | 'queued' | 'published' | 'failed';
    preview: string;
    at: string;
    social_set: string;
};

type Props = {
    month: string;
    set: number | null;
    posts: CalendarPost[];
    socialSets: { id: number; name: string }[];
};

const statusColors: Record<CalendarPost['status'], string> = {
    draft: 'border-muted-foreground/30 bg-muted text-muted-foreground',
    scheduled:
        'border-blue-500/40 bg-blue-500/10 text-blue-700 dark:text-blue-300',
    queued: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    published:
        'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    failed: 'border-destructive/40 bg-destructive/10 text-destructive',
};

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Local calendar day, so posts land on the day the viewer sees on their own clock.
const dayKey = (date: Date) =>
    `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;

const monthParam = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

export default function Calendar({ month, set, posts, socialSets }: Props) {
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
                                            <span className="truncate">
                                                {post.preview}
                                            </span>
                                            <span className="sr-only">
                                                {post.status}
                                            </span>
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
