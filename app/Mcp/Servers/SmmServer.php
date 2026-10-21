<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddPostToList;
use App\Mcp\Tools\CreateList;
use App\Mcp\Tools\CreatePost;
use App\Mcp\Tools\DeletePost;
use App\Mcp\Tools\GetList;
use App\Mcp\Tools\GetPost;
use App\Mcp\Tools\ListLists;
use App\Mcp\Tools\ListPosts;
use App\Mcp\Tools\ListSocialAccounts;
use App\Mcp\Tools\ListSocialSets;
use App\Mcp\Tools\RemovePostFromList;
use App\Mcp\Tools\ReorderList;
use App\Mcp\Tools\UpdatePost;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The same surface as /api/v1, over MCP. Authenticated with the same Bearer API tokens; a set-limited token
 * sees only its set here too.
 */
#[Name('Social Media Manager')]
#[Version('1.0.0')]
#[Instructions('Manage a team\'s social posts and post lists (ordered series). Posts go to every account of their platform in the chosen social set. Times are ISO 8601. A whole-team token must pass social_set_id when creating posts.')]
class SmmServer extends Server
{
    protected array $tools = [
        ListSocialSets::class,
        ListSocialAccounts::class,
        ListPosts::class,
        GetPost::class,
        CreatePost::class,
        UpdatePost::class,
        DeletePost::class,
        ListLists::class,
        CreateList::class,
        GetList::class,
        AddPostToList::class,
        RemovePostFromList::class,
        ReorderList::class,
    ];
}
