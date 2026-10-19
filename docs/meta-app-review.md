# Meta App Review

What to submit so Harun's SMM (app 982527934217888) can go Live with Advanced Access.
Until then Meta sends no real comment webhooks, so comment-to-DM cannot run.

## Before submitting

1. Business verification for the "Harun's SMM" business portfolio (Business Settings → Security Center).
2. App settings → Basic:
   - Privacy policy URL: https://sm.harun.dev/privacy
   - Terms of service URL: https://sm.harun.dev/terms
   - User data deletion: instructions URL https://sm.harun.dev/data-deletion
   - App icon (1024×1024), category Business, contact email sm@harun.dev
3. A reviewer login for SMM (see "Reviewer access").

## Permissions

Paste one block per permission under Use cases → Permissions and features → Request advanced access.

| Permission | How SMM uses it |
|---|---|
| `pages_show_list` | Lists the Pages the person manages so they can pick which ones to connect to SMM. |
| `business_management` | Lists Pages and Instagram accounts that the person manages through a business portfolio, so those can be picked too. |
| `pages_read_engagement` | Reads the connected Page's published posts so the person can limit an automation to specific posts. |
| `pages_manage_posts` | Publishes the posts the person writes and schedules in SMM to their Page, at the time they choose. |
| `pages_manage_metadata` | Subscribes the connected Page to the app's webhooks, so SMM is told when someone comments on a Page post. |
| `pages_read_user_content` | Reads the comment a person left on the Page's post (from the webhook) to check it for the automation's keyword. |
| `pages_messaging` | Sends one private reply (a Messenger message) to the person who commented the keyword, with the message the Page owner wrote. |
| `pages_manage_engagement` | Posts the optional public reply under that comment, for example "Sent you a DM". |
| `instagram_basic` | Reads the connected Instagram professional account's username and picture, and its posts for post selection. |
| `instagram_content_publish` | Publishes the posts the person schedules in SMM to their Instagram professional account. |
| `instagram_manage_comments` | Receives comment webhooks for the account's posts and posts the optional public reply. |
| `instagram_manage_messages` | Sends one private reply (Instagram DM) to the person who commented the keyword. |

Each block can end with: "The account owner sets this up and can turn it off at any time. SMM replies at
most once per comment and stores only the comment ID and commenter ID, not the comment text."

## Screencast (one recording, reused for every permission)

1. Sign in to sm.harun.dev.
2. Team → Connections → Connect Facebook / Instagram. Show the Facebook Login dialog and its permission list.
3. Pick a Page and its Instagram account; show them under Connections.
4. Create a post for both, schedule it, and show it published on Facebook and Instagram.
5. Team → Automations → New automation → Comment-to-DM. Keyword "info", a DM text, a public reply.
6. From a second account, comment "info" on the Page post and on the Instagram post.
7. Show the DM arriving and the public reply under each comment, and the run in SMM.
8. Disconnect the account under Connections, and show the data deletion page.

## Reviewer access

SMM signs in with an emailed link or a passkey, which a reviewer cannot use.
Open question: add a review-only login, then put its steps under "App verification → Test instructions".

## Notes

- Instagram comment webhooks need the app Live and `instagram_manage_comments` approved.
- Development mode only delivers events from people with a role on the app; the dashboard "Test" button
  works regardless (checked 2026-10-19: the sample `feed` event reached /webhooks/meta and got 200).
