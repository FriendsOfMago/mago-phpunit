# Contributing

## Checks

`just check-ci` runs every check: Composer validation, formatting, the package's own lint and
analysis, the docblock check, the unit tests and the corpus. CI runs it on PHP 8.1 to 8.5, which
installs each PHPUnit major from 10.5 to 13, and runs `just check-lowest` on the lowest Mago.

`just docblocks` fails on a class, interface, trait, enum, function or method without a docblock
description. Constructors, anonymous classes and test classes don't need one. A unit test without a
`#[CoversClass]` attribute fails, because `phpunit.xml` sets `requireCoverageMetadata` and
`failOnRisky`.

## Corpus

The corpus in `tests/corpus` has a case for each type and check. The cases are written against the
PHPUnit and Prophecy installed by Composer, with `@mago-expect` comments for the expected issues.

- The analyzer cases are in `tests/corpus/src`, grouped in folders by area. The shared fixtures are
  in `tests/corpus/src/fixtures`.
- The linter cases are in `tests/corpus/lint`, in one folder per rule.

`just test-corpus` runs a real worker on a copy of the corpus. `tests/Tools/CorpusPins.php` then
checks that each issue starts on a line covered by its `@mago-expect` comment.

A case for some PHPUnit majors only is marked with one of these lines:

```php
// Corpus: PHPUnit 12 and later.
// Corpus: PHPUnit 11 and earlier.
// Corpus: PHPUnit 13 only.
```

The case is left out on the other majors. A marker above the `namespace` statement applies to the
whole file. A marker below it applies to the next class, interface, trait or enum, and needs a
blank line above it.
