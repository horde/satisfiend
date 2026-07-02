# Webhook fixtures

Sample GitHub webhook payloads used by:

- `test/unit/**` - deterministic test inputs.
- `bin/satisfiend-cli debug inject --fixture=…` - in-process synthesis
  of a `WebhookReceivedEvent`.
- `bin/satisfiend-cli debug send --fixture=…` - full round-trip signed
  POST to a Satisfiend endpoint, imitating github.com exactly.

## What's here

Every file is a minimal but structurally realistic payload. They are
based on GitHub's public webhook documentation - no real user data,
no real repositories, no PII. Placeholder actor is `octocat` and the
placeholder repository is `horde/example`.

| File                              | `X-GitHub-Event` header |
|-----------------------------------|-------------------------|
| `push.json`                       | `push`                  |
| `pull_request.opened.json`        | `pull_request`          |
| `issues.opened.json`              | `issues`                |
| `release.published.json`          | `release`               |

## Adding real-world payloads

The synthetic fixtures above are enough to keep the tests honest but
they do not reflect every quirk of a real GitHub delivery. Contributors
are welcome to drop **redacted** real payloads alongside them when the
extra fidelity is useful:

- Strip any secrets, tokens, or private repository names.
- Replace author emails and usernames with placeholders.
- Name the file after the event and action, e.g.
  `pull_request.review_requested.json`.
- Document any redactions inline in a JSON comment-adjacent
  README-per-file if the redaction changes the shape non-trivially.

Every real payload lands under this same directory so the CLI's
`--fixture=path/to/file.json` works uniformly.
