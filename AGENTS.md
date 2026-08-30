# Development guide

This is a monorepo with independent PHP and JavaScript packages.

## Setup

- PHP: `composer install`
- Nested PHP package only: `composer install --working-dir=packages/laravel`
- JavaScript: `npm install`

## Verify

- Everything: `composer test` (after Composer and npm dependency installs)
- PHP: `composer test --working-dir=packages/laravel`
- JavaScript: `npm test --prefix packages/javascript`
- JavaScript build: `npm run build --prefix packages/javascript`
- Formatting: `composer format`

Do not add application-specific module hydrators or filesystem discovery.
Module names must be registered explicitly. Keep the hydration factory scoped.
