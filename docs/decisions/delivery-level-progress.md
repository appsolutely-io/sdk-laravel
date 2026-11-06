# Record progress for a whole webhook delivery

## Decision

Record a delivery as handled only after every synchronous listener returns. Protect handling with a delivery-id lock where the configured cache supports locks; require idempotent listeners.

## Rationale

Recording before dispatch would lose work if a listener failed. Recording each listener's progress would require application storage and listener identity management the bridge does not own. Delivery-level progress keeps persistence to the shared handled-id cache, with a failed delivery retried as a whole.

## Alternatives and costs

Per-listener checkpoints could avoid repeating successful listeners but would add a storage contract. A handled check without a lock permits concurrent attempts to run together. The lock reduces that race and the handled check repeats inside it; it does not provide permanent exactly-once side effects. A retry repeats successful listeners too, a store without locks runs unguarded, and a handler exceeding the lock lifetime can overlap another attempt. Queue slow work and make its effects idempotent.

## Current implementation

[`HandleWebhook`](../../src/Webhooks/HandleWebhook.php) parses a known typed payload before dispatch, dispatches the catch-all and typed events, and marks success last. [`DeliveryLock`](../../src/Webhooks/DeliveryLock.php) scopes locks by endpoint namespace and delivery id with a bounded lifetime. [`WebhookRouteTest`](../../tests/Webhooks/WebhookRouteTest.php) covers retries, the repeated handled check and stores without locks; [`QueuedListenerTest`](../../tests/Webhooks/QueuedListenerTest.php) checks event serialization. The [webhook guide](../../README.md#webhooks) gives the complete response table and queue semantics.
