set dotenv-load := false

# Set MAGO=/path/to/mago to run the checks against a different binary, such
# as a local build.
mago := env_var_or_default("MAGO", "vendor/bin/mago")

validate:
    composer validate --strict --no-check-publish

test:
    vendor/bin/phpunit --configuration phpunit.xml

lint:
    {{mago}} --config mago.toml lint

analyze:
    {{mago}} --config mago.toml analyze

format:
    {{mago}} --config mago.toml format
    {{mago}} --workspace tests/corpus format

format-check:
    {{mago}} --config mago.toml format --check
    {{mago}} --workspace tests/corpus format --check

# Starts a real worker on a copy of tests/corpus and checks that each issue
# has a `@mago-expect` pragma right above it, and each pragma its issues. See
# tests/Tools/CorpusPins.php: Mago itself lets a pragma in a function body
# take any later issue of its code, and runs the cases written for a newer
# PHPUnit than the installed one.
test-corpus:
    php tests/Tools/CorpusPins.php {{mago}}

# Checks that every class-like, function and method has a docblock with a
# description. Constructors, anonymous classes and test classes are exempt.
# Mago's linter doesn't have a rule for this.
docblocks:
    php tests/Tools/MissingDocblocks.php src tests/Unit tests/Tools resources

# Everything CI runs.
check-ci: validate format-check lint analyze docblocks test test-corpus

# The checks for the lowest supported Mago version. The format and lint
# checks are left out. Format and lint results can differ with an older
# Mago version.
check-lowest: validate analyze test test-corpus
