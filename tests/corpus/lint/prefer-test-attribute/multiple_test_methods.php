<?php

class FooTest {
    // @mago-expect lint:phpunit/prefer-test-attribute
    public function testOne(): void {}
    // @mago-expect lint:phpunit/prefer-test-attribute
    public function testTwo(): void {}
}
