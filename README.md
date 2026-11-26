# PHPUnit extension for Mago

[![CI](https://github.com/FriendsOfMago/mago-phpunit/actions/workflows/ci.yml/badge.svg)](https://github.com/FriendsOfMago/mago-phpunit/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/friendsofmago/mago-phpunit)](https://packagist.org/packages/friendsofmago/mago-phpunit)
[![Downloads](https://img.shields.io/packagist/dt/friendsofmago/mago-phpunit)](https://packagist.org/packages/friendsofmago/mago-phpunit)
[![PHP](https://img.shields.io/badge/PHP-%5E8.1-777BB4?logo=php&logoColor=white)](composer.json)
[![Mago](https://img.shields.io/badge/Mago-%5E1.61.0-F26B3A)](https://mago.carthage.software)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-10.5%20%7C%2011.5%20%7C%2012%20%7C%2013-3C9CD7)](composer.json)
[![License](https://img.shields.io/github/license/FriendsOfMago/mago-phpunit)](LICENSE)

`friendsofmago/mago-phpunit` is a [Mago](https://mago.carthage.software) extension for PHPUnit and
Prophecy, and the Mago counterpart of [phpstan-phpunit](https://github.com/phpstan/phpstan-phpunit).

- **Fewer false reports in tests.** The extension adds the types of mocks, asserted values and
  Prophecy calls that are missing from PHPUnit's docblocks, so test code that works isn't reported.
- **Mistakes found before the tests run.** Data providers with the wrong values, mocked methods
  that don't exist, a missing `parent::setUp()` and similar problems are reported by the analyzer.
- **Assertions that can't fail.** An assertion that always passes for the value's type is reported.
- **PHPUnit linter rules.** The PHPUnit rules of Mago's linter, such as strict assertions and one
  assertion style, come with the extension, with their fixes.

```php
$mailer = $this->createMock(Mailer::class);
$mailer->method('sendLater');
// phpunit/mock-undefined-method: The method `sendLater()` doesn't exist on `Mailer`.
```

PHPUnit 10.5, 11.5, 12 and 13 are supported, and so is Prophecy. The extension needs PHP 8.1 or
later and Mago 1.61.0 or a later 1.x release. The PHPUnit version is detected from the PHPUnit
classes in `vendor`. Checks that only apply to some versions, such as the annotation checks, are
turned on or off to match.

## Setup

```sh
composer require --dev friendsofmago/mago-phpunit
```

This also installs Mago through Composer, as `vendor/bin/mago`. If the project doesn't have a
`mago.toml` yet, run `vendor/bin/mago init` first.

Mago runs an extension in separate PHP processes, called workers. The command that starts a worker
is set in an `[extension-hosts.*]` table in `mago.toml`. Add this block to `mago.toml`. If it
already has an `[analyzer]` table, put the `class-initializers` line there instead of adding a
second one.

```toml
[extension-hosts.phpunit]
command = ["php", "-d", "xdebug.mode=off", "-d", "pcov.enabled=0", "vendor/friendsofmago/mago-phpunit/resources/worker.php"]

[analyzer]
class-initializers = ["PHPUnit\\Framework\\TestCase::setUp"]
```

Check these settings too:

- The tests must be in `[source] paths`, and `vendor` must be in `[source] includes`, as set by
  `mago init`. The PHPUnit and Prophecy classes are read from `vendor`.
- If `[linter]` has `integrations = ["phpunit"]`, remove `"phpunit"` from that list. Otherwise the
  same code is reported twice, once by Mago's built-in rules and once by the extension's
  [linter rules](#linter-rules).
- If the project uses a framework's Mago extension that already includes these checks, leave out
  the `[extension-hosts.phpunit]` block. Mago stops with an error when the same checks are
  registered by two extensions.

Run `vendor/bin/mago extension list` from the project root to check that the extension is loaded.
Then run `vendor/bin/mago analyze` and `vendor/bin/mago lint` as usual.

The worker path is relative to the project root. The `-d` options turn off Xdebug and pcov in the
worker, since both slow it down. PHP ignores each option when Xdebug or pcov isn't installed.

With the `class-initializers` line, Mago treats `setUp()` like a constructor, so a property set
there counts as initialized. An extension can't change this setting, so it goes in your config.
Without it, a readonly property set in `setUp()` is reported as `possibly-invalid-property-write`.

## Fewer false reports

Mago tracks the type of every value through the code. After `assertNotNull($user)`, for example,
`$user` has a type without `null`, so a later `$user->getName()` isn't reported. This is called
narrowing. PHPUnit 10 and later describe most of their types in docblocks already used by Mago:
`createMock(Foo::class)` returns `MockObject&Foo`, and assertions such as `assertNotNull()`,
`assertInstanceOf()` and `assertIsString()` narrow their value. The types that can't be written
in a docblock are added by the extension.

### Assertions

These assertions narrow their value in a way that depends on another argument:

| Assertion | The value afterwards |
| --- | --- |
| `assertArrayHasKey('key', $array)` | has the key, when the key is a literal |
| `assertArrayNotHasKey('key', $array)` | doesn't have the key, when the key is a literal |
| `assertObjectHasProperty('name', $object)` | has the property, when the value is typed `object` or `mixed` |
| `assertCount(2, $list)` | has two items, when the count is an int literal |
| `assertNotCount(2, $list)` | doesn't have two items, when the count is an int literal |
| `assertNotEmpty($value)` | isn't empty |
| `assertNotSame(null, $value)` | isn't that value, when it's `null`, a bool, int or string literal, or an enum case |
| `assertGreaterThan(0, $n)`, `assertLessThan()` and their `OrEqual` forms | is in that range, when the bound is an int literal, so `assertGreaterThan(0, $n)` narrows `$n` to `positive-int` |
| `assertContains($needle, $array)` | the needle has one of the array's value types |
| `assertNotContains($needle, $array)` | the needle isn't any literal value always held by the array, such as the values of `['a', 'b']` |
| `assertContainsOnly('string', $values)` (PHPUnit 10 to 12) | holds only values of the named type. On PHPUnit 10 the name can also be a class; later versions have `assertContainsOnlyInstancesOf()` for that. |
| `assertEmpty($value)` | is empty, unless it may be a `Countable` object, a `GMP` or a `BcMath\Number` |
| `assertStringContainsString('x', $text)`, `assertStringContainsStringIgnoringCase()`, `assertStringStartsWith()` and `assertStringEndsWith()` | is a non-empty string, when the needle is a non-empty string and the value is typed as a string, `mixed`, `null` or `false` |
| `assertJson($text)` | is a non-empty string, when the value is typed as a string, `mixed`, `null` or `false` |

A value of another type, such as `int|string`, isn't narrowed, since PHP converts it to a string in
a file without strict types. A `mixed` value is narrowed, which assumes the file has strict types:
the extension can't see whether it does.

On PHPUnit 10, `assertContainsOnlyInstancesOf(Foo::class, $values)` and `assertIsList($value)`
narrow too. Later versions have their own `@phpstan-assert` tags for them, and Mago reads those.

All of these narrow through `$this->`, `self::`, `static::`, `Assert::` and PHPUnit's global
functions.

```php
$result = $this->build();                    // array{primary?: string}
$this->assertArrayHasKey('primary', $result);
return $result['primary'];
// Without the extension: possibly-undefined-string-array-index
```

### Mocks

Mocks and stubs are called doubles in PHPUnit. The extension types them in these cases.

**Intersection mocks.** PHPUnit declares `createMockForIntersectionOfInterfaces()` as returning a
plain `MockObject`, since a docblock can't build a type from a list of class names. The methods of
the interfaces then look missing. With the extension,
`createMockForIntersectionOfInterfaces([A::class, B::class])` returns `MockObject&A&B`, and
`createStubForIntersectionOfInterfaces()` returns `Stub&A&B`, when the interfaces are listed in a
literal array.

**Trait mocks.** On PHPUnit 10 and 11, `getMockForTrait()` and `getObjectForTrait()` are declared
as returning a plain `MockObject` or `object`, since a trait can't be a type in PHP. The trait's
methods then look missing. With the extension, `getMockForTrait(Greets::class)` returns
`MockObject&Greets`, and `getObjectForTrait(Greets::class)` returns `Greets`.

**Mocks typed as a union.** Older tests often type a mock as `Foo|MockObject`, from before PHP had
intersection types:

```php
/** @var Mailer|MockObject */
private $mailer;

$this->mailer->expects($this->once())->method('send');
// Without the extension: non-existent-method: Method `expects` does not exist on type `Mailer`.
```

Mago checks each type in a union on its own, and `Mailer` doesn't have `expects()`. With the
extension, the expectation isn't reported, and the mock is accepted where a `Mailer` is expected.
A call to a `Mailer` method, such as `$this->mailer->send()`, is still reported, as
`non-existent-method` on `MockObject`. An extension can't change how Mago parses the docblock.
Typing the mock as `Mailer&MockObject` fixes both.

**Mocks typed as the class alone.** A mock stored in a `Mailer` property loses its mock methods:

```php
private Mailer $mailer;   // set to $this->createMock(Mailer::class)

$this->mailer->expects($this->once())->method('send')->willReturn(true);
// Without the extension: non-existent-method on expects(), then mixed-method-access on
// method() and on willReturn().
// With it: phpunit/mock-call-on-plain-type on expects().
```

With the extension, the chain is reported once, on `expects()`. The fix, shown in the help text, is
to type the property as `Mailer&MockObject`. A real `Mailer` object gets the error too, because a
mock and a real object have the same type here. That error is correct. `expects()` fails on a real
object when the test runs.

### Prophecy

```php
$mailer = $this->prophesize(Mailer::class);
$mailer->send('a@example.com')->willReturn(true);   // not reported
$mailer->sendLater('a@example.com');                // still reported, Mailer doesn't have it
```

Prophecy sets up these calls through `__call()`, so without the extension Mago reports each one as
`non-documented-method`. With it, a call to a method of the prophesized class returns a
`MethodProphecy`. After `willImplement(Foo::class)`, the prophecy also has the methods of `Foo`.
After `willExtend(Foo::class)`, it has them instead of the prophesized class's, and keeps the
interfaces. A double of a `Traversable` interface has the `Iterator` methods added by Prophecy,
unless the interface extends `IteratorAggregate`. The calls are checked too, as listed under
[Prophecy calls](#prophecy-calls).

### Exceptions

With Mago's `check-throws` setting on (under `[analyzer]`), the exceptions thrown by PHPUnit,
`sebastian/*` and Prophecy aren't reported as unhandled. A failed assertion throws on purpose, and
PHPUnit catches it.

## Checks

Each check reports an analyzer issue with a `phpunit/` code.

| Code | Level | Reports |
| --- | --- | --- |
| `phpunit/redundant-assertion` | warning | an assertion that always passes for the value's type, such as `assertNotNull()` on a `Foo` |
| `phpunit/mock-undefined-method` | error | `method('name')` for a method that can't be replaced in the double: missing, final, private, protected, a constructor or a destructor. Also a method given to `createPartialMock()`, or to `onlyMethods()` in a chain that starts at `getMockBuilder()` with a literal class name, that isn't found by reflection |
| `phpunit/mock-added-method-exists` | error | a method given to `addMethods()` (PHPUnit 10 and 11) that already exists in the mocked class |
| `phpunit/mock-call-on-plain-type` | error | `expects()` or `method()` on a value typed without `MockObject`, when its class doesn't declare that method |
| `phpunit/mock-invalid-return` | error, or warning for a value that fits in part | a value returned by a double, set up with `will()` or a `willReturn*()` method, that doesn't fit the method's return type, listed under [Mock return values](#mock-return-values) |
| `phpunit/prophecy-union` | warning | a type documented as `Foo\|ObjectProphecy`, which should be `ObjectProphecy<Foo>` |
| `phpunit/prophecy-invalid-return` | error, or warning in some cases | a value that the prophesized method can't return, listed under [Prophecy calls](#prophecy-calls) |
| `phpunit/prophecy-invalid-double` | error | a class that Prophecy can't double, such as a final class |
| `phpunit/prophecy-invalid-setup` | error | a method that Prophecy can't set up, such as a final or a private method |
| `phpunit/prophecy-unknown-type` | error | a name given to `Argument::type()` that isn't a type or a class |
| `phpunit/prophecy-token-mismatch` | error, or warning for a docblock type | an argument token that can never match its parameter, listed under [Prophecy calls](#prophecy-calls) |
| `phpunit/assert-same-count` | warning | `assertSame($n, count($value))` or `assertSame($n, $value->count())`, where `assertCount()` is clearer |
| `phpunit/invalid-data-provider` | error, or warning in two cases | a data provider or data set that PHPUnit can't use, listed under [Data providers](#data-providers) |
| `phpunit/data-provider-mismatch` | error, or warning in one case | a data set that doesn't fit the test's parameters, listed under [Data providers](#data-providers) |
| `phpunit/dependency-mismatch` | error | a result of a test's dependency that doesn't fit its parameter, listed under [Data providers](#data-providers) |
| `phpunit/missing-parent-call` | warning | a `setUp()` or `tearDown()` that doesn't call the parent's, when a parent class other than `TestCase` has one |
| `phpunit/invalid-covers` | warning | a covers or uses tag or attribute that can't be used by PHPUnit, listed under [Coverage](#coverage) |
| `phpunit/redundant-covers` | warning | a method's `@covers Foo` when the class already has `@covers Foo` |
| `phpunit/requires-without-operator` | warning | a version such as `#[RequiresPhp('8.2')]` without an operator like `>=` |
| `phpunit/incomplete-requires-version` | warning | a compared version without all of a major, minor and patch level, such as `'>= 8.1'` or `'8.1dev'`, on PHPUnit 12.5 and later, where PHPUnit warns about it |
| `phpunit/annotation-without-value` | warning | an annotation without a space and a value, such as `@covers\Foo` (ignored by PHPUnit) or a bare `@dataProvider` (PHPUnit fails the test, also reported as `invalid-data-provider`) |

To turn a check off, list its code under Mago's `[analyzer] ignore`, for the whole project or for
some paths:

```toml
[analyzer]
ignore = [
    "phpunit/assert-same-count",
    { code = "phpunit/missing-parent-call", in = "tests/Legacy/" },
]
```

To silence one report, put a comment with the code on the line above it. `analysis:` is the
category of analyzer issues:

```php
// @mago-ignore analysis:phpunit/mock-undefined-method
$mailer->method('sendLater');
```

By default, `mago analyze` only fails on errors. Pass `--minimum-fail-level=warning` to fail on the
warnings too.

### Mock return values

```php
interface Clock
{
    public function now(): int;
}

$clock = $this->createStub(Clock::class);
$clock->method('now')->willReturn('12:00');
// phpunit/mock-invalid-return: `willReturn()` gets `'12:00'`, but `App\Clock::now()` returns `int`, so PHPUnit throws.
```

PHPUnit compares each value given to `willReturn()` with the native return type of the method, and
throws an `IncompatibleReturnValueException` for a value that doesn't fit. Scalars aren't
converted, so `1` doesn't fit a `float` and `'5'` doesn't fit an `int`. A `void` or `never`
method doesn't take any value, not even `null`. The extension reports the same values as errors.
A value that fits only in part, such as an `?int` for an `int`, is a warning.

A method without a native return type takes any value, and so does a `mixed` or `callable` one.
An object is reported when its class can't be the returned one. That's the case for a double of
another class created right there, such as `$this->createStub(Foo::class)`, for an enum case, and
for an instance of an unrelated class when both are classes. An object typed as an interface
isn't reported, since its class may implement the returned one too. A double typed `MockObject`
alone, or with a wider type than its class, isn't reported either.

PHPUnit doesn't check the values given to `willReturnOnConsecutiveCalls()`, or the values
returned by a stub. It also takes any `willReturn()` value for a union of two or more types and
`null`, such as `int|string|null`, for an intersection with `null`, and for `null` alone, and any
stub for a method that returns any object. The double returns these values, and its code uses
strict types, so PHP throws a `TypeError` for a value that doesn't fit. These values are reported
with the same rules, except that an `int` fits a `float` and a value for an intersection type
isn't checked.

A stub is called first. It's given to `will()` or `willReturnOnConsecutiveCalls()`, or created by
`willReturnCallback()`, `willReturnArgument()`, `willReturnSelf()`, `willReturnMap()` or a
`TestCase` method such as `returnValue()`:

- `ReturnStub` returns its value, and `ReturnSelf` returns the double.
- `ReturnArgument` returns the argument, of the parameter's native type. A variadic parameter takes
  the index and those after it. An index past the parameters gets `null`, unless the call passes
  more arguments, so it's a warning.
- `ReturnCallback` returns the callback's result: the return type of a closure or a first-class
  callable, declared or inferred, or the native return type of the function or method it names.
- `ReturnValueMap` returns the last value of a matching row. A row matches a call when it has one
  value for each parameter plus the returned value, and `willReturnMap()` fills in a short row with
  the parameters' defaults. A map without such a row returns `null`, unless it's strict, as created by `willReturnStrictMap()`
  or with the `$strict` argument of PHPUnit 13.
- `ConsecutiveCalls` returns its values in turn, and calls the stubs among them.

An unpacked array that's written out, such as `...[1, 2]`, gives its values. The results of other
stubs aren't checked. A `void` method drops the values. A `never` method is reported when the
double gets a value, other than from a stub that throws, since the double then ends without
throwing.

### Prophecy calls

```php
interface Mailer
{
    public function send(string $address): bool;
}

$mailer->send(['a@example.com']);                   // invalid-argument
$mailer->send();                                    // too-few-arguments
$mailer->send('a@example.com')->willReturn('sent');
// phpunit/prophecy-invalid-return: `willReturn()` gets `string`, but `App\Mailer::send()` returns `bool`.
```

The arguments are checked by Mago against the prophesized method's parameters. A parameter also
takes an argument token (any arguments after `Argument::cetera()`), a prophecy of an object it
takes, also inside an array, and the values that Prophecy compares loosely with it: a number or a
`Stringable` object for a string, a numeric string or the other kind of number for a number, a
string or a number for an object with `__toString()`, and any `DateTimeInterface` for a date. A
setup with a required argument
missing is reported, also with `shouldNotBeCalled()`. It never matches a call, so such a test only
passes through Prophecy's error for an unexpected call. An extra argument is reported too, though
the setup still matches a call that passes it. `Argument::cetera()` matches any arguments.

An argument token that can never match its parameter is reported as `prophecy-token-mismatch`,
such as `Argument::type('int')` or `Argument::containingString('x')` for an `int` parameter,
`Argument::is(5)` for a `string` one, or `Argument::that(fn(Foo $foo) => ...)` when the parameter
takes a `Bar`. A callback is called without strict types, so a scalar parameter type that PHP
converts to, such as `string` for an `int` argument, is fine. A token that matches only some of the
arguments, such as a callback for a subclass, isn't reported. The issue is an error when the native
parameter type rules the token out, and a warning when only the docblock type does.

`willReturn()` values are checked against the return type, also after calls such as
`->shouldBeCalled()`. The value is an error when it fails with a `TypeError`. It's a warning when
it only breaks a docblock type, when PHP converts it to the native type (the double isn't in
strict mode, so `'5'` becomes an `int`), when it fits in part, such as `?int` for an `int`, or
when it's wider, such as `object` for a `Foo`. The parameter returned by `willReturnArgument()`
is checked the same way, as a warning when the parameter type only overlaps the return type, and
an index past the method's parameters returns null unless the call passes an extra argument. A
negative index is an error, since Prophecy throws for it. The items given
to `willYield()` are checked against the type the method yields, as a warning. Prophecy doesn't
reveal the prophecies in them. These are errors too, since Prophecy throws for them or the double
fails:

- `willReturn()`, `willReturnArgument()` or `willYield()` on a `void` or `never` method.
- `prophesize()` of a final class, an enum or a trait, `willExtend()` of an interface and
  `willImplement()` of a class. A class marked `@final` only in its docblock isn't reported.
- Setting up a final method, or a method with an intersection in its native return type.
- Setting up a method that isn't replaced in the double, so the setup never takes effect: a private
  method, a protected one that isn't abstract, or a class's method whose name starts with `_`.
  Prophecy does replace `__construct()`, `__destruct()`, `__sleep()`, `__wakeup()`,
  `__toString()`, `__call()` and `__invoke()`, the methods of an interface given to
  `prophesize()` or `willImplement()`, and the methods of `@method` tags.
- Setting up a static method. The double's static method fails when it's called.
- `willYield()` on a method whose native return type can't hold a `Generator`.
- `Argument::type()` with a name that isn't a class or a type with an `is_*()` function.

A prophecy documented without its class, such as `@var ObjectProphecy`, isn't checked.

### Data providers

```php
public static function cases(): iterable
{
    yield [1, 'one'];
    yield ['two', 'two'];
    // phpunit/data-provider-mismatch: Data set #1 has a value of type string for $number, but
    // the parameter takes int.
}

#[DataProvider('cases')]
public function testCase(int $number, string $name): void {}
```

A test's providers are found through its `DataProvider` and `DataProviderExternal` attributes, its
`@dataProvider` tags, and the closures of its `DataProviderClosure` attributes on PHPUnit 13.1 and
later. An `invalid-data-provider` error is reported for:

- a provider that is missing, isn't public or static, or takes arguments;
- a closure provider with a required parameter, or with a return type that isn't iterable, and a
  first-class callable such as `self::cases(...)` of a method that isn't static;
- a bare `@dataProvider` tag;
- a data set that isn't an array, or that has the key `''` on PHPUnit 13;
- the same string key in the data sets of two of a test's providers;
- a provider without any data sets, when all of the test's providers are in its class and all are
  empty. A provider attribute with `skipWhenEmpty` set to true skips the test on PHPUnit 13, so
  it isn't reported then;
- two `#[TestWith]` data sets with the same key, on PHPUnit 11 and later;
- a `#[TestWithJson]` or `@testWith` data set that isn't a valid JSON array, and a `@testWith` tag
  without rows.

PHPUnit only warns about two cases, so they're `invalid-data-provider` warnings:

- a data provider that is also a test method;
- a `#[TestWith*]` attribute next to a `#[DataProvider*]` attribute, on PHPUnit 12.5 and later.
  PHPUnit ignores the `#[TestWith*]` data sets then.

For a provider in the same class or a closure, the data sets are also matched against the test's
parameters, following PHPUnit's rules for passing them. A data set that doesn't fit is a
`data-provider-mismatch` error:

- The values go to the parameters in order. From PHPUnit 11 on, a value under a string key goes to
  the parameter of that name.
- A test that depends on other tests, through an attribute or a `@depends` tag, gets their results
  after the data. From PHPUnit 11 on, a test that returns `void` or `never` doesn't pass a result.
  PHPUnit 11 stops at the first such dependency, and PHPUnit 12 skips each one.
- A data set built at runtime, such as one from a loop, is checked as far as its type is known. A
  value that may fit, such as a `mixed` one, isn't reported.
- On PHPUnit 12.5 and later, a data set with more values than the test has parameters is a
  warning, unless the provider is declared with `#[DataProvider('cases', false)]`. PHPUnit doesn't
  allow named arguments for its attributes.

The data sets of `#[TestWith]` and `#[TestWithJson]` attributes, and of `@testWith` tags on PHPUnit
10 and 11, are matched the same way. PHPUnit ignores them on a test with a data provider, and so
does the extension. For a `#[TestWithJson]` attribute with JSON that isn't valid, PHPUnit stops the
whole run, even on a test with a data provider.

The result of each dependency is matched with the parameter it fills, and a result that doesn't fit
is a `dependency-mismatch` error. PHPUnit runs the test with strict types, so the test fails with a
`TypeError`. The result has the native return type of the test it comes from. A test that calls
`expectException()` may pass null instead, so null is taken into account for it. A test without a
native return type isn't checked. On PHPUnit 10, a test that returns `void` passes null. The results
fill the first parameters of a test without data. In a test with data, they come after the values of
each data set. The results after a data set with string keys aren't checked on PHPUnit 11 and later,
since PHPUnit fails the test. A dependency named by a constant, such as `#[Depends(self::TARGET)]`,
is resolved. A dependency that can't be used by PHPUnit, such as a method name in another case,
isn't checked. Neither are the tests of a class with its own `invokeTestMethod()` or a test declared
in a trait.

### Coverage

An `invalid-covers` warning is reported for:

- a covers tag or attribute for code that doesn't exist, or for a class, method or function built
  into PHP;
- a covers tag for an interface, and `#[CoversClass]` for an interface on PHPUnit 11 and later;
- on PHPUnit 12 and later, `#[CoversClass]` for a trait, `#[CoversMethod]` for an interface method,
  `#[CoversMethod(Foo::class, 'bar')]` when `bar()` isn't declared in `Foo` itself, and a target
  written with a leading backslash;
- a `@coversDefaultClass` tag on a method, given twice, or naming something that isn't a class;
- `@covers ::name` for a name that isn't a function, when the class doesn't have
  `@coversDefaultClass`.

PHPUnit warns about each of these and leaves the test out of the coverage report.

The `#[UsesClass]`, `#[UsesTrait]`, `#[UsesMethod]` and `#[UsesFunction]` attributes, and the
`@uses` and `@usesDefaultClass` tags on PHPUnit 10 and 11, are checked with the same rules. PHPUnit
checks their targets like covers targets. A `@uses` tag for an interface is accepted. Method tags
are only read on test methods, and a class or test with `CoversNothing` isn't checked, since PHPUnit
doesn't collect coverage for it. On PHPUnit 10 and 11, a test's own `@covers` tag overrides the
class's `CoversNothing`.

### Annotations

PHPUnit 12 and later ignore annotations such as `@covers` and `@dataProvider`, and PHPUnit 10 and 11
ignore them on a class or method that also has a PHPUnit attribute. The checks follow the same
rules, so annotations are only checked on PHPUnit 10 and 11, where PHPUnit would use them.

## Linter rules

The extension also has the PHPUnit rules of Mago's linter, so they don't depend on Mago's built-in
`phpunit` integration. They run with `mago lint`. The assertion rules check test methods. A test
method is named `test` followed by `_` or an uppercase letter, or has the `#[Test]` attribute or a
`@test` tag. `phpunit/no-assert-empty` and `phpunit/data-provider-name-case` match opt-in checks of
phpstan-phpunit, and aren't limited to test methods.

| Code | Default | Reports |
| --- | --- | --- |
| `phpunit/strict-assertions` | on | `assertEquals()` or `assertNotEquals()` with an int, float or string literal, instead of `assertSame()` or `assertNotSame()` |
| `phpunit/use-specific-assertions` | on | `assertSame(null, $x)`, `assertEquals(true, $x)` and similar, instead of `assertNull()`, `assertTrue()` or `assertFalse()` |
| `phpunit/assertion-style-static` | on | an assertion called with `$this->` or `self::` instead of `static::` |
| `phpunit/assertion-style-self` | off | an assertion called with `$this->` or `static::` instead of `self::` |
| `phpunit/assertion-style-this` | off | an assertion called with `static::` or `self::` instead of `$this->` |
| `phpunit/prefer-test-attribute` | off | a public method named `testFoo()` instead of `foo()` with `#[Test]` |
| `phpunit/no-assert-empty` | off | `assertEmpty()` or `assertNotEmpty()`, with which `0`, `'0'`, `''`, `[]`, `null` and `false` all count as empty |
| `phpunit/data-provider-name-case` | off | a `#[DataProvider]` attribute or `@dataProvider` tag naming a method of the same class in another case |

The style rules also cover helpers called the same way, such as `markTestSkipped()` and constraint
factories like `isTrue()`. A `parent::` call isn't reported.

All of them are warnings. Each comes with a fix, except `no-assert-empty`, since the stricter
assertion depends on the value. Most fixes can change what a test does, so `mago fix` only applies
them with `--potentially-unsafe`. Two fixes are applied by default. The first replaces
`assertSame()` against `null`, `true` or `false`, and `assertNotSame()` against `null`. The second
fixes the case of a data provider name. A `use-specific-assertions` report on a call with named or
unpacked arguments doesn't have a fix, since the dedicated assertion doesn't have the same
parameter names.

An extension's lint rule can be turned on or off and given a level, but doesn't take other options.
So each assertion style is its own rule. To use `$this->`, switch the rules:

```toml
[linter.rules]
"phpunit/assertion-style-static" = { enabled = false }
"phpunit/assertion-style-this" = { enabled = true }
```

Any rule can be turned off with `enabled = false` in the same way. To silence one report, put a
comment with the code on the line above it. `lint:` is the category of linter issues:

```php
// @mago-ignore lint:phpunit/strict-assertions
$this->assertEquals(1, $count);
```

If you're switching from Mago's built-in rules, add the `phpunit/` prefix to the codes in
`@mago-expect` and `@mago-ignore` comments: `lint:strict-assertions` becomes
`lint:phpunit/strict-assertions`. The built-in `assertion-style` rule with `style = "this"` becomes
`phpunit/assertion-style-this`. The built-in assertion rules only check methods named `test...`.

## Compared with phpstan-phpunit

Everything in phpstan-phpunit's default configuration (`extension.neon` and `rules.neon`) has a
counterpart, and so do its bleeding-edge rules for empty data providers and incomplete versions.
`assertSame(true, ...)`, `assertSame(null, ...)` and `assertEquals()` are reported by the
[linter rules](#linter-rules). The differences:

- A `Foo|MockObject` docblock stays a union in Mago. See [mocks typed as a union](#mocks).
- `#[TestWith]`, `#[TestWithJson]` and `@testWith` data sets are matched against the test's
  parameters, and their JSON is checked. phpstan-phpunit doesn't check them.
- `assertContainsEquals()` and `assertNotContainsEquals()` don't narrow, since they compare loosely
  and `'1'` equals `1`.
- `#[RequiresPhp]` versions that can never match the PHP version aren't reported, since Mago
  analyzes for one PHP version and a test suite usually runs on several.
- The opt-in checks for `assertEmpty()` (with phpstan-strict-rules) and for a `@dataProvider` name
  in the wrong case (`checkFunctionNameCase`) are the linter rules `phpunit/no-assert-empty` and
  `phpunit/data-provider-name-case`, off by default.
- `getMockForTrait()` and `getObjectForTrait()` objects are typed with the trait. phpstan-phpunit
  doesn't type them.

## Known limitations

- On PHPUnit 10, `assertEmpty()` has a `@psalm-assert empty $actual` tag, and Mago applies it. An
  object checked by `assertEmpty()` is then narrowed to `never`. PHPUnit 11 and later don't have
  the tag.
- `willReturn()` isn't checked when the `MethodProphecy` is kept in a variable first, as in
  `$send = $mailer->send('a@example.com'); $send->willReturn(true);`.
- An `Argument::cetera()` token kept in a variable isn't recognized, so the arguments of a call
  that passes the variable are checked as if it were one argument. Mago doesn't give an extension
  the argument types at that point.
- The calls on a value typed as a prophecy of one class or another, such as
  `ObjectProphecy<A>|ObjectProphecy<B>`, aren't checked when the classes have different methods of
  that name.
- Prophecy matches named arguments by their position. Mago matches them by their name, so a named
  argument in another order than the parameters isn't reported.
- The parameter and return types that depend on a template, such as `T` in a generic class, aren't
  checked in Prophecy calls.
- `$prophecy->willExtend(Foo::class);` on its own line types the prophecy as both the old class and
  `Foo`, because Mago can narrow a variable's type but not remove part of it. A chained call gets
  `Foo` alone.
- Data sets are only checked for providers declared in the test's own class. Inherited providers,
  providers in a trait and `DataProviderExternal` providers aren't.
- A protected method isn't reported by `mock-undefined-method` when the file calls
  `getMockBuilder()`, `createPartialMock()`, `onlyMethods()`, `getMockForTrait()` or
  `getMockForAbstractClass()`, since the double may be built with one of them in a helper. On
  PHPUnit 10 and 11, a missing method isn't reported when the file calls `addMethods()`,
  `getMockForTrait()` or `getMockForAbstractClass()`, for the same reason.

## For extension authors

Another extension, such as a framework's, can run these checks in its own worker by registering
`PHPUnitExtension::plugin()` next to its own analyzer plugins, and `PHPUnitExtension::linterRules()`
next to its own linter rules:

```php
use MagoPHPUnit\PHPUnitExtension;

$plugins = [new MyPlugin(), PHPUnitExtension::plugin()];
$rules = [new MyRule(), ...PHPUnitExtension::linterRules()];
```

Projects that use that extension then leave out the `phpunit` host from the setup above.

## Development

`just check-ci` runs every check. See [CONTRIBUTING.md](CONTRIBUTING.md) for the test corpus and
the other checks.

## License

MIT. See [LICENSE](LICENSE).
