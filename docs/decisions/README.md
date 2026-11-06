# Design decisions

These records explain choices in the current implementation. For installation and usage, see the [package guide](../../README.md).

## Configuration and dependencies

- [Publish configuration as literal data](published-configuration.md)
- [Require the Laravel components the bridge uses](component-dependencies.md)

## HTTP integration

- [Adapt Laravel requests at the public send boundary](laravel-http-adapter.md)

## Authentication

- [Delegate the Socialite code flow to the SDK](socialite-composition.md)

## Webhooks

- [Record progress for a whole webhook delivery](delivery-level-progress.md)
