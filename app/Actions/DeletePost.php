<?php

namespace App\Actions;

use App\Models\Post;
use App\Models\PostList;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Deleting a post, shared by the REST API and the MCP tools. A draft or scheduled post is removed; a published
 * one is taken down from every account it went out to and kept with status `deleted`.
 */
class DeletePost
{
    /**
     * @return Post|null the taken-down post, or null when a draft/scheduled post was removed
     *
     * @throws ConflictHttpException for queued/failed posts
     * @throws UnprocessableEntityHttpException when an account could not delete it, with what to do in the message
     */
    public static function run(Post $post): ?Post
    {
        if ($post->status === 'published') {
            $errors = TakeDownPost::run($post);

            if ($errors !== []) {
                throw new UnprocessableEntityHttpException(implode(' ', $errors));
            }

            return $post->load('targets.socialAccount');
        }

        if (! $post->isEditable()) {
            throw new ConflictHttpException('Only draft, scheduled or published posts can be deleted.');
        }

        // The FK cascade would drop the pivot rows without closing the gaps in each list's positions.
        $post->lists->each(fn (PostList $list) => $list->removePost($post));
        $post->delete();

        return null;
    }
}
