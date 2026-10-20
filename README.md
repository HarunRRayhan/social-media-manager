# Social Media Manager

A self-hosted app for scheduling and publishing posts to several social networks from one place. It runs at [sm.harun.dev](https://sm.harun.dev) and is still being built.

## Features

- **Teams.** Each team has its own connected accounts, social sets, calendar, and automations. Switch teams from the top bar.
- **Social sets.** Group connected accounts by purpose. One account can sit in several sets, and a post goes to every account of its platform in the chosen set.
- **Calendar.** A month view of a team's posts, filterable by social set.
- **Publishing.** Schedule or publish now to Facebook Pages, Instagram, Threads, Bluesky, and LinkedIn (personal profile).
- **Comment automations.** Send a DM, and optionally a public reply, when a comment on a Facebook or Instagram post matches a keyword.
- **REST API.** Token-authenticated `/api/v1` for social sets, accounts, and posts, including creating sets and assigning accounts to them. Team owners and admins create tokens under Settings, API tokens.

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

## Docs

- [Meta App Review notes](docs/meta-app-review.md)
