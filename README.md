# Social Media Manager

A self-hosted app for scheduling and publishing posts to several social networks from one place. It runs at [sm.harun.dev](https://sm.harun.dev) and is still being built.

## Features

- **Teams.** Each team has its own connected accounts, social sets, calendar, and automations. Switch teams from the top bar.
- **Social sets.** Group connected accounts by purpose. One account can sit in several sets, and a post goes to every account of its platform in the chosen set. Every team has a Default set that always holds every connected account and cannot be deleted.
- **Calendar.** A month view of a team's posts, filterable by social set.
- **Publishing.** Schedule or publish now to Facebook Pages, Instagram, Threads, Bluesky, and LinkedIn (personal profile).
- **Deleting published posts.** Delete a published post from the calendar (or `DELETE /api/v1/posts/{id}`) to take it down on every account it went out to. The post stays in SMM marked deleted.
- **Comment automations.** Send a DM, and optionally a public reply, when a comment on a Facebook or Instagram post matches a keyword.
- **Lists.** Ordered groups of posts, such as the parts of a series; a post's place in a list is its part number. See [Lists](#lists).
- **REST API.** Token-authenticated `/api/v1` for social sets, accounts, posts, and lists, including creating sets and assigning accounts to them. Team owners and admins create tokens under Settings, API tokens. A token limited to the Default set covers every connected account, including ones connected later.
- **MCP server.** The same surface over the Model Context Protocol at `/mcp`, for Claude Code, Claude Desktop, and other MCP clients. See [MCP](#mcp).

## Stack

Laravel 13 (PHP 8.4), Inertia with React and TypeScript, Fortify for auth, Sanctum for API tokens, PostgreSQL, a queue worker, and the Laravel scheduler.

## Local setup

You need PHP 8.4, Composer, Node 22, and PostgreSQL. Create a database and set the `DB_*` values in `.env` (copied from `.env.example`) before running setup.

```bash
composer setup   # install, copy .env, generate key, migrate, build assets
composer dev     # app server, queue, logs, and Vite
php artisan schedule:work   # needed for scheduled posts to publish
```

Then fill in the platform credentials you need in `.env`:

| Platform            | Env vars                                                      | Redirect URI                          |
| ------------------- | ------------------------------------------------------------- | ------------------------------------- |
| Facebook, Instagram | `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN` | `{APP_URL}/connect/meta/callback`     |
| Threads             | `THREADS_APP_ID`, `THREADS_APP_SECRET`                        | `{APP_URL}/connect/threads/callback`  |
| LinkedIn            | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`                | `{APP_URL}/connect/linkedin/callback` |
| Bluesky             | none, connect with a handle and an app password               |                                       |

A platform's connect button stays disabled until its credentials are set.

### Platform permissions

| Platform  | Scopes SMM requests                                                                                                 | Delete uses                                                    |
| --------- | ------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Facebook  | `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `business_management`, plus the automation scopes | `DELETE /{post-id}` with the Page token (`pages_manage_posts`) |
| Instagram | `instagram_basic`, `instagram_content_publish`, `instagram_manage_contents`, `instagram_manage_comments`, ...       | `DELETE /{ig-media-id}` (`instagram_manage_contents`)          |
| Threads   | `threads_basic`, `threads_content_publish`, `threads_delete`                                                        | `DELETE graph.threads.com/v1.0/{media-id}` (`threads_delete`)  |
| LinkedIn  | `openid`, `profile`, `email`, `w_member_social`                                                                     | `DELETE /rest/posts/{encoded urn}` (`w_member_social`)         |
| Bluesky   | none, an app password                                                                                               | `com.atproto.repo.deleteRecord`                                |

`instagram_manage_contents` and `threads_delete` were added for deleting, so they must be enabled in the Meta app, and accounts connected before that have to be reconnected. Until then, deleting tells you to reconnect the account. Threads allows 100 deletes a day per profile.

Comment automations also need Meta's webhook pointed at `{APP_URL}/webhooks/meta` with `META_WEBHOOK_VERIFY_TOKEN`, subscribed to Page `feed` and Instagram `comments`. That needs a public HTTPS URL, so use a tunnel locally.

## Scheduled jobs

| Command                                             | When            |
| --------------------------------------------------- | --------------- |
| `posts:publish-due`                                 | every 5 minutes |
| `threads:refresh-tokens`, `linkedin:refresh-tokens` | daily           |
| `social-sets:purge`                                 | daily           |

## Checks

```bash
composer ci:check   # lint, type checks, and the test suite
```

## Deployment

Railway runs two services from `deploy/Dockerfile`: `web` and `worker` (see `deploy/railway.*.json`). Merging to `main` deploys both. `GET /version` returns the deployed commit.

## Lists

A list is an ordered group of posts, for example the five parts of a series. It is SMM's own grouping: a post can be in several lists, and its 1-based position in a list is shown as "Part N" on the calendar and the list page. Nothing is added to captions. Lists are team-wide, or tied to one social set; a token limited to a set sees team-wide lists plus its own set's.

Only YouTube has a playlist API, so a list may later be mirrored to the platform; `remote_ids` (JSON, keyed by platform) on the list is where that mirror's id goes. No publisher writes it yet, and every platform is a no-op today.

In the app: **Lists** in the sidebar to create, rename, reorder (up/down), remove posts from, and delete lists; the list icon on a calendar post puts it into a list.

API, under `/api/v1` with the same Bearer token as the rest:

| Method   | Path                      | Body                                                                          |
| -------- | ------------------------- | ----------------------------------------------------------------------------- |
| `GET`    | `lists`                   |                                                                               |
| `POST`   | `lists`                   | `name`, `description?`, `social_set_id?`                                      |
| `GET`    | `lists/{id}`              | returns the posts in order                                                    |
| `PATCH`  | `lists/{id}`              | `name?`, `description?`, `social_set_id?`                                     |
| `DELETE` | `lists/{id}`              | soft-deletes the list, posts stay                                             |
| `POST`   | `lists/{id}/posts`        | `post_id`, `position?` (1-based, default appended; an existing post is moved) |
| `DELETE` | `lists/{id}/posts/{post}` |                                                                               |
| `PUT`    | `lists/{id}/posts/order`  | `post_ids: []`, every post of the list exactly once                           |

`POST posts` and `PATCH posts/{id}` also take `list_id` and `position`, and every post carries `lists: [{id, name, position}]`. `GET posts` filters by `status`, `platform`, `social_set_id`, `from`, and `to`.

## MCP

`POST https://sm.harun.dev/mcp` is a Streamable HTTP MCP server (the [`laravel/mcp`](https://github.com/laravel/mcp) package) authenticated with the same Bearer API tokens as `/api/v1`; a set-limited token is limited the same way. Tools: `list-social-sets`, `list-social-accounts`, `list-posts`, `get-post`, `create-post`, `update-post`, `delete-post`, `list-lists`, `create-list`, `get-list`, `add-post-to-list`, `remove-post-from-list`, `reorder-list`. They validate with the API's own rules and run the same actions, so behavior cannot drift between the two.

Claude Code:

```bash
claude mcp add --transport http smm https://sm.harun.dev/mcp --header "Authorization: Bearer $SM_API_TOKEN"
```

Claude Desktop or any client that takes a JSON config (`claude_desktop_config.json`):

```json
{
    "mcpServers": {
        "smm": {
            "type": "http",
            "url": "https://sm.harun.dev/mcp",
            "headers": { "Authorization": "Bearer sm_team_live_..." }
        }
    }
}
```

## Docs

- [Meta App Review notes](docs/meta-app-review.md)
