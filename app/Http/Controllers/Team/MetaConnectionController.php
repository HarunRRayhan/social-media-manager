<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Publishers\MetaGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Facebook Login for the team: the user picks which granted Pages become Facebook accounts,
 * and which Instagram professional accounts linked to those Pages become Instagram accounts.
 */
class MetaConnectionController extends Controller
{
    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'business_management',
        'instagram_basic',
        'instagram_content_publish',
        // Comment automations: receive comment webhooks, send private replies, post public replies.
        'pages_manage_metadata',
        'pages_messaging',
        'pages_manage_engagement',
        'pages_read_user_content',
        'instagram_manage_comments',
        'instagram_manage_messages',
    ];

    /**
     * Send the user to the Facebook Login dialog.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);
        abort_unless(filled(config('services.meta.app_id')), 404);

        $state = Str::random(40);
        $request->session()->put('meta_oauth', ['state' => $state, 'team_id' => $team->id]);

        $url = 'https://www.facebook.com/'.config('services.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => route('connect.meta.callback'),
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);

        return redirect()->away($url);
    }

    /**
     * Exchange the code for a long-lived token and hold the granted Pages for the picker.
     */
    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull('meta_oauth');

        if (! $flow || ! is_string($request->query('state')) || ! hash_equals($flow['state'], $request->query('state'))) {
            return $this->back('error', __('That Facebook sign-in expired or did not start here. Please try again.'));
        }

        $team = $request->user()->teams()->whereKey($flow['team_id'])->first();
        abort_unless($team && $request->user()->can('manage', $team), 403);

        if (! is_string($request->query('code'))) {
            return $this->back('error', __('Facebook connection was cancelled.'));
        }

        try {
            $short = MetaGraph::exchange(['redirect_uri' => route('connect.meta.callback'), 'code' => $request->query('code')])['access_token'];
            $long = MetaGraph::exchange(['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $short])['access_token'];

            // shortcut: reads only the first 100 Pages, follow paging.next if a team ever has more.
            $pages = MetaGraph::get('me/accounts', $long, [
                'fields' => 'id,name,access_token,picture,instagram_business_account{id,username,profile_picture_url}',
                'limit' => 100,
            ])['data'] ?? [];
        } catch (RuntimeException $e) {
            report($e);

            return $this->back('error', __('Could not connect to Facebook: :message', ['message' => $e->getMessage()]));
        }

        if (! $pages) {
            return $this->back('error', __('No Facebook Pages were shared. Pick at least one Page when Facebook asks.'));
        }

        // Tokens stay server-side; the picker only ever sees ids, names and pictures.
        $key = Str::random(40);
        Cache::put('meta-pick:'.$key, encrypt($pages), now()->addMinutes(15));
        $request->session()->put('meta_pick', ['key' => $key, 'team_id' => $team->id]);

        return to_route('connect.meta.pick');
    }

    /**
     * Let the user choose which of the granted Pages and Instagram accounts to connect.
     */
    public function pick(Request $request): Response|RedirectResponse
    {
        [$team, $pages] = $this->pending($request);

        if ($pages === null) {
            return $this->expired();
        }

        $connected = $team->socialAccounts()->get(['provider', 'external_id'])
            ->groupBy('provider')->map->pluck('external_id');

        return Inertia::render('team/connections-pick', [
            'pages' => collect($pages)->map(fn (array $page) => [
                'id' => (string) $page['id'],
                'name' => $page['name'],
                'avatar_url' => $page['picture']['data']['url'] ?? null,
                'connected' => $connected->get('facebook', collect())->contains((string) $page['id']),
            ])->values(),
            'instagram' => collect($pages)->filter(fn (array $page) => isset($page['instagram_business_account']['id']))
                ->map(fn (array $page) => [
                    'id' => (string) $page['instagram_business_account']['id'],
                    'name' => $page['instagram_business_account']['username'] ?? $page['name'],
                    'username' => $page['instagram_business_account']['username'] ?? null,
                    'avatar_url' => $page['instagram_business_account']['profile_picture_url'] ?? null,
                    'connected' => $connected->get('instagram', collect())->contains((string) $page['instagram_business_account']['id']),
                ])->values(),
        ]);
    }

    /**
     * Store the picked Pages and Instagram accounts and subscribe their Pages to webhooks.
     */
    public function store(Request $request): RedirectResponse
    {
        [$team, $pages] = $this->pending($request);

        if ($pages === null) {
            return $this->expired();
        }

        $pageIds = collect($pages)->map(fn (array $page) => (string) $page['id'])->all();
        $igIds = collect($pages)->map(fn (array $page) => (string) ($page['instagram_business_account']['id'] ?? ''))->filter()->all();

        $picked = $request->validate([
            'facebook' => ['array', 'required_without:instagram'],
            'facebook.*' => ['string', Rule::in($pageIds)],
            'instagram' => ['array'],
            'instagram.*' => ['string', Rule::in($igIds)],
        ], ['facebook.required_without' => __('Pick at least one account.')]);
        $facebook = $picked['facebook'] ?? [];
        $instagram = $picked['instagram'] ?? [];

        $chosen = array_values(array_filter($pages, fn (array $page) => in_array((string) $page['id'], $facebook, true)
            || in_array((string) ($page['instagram_business_account']['id'] ?? ''), $instagram, true)));

        $count = DB::transaction(function () use ($team, $chosen, $facebook, $instagram) {
            $count = 0;

            foreach ($chosen as $page) {
                if (in_array((string) $page['id'], $facebook, true)) {
                    // A Page token derived from a long-lived user token does not expire.
                    $team->socialAccounts()->updateOrCreate(
                        ['provider' => 'facebook', 'external_id' => (string) $page['id']],
                        ['name' => $page['name'], 'avatar_url' => $page['picture']['data']['url'] ?? null, 'access_token' => $page['access_token'], 'token_expires_at' => null],
                    );
                    $count++;
                }

                $ig = $page['instagram_business_account'] ?? null;

                if ($ig && in_array((string) $ig['id'], $instagram, true)) {
                    $team->socialAccounts()->updateOrCreate(
                        ['provider' => 'instagram', 'external_id' => (string) $ig['id']],
                        [
                            'name' => $ig['username'] ?? $page['name'],
                            'username' => $ig['username'] ?? null,
                            'avatar_url' => $ig['profile_picture_url'] ?? null,
                            'access_token' => $page['access_token'],
                            'token_expires_at' => null,
                            'meta' => ['page_id' => (string) $page['id']],
                        ],
                    );
                    $count++;
                }
            }

            return $count;
        });

        Cache::forget('meta-pick:'.$request->session()->pull('meta_pick')['key']);

        // Best effort: comment automations need it, publishing does not. The Automations page can retry it.
        foreach ($chosen as $page) {
            rescue(fn () => self::subscribe((string) $page['id'], $page['access_token']));
        }

        return $this->back('success', trans_choice('{1} Connected 1 account.|[2,*] Connected :count accounts.', $count));
    }

    /**
     * Subscribe a Page to the app's webhooks, so its comments (and its Instagram account's) reach /webhooks/meta.
     */
    public static function subscribe(string $pageId, string $pageToken): void
    {
        MetaGraph::post("{$pageId}/subscribed_apps", $pageToken, ['subscribed_fields' => 'feed,messages']);
    }

    /**
     * The team and cached Pages of the sign-in waiting to be picked from, or null Pages once it expired.
     *
     * @return array{0: Team|null, 1: list<array<string, mixed>>|null}
     */
    private function pending(Request $request): array
    {
        $flow = $request->session()->get('meta_pick');
        $cached = $flow ? Cache::get('meta-pick:'.$flow['key']) : null;

        if (! $cached) {
            return [null, null];
        }

        $team = $request->user()->teams()->whereKey($flow['team_id'])->first();
        abort_unless($team && $request->user()->can('manage', $team), 403);

        return [$team, decrypt($cached)];
    }

    private function expired(): RedirectResponse
    {
        return $this->back('error', __('That Facebook sign-in expired. Please connect again.'));
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('connections.index');
    }
}
