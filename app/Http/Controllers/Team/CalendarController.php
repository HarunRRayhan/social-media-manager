<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A month of the current team's posts, optionally limited to one social set.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam();

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'set' => ['nullable', 'integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ]);

        $month = isset($data['month']) ? Carbon::createFromFormat('!Y-m', $data['month'], 'UTC') : now('UTC')->startOfMonth();

        // A post sits on the day it goes out: its schedule, else when it published, else (a draft) when it was written.
        $day = 'COALESCE(scheduled_at, published_at, created_at)';

        $posts = Post::query()
            ->where('team_id', $team->id)
            ->when($data['set'] ?? null, fn ($query, $set) => $query->where('social_set_id', $set))
            // Posts of a deleted set stay in the bin, not on the calendar.
            ->whereHas('socialSet')
            // The browser shows local days, so a UTC month is padded by a day each side to cover every timezone.
            ->whereRaw("$day >= ? AND $day < ?", [
                $month->copy()->subDay()->toDateTimeString(),
                $month->copy()->addMonth()->addDay()->toDateTimeString(),
            ])
            ->with('socialSet:id,name', 'lists:id,name')
            ->orderByRaw($day)
            ->get()
            ->map(fn (Post $post) => [
                'id' => $post->id,
                'platform' => $post->platform,
                'status' => $post->status,
                'preview' => Str::limit((string) $post->body, 60),
                'at' => ($post->scheduled_at ?? $post->published_at ?? $post->created_at)->toIso8601String(),
                'social_set' => $post->socialSet->name,
                'lists' => $post->lists->map(fn ($list) => ['id' => $list->id, 'name' => $list->name, 'position' => $list->pivot->position]),
            ]);

        return Inertia::render('team/calendar', [
            'month' => $month->format('Y-m'),
            'set' => isset($data['set']) ? (int) $data['set'] : null,
            'posts' => $posts,
            'socialSets' => $team->socialSets()->orderBy('name')->get(['id', 'name']),
            'lists' => $team->postLists()->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('manage', $team),
        ]);
    }
}
