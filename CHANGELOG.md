# Changelog

All notable changes to this project will be documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and releases follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.2] - 2026-07-20

### Added

- Add an immutable, typed `RouteDefinition` snapshot for route metadata and compiled catalog loading.
- Enforce PSR-12 automatically with PHP_CodeSniffer in local checks and CI.
- Add an isolated `ArielRouter` object API and mockable `RouterInterface` for dependency injection.
- Add `add()` as a generic route-registration method for both instance and facade usage.

### Changed

- Format the complete source, test, and benchmark suites according to PSR-12.
- Keep compiled payload arrays only at the serialization boundary for compatibility with existing catalog files.
- Keep the static `Router` facade as the primary API while sharing its registration behavior with `ArielRouter`.

## [1.0.1] - 2026-07-19

### Fixed

- Coerce URL strings through PHP's native scalar type rules when invoking typed class handlers.

## [1.0.0] - 2026-07-19

### Added

- Dependency-free radix-tree routing for PHP 8.4+.
- Static, dynamic, constrained, optional, wildcard-method, and regex fallback routes.
- Nested groups, named URL generation, and class or callable handlers.
- Explicit middleware lifecycle contracts, including factories and terminable middleware.
- In-memory and file-backed compiled indexes with atomic cache writes.
- Build-time route definition catalogs.
- Persistent-worker request-state isolation.
- Read-only router introspection helpers for route catalogs, named routes, matching, and compilation state.
- PHPUnit suite, PHPStan level max, CI, and documented performance measurements.
