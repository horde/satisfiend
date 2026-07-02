# Allow other horde apps to process webhook events from Satisfiend

Satisfiend dispatches a PSR-14 `WebhookReceivedEvent` for every verified
incoming webhook. Any Horde application can listen for these events.

There are two ways to subscribe a listener:

1. **From another Horde application** - the listener class lives in an
   app package and registers itself in that app's `_bootstrap()`. See
   "Register from another Horde app" below.
2. **From an installation-local class** - the listener class lives in
   the running install, not in any versioned package, and satisfiend
   picks it up from its config. See "Register from installation-local
   config" below.

## Register from another Horde app

Add a listener to the shared `SimpleListenerProvider` in your application's `_bootstrap()` method.
Guard the registration so your app works even when satisfiend is not installed:

```php
use Horde\EventDispatcher\SimpleListenerProvider;
use Psr\EventDispatcher\ListenerProviderInterface;

protected function _bootstrap(): void
{
    $injector = $GLOBALS['injector'];

    // Only register if satisfiend is actually installed
    if (!class_exists(\Horde\Satisfiend\Event\WebhookReceivedEvent::class)) {
        return;
    }

    if ($injector->has(ListenerProviderInterface::class)) {
        $provider = $injector->getInstance(ListenerProviderInterface::class);
        if ($provider instanceof SimpleListenerProvider) {
            $provider->addListener(
                $injector->getInstance(MyWebhookListener::class),
            );
        }
    }
}
```

## Register from installation-local config

When you need to react to webhook events but the reaction is specific to
one installation - a spencer-only rebuild trigger, a Slack post to your
team's channel, a rebuild of your private wiki - a full Horde app is
overkill. Satisfiend reads a list of installation-local listener classes
from its own config and subscribes each one to the shared dispatcher at
bootstrap.

### 1. Write the listener class

Put the class anywhere on disk in your running install. A common choice
is a `local/` directory next to `vendor/`:

```php
// /path/to/running/horde/local/src/MyOps/DevSiteRebuildListener.php
namespace MyOps;

use Horde\Satisfiend\Event\WebhookReceivedEvent;
use Psr\Log\LoggerInterface;

class DevSiteRebuildListener
{
    public function __construct(
        private readonly LoggerInterface $log,
    ) {}

    public function __invoke(WebhookReceivedEvent $event): void
    {
        if ($event->debug || $event->eventType !== 'push') {
            return;
        }
        // Kick the SSG - the local admin's business.
        // ...
    }
}
```

### 2. Teach composer to autoload it

Add a PSR-4 mapping to the **root bundle**'s `composer.json` (the one
that owns your running install, not to any `vendor/` package):

```json
{
    "autoload": {
        "psr-4": {
            "MyOps\\": "local/src/MyOps/"
        }
    }
}
```

Then run `composer dump-autoload` so the class becomes discoverable.

### 3. Name the class in satisfiend's config

Edit `var/config/satisfiend/conf.php` (create it if missing; the schema
lives in `config/conf.xml` and is distributed by
horde-installer-plugin):

```php
$conf['listeners']['classes'] = [
    'MyOps\\DevSiteRebuildListener',
];
```

That's it. On the next HTTP request or CLI invocation, satisfiend's
`ListenerLoader` reads the config, resolves each class through the
injector (so listeners can declare typed constructor dependencies -
`Horde\Db\Adapter`, `LoggerInterface`, anything bound in the
container), verifies the resulting object is callable, and subscribes
it to the shared listener provider.

### Failure handling

A misconfigured entry is logged and skipped; satisfiend keeps running.
Look for `satisfiend:` warnings in the horde log to diagnose:

- **"not autoloadable"** - the PSR-4 mapping is missing or
  `composer dump-autoload` has not been run.
- **"could not construct"** - the class exists but its constructor
  threw or asked for a service the injector cannot provide.
- **"is not callable"** - the class exists and constructs cleanly, but
  it does not define `__invoke` (or any other callable interface).
- **"non-string entry"** - `$conf['listeners']['classes']` contained
  a value that is not a class name string.

## Write a listener

A listener is any callable that type-hints `WebhookReceivedEvent`.
The simplest form is an invocable class:

```php
namespace Horde\MyApp;

use Horde\Satisfiend\Event\WebhookReceivedEvent;

class MyWebhookListener
{
    public function __invoke(WebhookReceivedEvent $event): void
    {
        // Filter by provider and event type
        if ($event->providerType !== 'github') {
            return;
        }
        if ($event->eventType !== 'pull_request') {
            return;
        }

        // Access relational fields directly
        $repo   = $event->repository;  // "owner/repo"
        $action = $event->action;      // "opened", "closed", "merged", ...
        $actor  = $event->actor;       // "username"
        $nodeId = $event->nodeId;      // GitHub GraphQL node ID

        // Access the full payload when you need more detail
        $payload = json_decode($event->payload);
        $prTitle = $payload->pull_request->title;
    }
}
```

## Event properties

| Property       | Type     | Description                                        |
|----------------|----------|----------------------------------------------------|
| `slug`         | `string` | Endpoint slug that received the webhook            |
| `providerType` | `string` | `github`, `gitea`, `gitlab`                        |
| `eventType`    | `string` | Provider event name (e.g. `push`, `pull_request`)  |
| `action`       | `string` | Sub-action (e.g. `opened`, `closed`), empty if N/A |
| `repository`   | `string` | Full repository name (`owner/repo`)                |
| `actor`        | `string` | Username of the person who triggered the event     |
| `nodeId`       | `string` | Globally unique entity ID for idempotency          |
| `deliveryId`   | `string` | Webhook delivery UUID for replay deduplication     |
| `payload`      | `string` | Raw JSON body                                      |
| `debug`        | `bool`   | `true` only for events synthesised in-process by `bin/satisfiend-cli debug inject`. Real HTTP deliveries - including `bin/satisfiend-cli debug send` - are always `false`. Listeners that must never react to synthetic events should filter on this flag first. |

## Dependencies

Satisfiend should be a `suggest`, not a `require`, so your app works without it:

```json
"suggest": {
    "horde/satisfiend": "^1 || dev-FRAMEWORK_6_0"
}
```

The `class_exists()` guard in `_bootstrap()` handles the case where satisfiend
is not installed. The listener class itself can safely import the event class
via `use` - PHP only resolves those when the class is actually loaded, and the
guard prevents that from happening when satisfiend is absent.

## Execution order

Satisfiend's own `PersistEventListener` runs on the same dispatcher and stores
every event to the `satisfiend_events` table. Your listener runs alongside it.
Listener order depends on registration order in `SimpleListenerProvider` - apps
that bootstrap earlier register earlier. Do not rely on ordering between apps.

If your listener throws an exception, it propagates immediately and subsequent
listeners (including persistence) will not run. Catch exceptions in your
listener if you want best-effort processing.
