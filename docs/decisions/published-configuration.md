# Publish configuration as literal data

## Decision

Keep the published configuration's environment names and defaults as literal values. Use the internal environment enum for diagnostics, with tests keeping the two representations aligned.

## Rationale

A published `config/appsolutely.php` belongs to the application and can outlive the package release that supplied it. Referring to an SDK constant or bridge enum in that file would bind application boot to a class that a dependency can rename. Literal data avoids that coupling while retaining readable editable configuration.

## Alternatives and costs

Importing the enum or SDK constants removes duplication but can turn a dependency upgrade into a fatal boot error in an existing application. Literal copies can drift, so the package pays for parity tests: every variable must map to its setting and unset settings must use the expected SDK defaults. Settings remain lazy so an application need not configure features it does not use.

## Current implementation

[`config/appsolutely.php`](../../config/appsolutely.php) contains literal values. [`EnvironmentVariable`](../../src/EnvironmentVariable.php) supplies internal names and keys for [`Settings`](../../src/Settings.php). [`EnvironmentVariableTest`](../../tests/EnvironmentVariableTest.php) checks mappings, defaults and the absence of bridge or SDK class references in published configuration. The [configuration guide](../../README.md#configuration) describes lazy validation and optional credentials.
