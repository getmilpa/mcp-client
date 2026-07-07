# Contributing to Milpa MCP Client

Thanks for your interest in contributing! Milpa MCP Client is the Model Context Protocol
client of the Milpa framework — stdio and HTTP/SSE transports, typed tool/resource
contracts, connection management, and a manager that aggregates and routes tool calls
across multiple connected MCP servers.

## Getting started

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse src
php tools/validate-docblocks.php
```

These run in CI on PHP 8.3 and 8.4 (alongside `composer validate --strict` and a
`php -l` syntax pass); run them locally before opening a PR.

## Guidelines

- **PHP >= 8.3**, with `declare(strict_types=1);` in every file.
- **Document every public symbol.** A public class/interface/enum/trait or public
  method without a DocBlock summary fails CI (`tools/validate-docblocks.php`).
  Trivial accessors and magic methods are exempt.
- **Respect the tier boundary.** Milpa MCP Client has no dependency on `milpa/core` or
  any other Milpa package — its only production dependency is `guzzlehttp/guzzle`, for
  the HTTP/SSE transport. Keep it that way: do not introduce a dependency on Doctrine,
  a concrete container, or any product/plugin code, and do not couple a transport to a
  specific MCP server implementation.
- **[Conventional Commits](https://www.conventionalcommits.org/)** — releases and
  the CHANGELOG are generated automatically from commit messages. Use
  `feat:` / `fix:` / `docs:` / `chore:` etc.; a breaking change to a public
  interface or capability schema is a `feat!:` / `BREAKING CHANGE:` (bumps MINOR
  while the package is `0.x`, MAJOR once it reaches `1.0`).

## Code style

The whole Milpa family (`milpa/core`, `milpa/http`, `milpa/tool-runtime`) shares one
coding standard, committed verbatim in every repo as `.php-cs-fixer.dist.php` and
enforced by CI. In short:

- **[PSR-12](https://www.php-fig.org/psr/psr-12/) base**: 4 spaces (never tabs);
  opening braces on the **next line** for classes and methods, on the **same line**
  for control structures; one statement per line.
- **Family deltas on top of PSR-12**: short array syntax (`[]`), one space around
  string concatenation (`$a . $b`), fully-multiline method arguments when split,
  no unused imports, aligned/separated/trimmed PHPDoc tags, trailing commas in
  multiline constructs.

Check and fix locally before pushing:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff   # what CI runs
vendor/bin/php-cs-fixer fix                    # apply
```

Do not tweak `.php-cs-fixer.dist.php` in one package alone — the standard changes
in lockstep across the family or not at all.

## Pull requests

Keep PRs focused, add tests for behavior changes, and make sure the four commands
above are green. A maintainer will review and, once merged to `main`,
release-please will handle versioning.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE).

---

Milpa is developed and maintained by [TeamX Agency](https://teamx.agency).
