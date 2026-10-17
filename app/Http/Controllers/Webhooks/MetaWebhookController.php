<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RunCommentAutomation;
use App\Models\CommentAutomation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta webhooks: Page `feed` comments and Instagram `comments`, fanned out to matching comment automations.
 */
class MetaWebhookController extends Controller
{
    /**
     * The subscription handshake from the App Dashboard.
     */
    public function verify(Request $request): Response
    {
        $token = (string) config('services.meta.webhook_verify_token');

        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'));
    }

    /**
     * Accept a signed event delivery and queue a job per matching automation.
     */
    public function receive(Request $request): Response
    {
        $secret = (string) config('services.meta.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature), 403);

        foreach ($this->comments($request->json()->all()) as $comment) {
            CommentAutomation::query()
                ->where('active', true)
                ->whereHas('socialAccount', fn ($query) => $query->where('provider', $comment['provider'])->where('external_id', $comment['account']))
                ->where(fn ($query) => $query->whereNull('post_remote_id')->orWhere('post_remote_id', $comment['post']))
                ->get()
                ->filter(fn (CommentAutomation $automation) => $automation->matches($comment['text']))
                ->each(fn (CommentAutomation $automation) => RunCommentAutomation::dispatch($automation->id, $comment['id'], $comment['from']));
        }

        return response('', 200);
    }

    /**
     * New comments by someone other than the account itself, in one shape for both platforms.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{provider: string, account: string, id: string, post: string, from: string, text: string}>
     */
    private function comments(array $payload): array
    {
        $comments = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                $comment = match (true) {
                    ($payload['object'] ?? null) === 'page' && ($change['field'] ?? null) === 'feed'
                        && ($value['item'] ?? null) === 'comment' && ($value['verb'] ?? null) === 'add' => [
                            'provider' => 'facebook', 'id' => $value['comment_id'] ?? null, 'post' => $value['post_id'] ?? null, 'text' => $value['message'] ?? '',
                        ],
                    ($payload['object'] ?? null) === 'instagram' && ($change['field'] ?? null) === 'comments' => [
                        'provider' => 'instagram', 'id' => $value['id'] ?? null, 'post' => $value['media']['id'] ?? null, 'text' => $value['text'] ?? '',
                    ],
                    default => null,
                };

                $account = (string) ($entry['id'] ?? '');
                $from = (string) ($value['from']['id'] ?? '');

                if (! $comment || ! $comment['id'] || $from === '' || $from === $account) {
                    continue;
                }

                $comments[] = [...$comment, 'account' => $account, 'from' => $from, 'id' => (string) $comment['id'], 'post' => (string) $comment['post'], 'text' => (string) $comment['text']];
            }
        }

        return $comments;
    }
}
