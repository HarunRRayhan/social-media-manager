<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Publishers\MetaGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;

/**
 * Facebook Login for the team: every Page the user grants becomes a Facebook account,
 * and every Instagram professional account linked to one of those Pages becomes an Instagram account.
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
     * Exchange the code for a long-lived token and store the granted Pages and Instagram accounts.
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

        $count = DB::transaction(function () use ($team, $pages) {
            $count = 0;

            foreach ($pages as $page) {
                // A Page token derived from a long-lived user token does not expire.
                $team->socialAccounts()->updateOrCreate(
                    ['provider' => 'facebook', 'external_id' => (string) $page['id']],
                    ['name' => $page['name'], 'avatar_url' => $page['picture']['data']['url'] ?? null, 'access_token' => $page['access_token'], 'token_expires_at' => null],
                );
                $count++;

                if ($ig = $page['instagram_business_account'] ?? null) {
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

        // Best effort: comment automations need it, publishing does not. The Automations page can retry it.
        foreach ($pages as $page) {
            rescue(fn () => self::subscribe((string) $page['id'], $page['access_token']));
        }

        return $count
            ? $this->back('success', trans_choice('{1} Connected 1 account.|[2,*] Connected :count accounts.', $count))
            : $this->back('error', __('No Facebook Pages were shared. Pick at least one Page when Facebook asks.'));
    }

    /**
     * Subscribe a Page to the app's webhooks, so its comments (and its Instagram account's) reach /webhooks/meta.
     */
    public static function subscribe(string $pageId, string $pageToken): void
    {
        MetaGraph::post("{$pageId}/subscribed_apps", $pageToken, ['subscribed_fields' => 'feed,messages']);
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('connections.index');
    }
}
