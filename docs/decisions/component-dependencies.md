# Require the Laravel components the bridge uses

## Decision

Require individual Illuminate components with one supported Laravel range, plus directly imported interface and Symfony packages. Keep the testing component in development requirements and suggestions.

## Rationale

The bridge provides Laravel integration while the PHP SDK remains framework-free. Requiring the entire framework would make a component library unnecessarily broad. A single Illuminate range prevents the manifest from promising inconsistent component support; lowest and highest dependency installations exercise the declared floors. Direct imports need direct requirements rather than depending on another package to keep a transitive dependency.

## Alternatives and costs

Requiring the framework simplifies the dependency list but expands the runtime requirement. Requiring the testing component for every install adds a production dependency used only by the webhook test trait. Suggesting it keeps runtime installs smaller, at the cost of an explicit installation requirement for component-only applications that use the trait. Dependency floors and the JWT conflict in the manifest exclude combinations the supported builds cannot use; loosening them requires validating those combinations.

## Current implementation

[`composer.json`](../../composer.json) defines the component ranges, direct dependencies and testing suggestion. [`PackageManifestTest`](../../tests/PackageManifestTest.php) checks the dependency layout. [`WithoutLaravelsTestingComponentTest`](../../tests/Testing/WithoutLaravelsTestingComponentTest.php) proves ordinary package classes load without the testing component and the trait fails with a useful installation message. The [testing guide](../../README.md#testing) explains when the trait needs it.
