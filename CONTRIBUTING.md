# Contributing

Bug reports, performance traces, documentation corrections, and focused pull requests are welcome.

## Development

Requirements are PHP 8.4+ and Composer 2.

```bash
composer install
composer check
```

Before opening a pull request:

1. Add or update tests for behavior changes.
2. Run `composer check` with no PHPStan baseline or ignored errors.
3. Document public API changes in the README and changelog.
4. Include comparable before/after measurements for performance-sensitive changes, using the same machine and workload.
5. Keep runtime dependencies at zero unless there is a compelling interoperability reason.

Pull requests should be small enough to review and should explain the user-visible outcome. Performance claims must include environment, route count, warm-up, and iteration count.

## Compatibility

Public API changes follow Semantic Versioning. New behavior must support the minimum PHP version declared in `composer.json`.
