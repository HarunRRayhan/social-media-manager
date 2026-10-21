# Social Media Manager

A self-hosted app for scheduling and publishing posts to several social networks from one place. It runs at [sm.harun.dev](https://sm.harun.dev) and is still being built.

## Features

- **Teams.** Each team has its own connected accounts, social sets, calendar, and automations. Switch teams from the top bar.
- **Social sets.** Group connected accounts by purpose. One account can sit in several sets, and a post goes to every account of its platform in the chosen set. Every team has a Default set that always holds every connected account and cannot be deleted.
- **Calendar.** A month view of a team's posts, filterable by social set.
- **Publishing.** Schedule or publish now to Facebook Pages, Instagram, Threads, Bluesky, LinkedIn (personal profile), and X.
- **Deleting published posts.** Delete a published post from the calendar (or `DELETE /api/v1/posts/{id}`) to take it down on every account it went out to. The post stays in SMM marked deleted.
- **Comment automations.** Send a DM, and optionally a public reply, when a comment on a Facebook or Instagram post matches a keyword.
- **REST API.** Token-authenticated `/api/v1` for social sets, accounts, and posts, including creating sets and assigning accounts to them. Team owners and admins create tokens under Settings, API tokens. A token limited to the Default set covers every connected account, including ones connected later.

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
| X                   | `X_CLIENT_ID`, `X_CLIENT_SECRET`                              | `{APP_URL}/connect/x/callback`        |
| Bluesky             | none, connect with a handle and an app password               |                                       |

A platform's connect button stays disabled until its credentials are set.

### Platform permissions

| Platform  | Scopes SMM requests                                                                                                 | Delete uses                                                    |
| --------- | ------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Facebook  | `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `business_management`, plus the automation scopes | `DELETE /{post-id}` with the Page token (`pages_manage_posts`) |
| Instagram | `instagram_basic`, `instagram_content_publish`, `instagram_manage_contents`, `instagram_manage_comments`, ...       | `DELETE /{ig-media-id}` (`instagram_manage_contents`)          |
| Threads   | `threads_basic`, `threads_content_publish`, `threads_delete`                                                        | `DELETE graph.threads.com/v1.0/{media-id}` (`threads_delete`)  |
| LinkedIn  | `openid`, `profile`, `email`, `w_member_social`                                                                     | `DELETE /rest/posts/{encoded urn}` (`w_member_social`)         |
| X         | `tweet.read`, `tweet.write`, `users.read`, `media.write`, `offline.access`                                          | `DELETE /2/tweets/{id}` (`tweet.write`)                        |
| Bluesky   | none, an app password                                                                                               | `com.atproto.repo.deleteRecord`                                |

`instagram_manage_contents` and `threads_delete` were added for deleting, so they must be enabled in the Meta app, and accounts connected before that have to be reconnected. Until then, deleting tells you to reconnect the account. Threads allows 100 deletes a day per profile.

The X developer app needs OAuth 2.0 user authentication turned on with "Read and write" permissions, app type Web App (confidential client), and `{APP_URL}/connect/x/callback` as a callback URL. SMM asks for its OAuth 2.0 Client ID and Client Secret. Posts are up to 280 characters with up to 4 images or 1 video (chunked upload). Access tokens last two hours and renew through `x:refresh-tokens`; when a renewal is refused, the account is flagged to reconnect. The plan's rate limits apply: a `429` fails the post with the error and reset time, and it is not retried.

Comment automations also need Meta's webhook pointed at `{APP_URL}/webhooks/meta` with `META_WEBHOOK_VERIFY_TOKEN`, subscribed to Page `feed` and Instagram `comments`. That needs a public HTTPS URL, so use a tunnel locally.

## Scheduled jobs

| Command                                             | When             |
| --------------------------------------------------- | ---------------- |
| `posts:publish-due`                                 | every 5 minutes  |
| `threads:refresh-tokens`, `linkedin:refresh-tokens` | daily            |
| `x:refresh-tokens`                                  | every 15 minutes |
| `social-sets:purge`                                 | daily            |

## Checks

```bash
composer ci:check   # lint, type checks, and the test suite
```

## Deployment

Railway runs two services from `deploy/Dockerfile`: `web` and `worker` (see `deploy/railway.*.json`). Merging to `main` deploys both. `GET /version` returns the deployed commit.

## Docs

- [Meta App Review notes](docs/meta-app-review.md)
