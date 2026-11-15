<?php

class FooTest {
    private function testPrivateHelper(): void {}
    protected function testProtectedHelper(): void {}
    PROTECTED static function testUppercaseModifier(): void {}

    // @mago-expect lint:phpunit/prefer-test-attribute
    function testWithoutModifier(): void {}

    // @mago-expect lint:phpunit/prefer-test-attribute
    final public static function testPublicStatic(): void {}
}
