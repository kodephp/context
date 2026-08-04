<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\ContextException;
use Kode\Context\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * 类型安全访问器与便捷 API 测试
 */
class AccessorTest extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    // ==================== 类型安全访问器 ====================

    public function testGetString(): void
    {
        Context::set('name', 'kode');

        $this->assertSame('kode', Context::getString('name'));
        $this->assertNull(Context::getString('missing'));
        $this->assertSame('fallback', Context::getString('missing', 'fallback'));
    }

    public function testGetStringRejectsWrongType(): void
    {
        Context::set('name', 123);

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage("上下文键 'name' 的值不是 string 类型，实际类型为 int");
        Context::getString('name');
    }

    public function testGetIntAcceptsNumericStringAndWholeFloat(): void
    {
        Context::set('a', 42);
        Context::set('b', '42');
        Context::set('c', '-42');
        Context::set('d', 42.0);

        $this->assertSame(42, Context::getInt('a'));
        $this->assertSame(42, Context::getInt('b'));
        $this->assertSame(-42, Context::getInt('c'));
        $this->assertSame(42, Context::getInt('d'));
        $this->assertSame(7, Context::getInt('missing', 7));
    }

    public function testGetIntRejectsLossyValues(): void
    {
        Context::set('a', 4.2);

        $this->expectException(ContextException::class);
        Context::getInt('a');
    }

    public function testGetIntRejectsNonNumericString(): void
    {
        Context::set('a', '12abc');

        $this->expectException(ContextException::class);
        Context::getInt('a');
    }

    public function testGetFloat(): void
    {
        Context::set('a', 1.5);
        Context::set('b', 2);
        Context::set('c', '3.25');

        $this->assertSame(1.5, Context::getFloat('a'));
        $this->assertSame(2.0, Context::getFloat('b'));
        $this->assertSame(3.25, Context::getFloat('c'));
        $this->assertNull(Context::getFloat('missing'));
    }

    public function testGetFloatRejectsWrongType(): void
    {
        Context::set('a', ['x']);

        $this->expectException(ContextException::class);
        Context::getFloat('a');
    }

    public function testGetBoolAcceptsCommonRepresentations(): void
    {
        Context::set('a', true);
        Context::set('b', 0);
        Context::set('c', 'yes');
        Context::set('d', 'off');
        Context::set('e', 'true');

        $this->assertTrue(Context::getBool('a'));
        $this->assertFalse(Context::getBool('b'));
        $this->assertTrue(Context::getBool('c'));
        $this->assertFalse(Context::getBool('d'));
        $this->assertTrue(Context::getBool('e'));
        $this->assertTrue(Context::getBool('missing', true));
    }

    public function testGetBoolRejectsAmbiguousValue(): void
    {
        Context::set('a', 'maybe');

        $this->expectException(ContextException::class);
        Context::getBool('a');
    }

    public function testGetArray(): void
    {
        Context::set('a', ['x' => 1]);

        $this->assertSame(['x' => 1], Context::getArray('a'));
        $this->assertSame([], Context::getArray('missing', []));
    }

    public function testGetArrayRejectsWrongType(): void
    {
        Context::set('a', 'str');

        $this->expectException(ContextException::class);
        Context::getArray('a');
    }

    public function testGetOrFail(): void
    {
        Context::set('nullable', null);

        $this->assertNull(Context::getOrFail('nullable'), '显式写入的 null 视为存在');

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage("上下文键 'missing' 不存在");
        Context::getOrFail('missing');
    }

    // ==================== 便捷操作 ====================

    public function testGetOrSetOnlyInvokesFactoryOnce(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): string {
            $calls++;

            return 'built';
        };

        $this->assertSame('built', Context::getOrSet('k', $factory));
        $this->assertSame('built', Context::getOrSet('k', $factory));
        $this->assertSame(1, $calls);
    }

    public function testAdd(): void
    {
        $this->assertTrue(Context::add('k', 1));
        $this->assertFalse(Context::add('k', 2));
        $this->assertSame(1, Context::get('k'));
    }

    public function testPull(): void
    {
        Context::set('k', 'v');

        $this->assertSame('v', Context::pull('k'));
        $this->assertFalse(Context::has('k'));
        $this->assertSame('def', Context::pull('k', 'def'));
    }

    public function testIncrementAndDecrement(): void
    {
        $this->assertSame(1, Context::increment('counter'));
        $this->assertSame(4, Context::increment('counter', 3));
        $this->assertSame(3, Context::decrement('counter'));
        $this->assertEqualsWithDelta(3.5, Context::increment('counter', 0.5), 0.0001);
    }

    public function testIncrementRejectsNonNumeric(): void
    {
        Context::set('counter', 'abc');

        $this->expectException(ContextException::class);
        Context::increment('counter');
    }

    public function testPush(): void
    {
        Context::push('list', 'a');
        Context::push('list', 'b', 'c');

        $this->assertSame(['a', 'b', 'c'], Context::get('list'));
    }

    public function testPushRejectsNonArray(): void
    {
        Context::set('list', 'oops');

        $this->expectException(ContextException::class);
        Context::push('list', 'a');
    }

    public function testOnlyAndExcept(): void
    {
        Context::merge(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'c' => 3], Context::only(['a', 'c']));
        $this->assertSame(['b' => 2], Context::except(['a', 'c']));
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue(Context::isEmpty());

        Context::set('a', 1);
        $this->assertFalse(Context::isEmpty());

        Context::clear();
        $this->assertTrue(Context::isEmpty());
    }

    // ==================== 监听器增强 ====================

    public function testWildcardListener(): void
    {
        $seen = [];
        Context::listen(Context::WILDCARD, function (string $key) use (&$seen): void {
            $seen[] = $key;
        });

        Context::set('a', 1);
        Context::set('b', 2);
        Context::delete('a');

        $this->assertSame(['a', 'b', 'a'], $seen);
    }

    public function testListenReturnsIdForPreciseRemoval(): void
    {
        $first = 0;
        $second = 0;

        $id1 = Context::listen('k', function () use (&$first): void {
            $first++;
        });
        Context::listen('k', function () use (&$second): void {
            $second++;
        });

        Context::set('k', 1);
        $this->assertSame(1, $first);
        $this->assertSame(1, $second);

        Context::unlisten('k', $id1);
        Context::set('k', 2);

        $this->assertSame(1, $first, '已移除的监听器不应再被触发');
        $this->assertSame(2, $second);
    }

    public function testUnlistenAllForKey(): void
    {
        Context::listen('k', static fn (): bool => true);
        $this->assertSame(['k'], Context::listenedKeys());

        Context::unlisten('k');
        $this->assertSame([], Context::listenedKeys());
    }

    public function testListenerExceptionDoesNotBreakWrite(): void
    {
        Context::listen('k', static function (): void {
            throw new \RuntimeException('boom');
        });

        Context::set('k', 'v');

        $this->assertSame('v', Context::get('k'));
    }

    // ==================== 运行时枚举 ====================

    public function testRuntimeEnum(): void
    {
        $runtime = Context::runtime();

        $this->assertInstanceOf(Runtime::class, $runtime);
        $this->assertSame(Context::getRuntime(), $runtime->value);
        $this->assertNotSame('', $runtime->label());
    }

    public function testRuntimeEnumSemantics(): void
    {
        $this->assertTrue(Runtime::Fiber->isCoroutine());
        $this->assertTrue(Runtime::Swoole->isCoroutine());
        $this->assertFalse(Runtime::Process->isCoroutine());

        $this->assertTrue(Runtime::Thread->sharesMemory());
        $this->assertFalse(Runtime::Process->sharesMemory());
        $this->assertFalse(Runtime::Sync->sharesMemory());
    }

    public function testResetClearsListenersAndData(): void
    {
        Context::set('k', 'v');
        Context::listen('k', static fn (): bool => true);

        Context::reset();

        $this->assertSame([], Context::listenedKeys());
        $this->assertSame(0, Context::count());
        $this->assertFalse(Context::isPostFork());
    }
}
