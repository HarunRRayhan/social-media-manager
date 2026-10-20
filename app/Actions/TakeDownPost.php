<?php

namespace App\Actions;

use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Publishers\ReconnectRequired;
use RuntimeException;

/**
 * Deletes a published post from every account it went out to. Each account that took it down is marked
 * deleted, so a retry after a partial failure only tries the rest; the post itself is marked deleted once
 * none is left. The rows are kept as history.
 */
class TakeDownPost
{
    /**
     * @return list<string> one message per account that still has the post; empty when it is gone everywhere
     */
    public static function run(Post $post): array
    {
        $errors = [];

        foreach ($post->targets()->with('socialAccount')->where('status', 'published')->get() as $target) {
            /** @var PostTarget $target */
            $account = $target->socialAccount;
            $app = $post->platform === 'linkedin' ? 'LinkedIn' : ucfirst($post->platform);

            if (! $account) {
                $errors[] = "A removed {$app} account still has this post: delete it in the {$app} app.";

                continue;
            }

            try {
                app(PublishPost::PUBLISHERS[$account->provider])->delete((string) $target->remote_id, $account);
                $target->update(['status' => 'deleted']);
            } catch (ReconnectRequired $e) {
                $errors[] = "{$account->name}: reconnect this account under Connections to allow deleting, then try again ({$e->getMessage()}).";
            } catch (RuntimeException $e) {
                $errors[] = "{$account->name}: {$e->getMessage()} If it keeps failing, delete it in the {$app} app.";
            }
        }

        if ($errors === []) {
            $post->update(['status' => 'deleted']);
        }

        return $errors;
    }
}
