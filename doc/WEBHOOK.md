# Allow other horde apps to process webhook events from Satisfiend

Satisfiend dispatches a PSR-14 `WebhookReceivedEvent` for every verified
incoming webhook. Any Horde application can listen for these events.

## Register a listener

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
