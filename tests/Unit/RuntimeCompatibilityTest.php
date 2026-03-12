<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use Kode\Context\ContextException;
use PHPUnit\Framework\TestCase;

/**
 * 运行时兼容性测试
 */
class RuntimeCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        Context::reset();
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    /**
     * 测试基本功能在各种环境下的兼容性
     */
    public function testBasicFunctionality(): void
    {
        Context::set('user_id', 123);
        Context::set('trace_id', 'trace_abc123');
        Context::set('request_data', ['method' => 'GET', 'uri' => '/test']);

        $this->assertEquals(123, Context::get('user_id'));
        $this->assertEquals('trace_abc123', Context::get('trace_id'));
        $this->assertEquals(['method' => 'GET', 'uri' => '/test'], Context::get('request_data'));

        $this->assertTrue(Context::has('user_id'));
        $this->assertFalse(Context::has('nonexistent'));

        $snapshot = Context::copy();
        $this->assertArrayHasKey('user_id', $snapshot);
        $this->assertArrayHasKey('trace_id', $snapshot);
        $this->assertArrayHasKey('request_data', $snapshot);
        $this->assertEquals(123, $snapshot['user_id']);
        $this->assertEquals('trace_abc123', $snapshot['trace_id']);
    }

    /**
     * 测试delete方法
     */
    public function testDelete(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));

        Context::delete('key1');

        $this->assertFalse(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));
        $this->assertNull(Context::get('key1'));
        $this->assertEquals('value2', Context::get('key2'));
    }

    /**
     * 测试clear方法
     */
    public function testClear(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');
        Context::set('key3', 'value3');

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));
        $this->assertTrue(Context::has('key3'));

        Context::clear();

        $this->assertFalse(Context::has('key1'));
        $this->assertFalse(Context::has('key2'));
        $this->assertFalse(Context::has('key3'));
        $this->assertEmpty(Context::copy());
    }

    /**
     * 测试run方法的上下文隔离
     */
    public function testRunIsolation(): void
    {
        Context::set('outer_key', 'outer_value');
        Context::set('shared_key', 'outer_shared');

        $innerResult = Context::run(function () {
            $this->assertFalse(Context::has('outer_key'));
            $this->assertFalse(Context::has('shared_key'));

            Context::set('inner_key', 'inner_value');
            Context::set('shared_key', 'inner_shared');

            $this->assertTrue(Context::has('inner_key'));
            $this->assertTrue(Context::has('shared_key'));
            $this->assertEquals('inner_value', Context::get('inner_key'));
            $this->assertEquals('inner_shared', Context::get('shared_key'));

            return [
                'inner_key' => Context::get('inner_key'),
                'shared_key' => Context::get('shared_key')
            ];
        });

        $this->assertIsArray($innerResult);
        $this->assertEquals('inner_value', $innerResult['inner_key']);
        $this->assertEquals('inner_shared', $innerResult['shared_key']);

        $this->assertTrue(Context::has('outer_key'));
        $this->assertTrue(Context::has('shared_key'));
        $this->assertEquals('outer_value', Context::get('outer_key'));
        $this->assertEquals('outer_shared', Context::get('shared_key'));

        $this->assertFalse(Context::has('inner_key'));
    }

    /**
     * 测试fork方法的上下文继承
     */
    public function testForkInheritance(): void
    {
        Context::set('outer_key', 'outer_value');
        Context::set('shared_key', 'outer_shared');

        $innerResult = Context::fork(function () {
            $this->assertTrue(Context::has('outer_key'));
            $this->assertTrue(Context::has('shared_key'));
            $this->assertEquals('outer_value', Context::get('outer_key'));
            $this->assertEquals('outer_shared', Context::get('shared_key'));

            Context::set('inner_key', 'inner_value');
            Context::set('shared_key', 'inner_shared');

            $this->assertEquals('inner_shared', Context::get('shared_key'));

            return [
                'inner_key' => Context::get('inner_key'),
                'shared_key' => Context::get('shared_key')
            ];
        });

        $this->assertIsArray($innerResult);
        $this->assertEquals('inner_value', $innerResult['inner_key']);
        $this->assertEquals('inner_shared', $innerResult['shared_key']);

        $this->assertTrue(Context::has('outer_key'));
        $this->assertTrue(Context::has('shared_key'));
        $this->assertEquals('outer_value', Context::get('outer_key'));
        $this->assertEquals('outer_shared', Context::get('shared_key'));

        $this->assertFalse(Context::has('inner_key'));
    }

    /**
     * 测试复杂数据类型的存储和获取
     */
    public function testComplexDataTypes(): void
    {
        $obj = new \stdClass();
        $obj->property = 'test';
        Context::set('object', $obj);
        $this->assertSame($obj, Context::get('object'));

        $array = ['key' => 'value', 'nested' => ['a', 'b', 'c']];
        Context::set('array', $array);
        $this->assertEquals($array, Context::get('array'));

        $closure = function ($x) { return $x * 2; };
        Context::set('closure', $closure);
        $retrievedClosure = Context::get('closure');
        $this->assertIsCallable($retrievedClosure);
        $this->assertEquals(10, $retrievedClosure(5));
    }

    /**
     * 测试运行时检测
     */
    public function testRuntimeDetection(): void
    {
        $runtime = Context::getRuntime();

        $validRuntimes = [
            Context::RUNTIME_FIBER,
            Context::RUNTIME_SWOOLE,
            Context::RUNTIME_SWOW,
            Context::RUNTIME_SYNC,
        ];

        $this->assertContains($runtime, $validRuntimes);
    }

    /**
     * 测试协程检测
     */
    public function testCoroutineDetection(): void
    {
        $isCoroutine = Context::isCoroutine();
        $runtime = Context::getRuntime();

        if ($runtime === Context::RUNTIME_SYNC) {
            $this->assertFalse($isCoroutine);
        } else {
            $this->assertTrue($isCoroutine);
        }
    }

    /**
     * 测试协程ID获取
     */
    public function testCoroutineId(): void
    {
        $id = Context::getCoroutineId();
        $runtime = Context::getRuntime();

        if ($runtime === Context::RUNTIME_SYNC) {
            $this->assertNull($id);
        } else {
            $this->assertNotNull($id);
        }
    }

    /**
     * 测试restore方法
     */
    public function testRestore(): void
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $snapshot = Context::copy();

        Context::clear();
        Context::set('key3', 'value3');

        Context::restore($snapshot);

        $this->assertTrue(Context::has('key1'));
        $this->assertTrue(Context::has('key2'));
        $this->assertFalse(Context::has('key3'));
    }

    /**
     * 测试监听器在复杂数据变更时的行为
     */
    public function testListenerWithComplexChanges(): void
    {
        $changes = [];

        Context::listen('data', function ($key, $oldValue, $newValue) use (&$changes) {
            $changes[] = compact('key', 'oldValue', 'newValue');
        });

        $data1 = ['a' => 1, 'b' => 2];
        Context::set('data', $data1);
        $this->assertCount(1, $changes);

        $data2 = ['a' => 1, 'b' => 3];
        Context::set('data', $data2);
        $this->assertCount(2, $changes);

        Context::delete('data');
        $this->assertCount(3, $changes);

        $this->assertEquals($data1, $changes[0]['newValue']);
        $this->assertEquals($data1, $changes[1]['oldValue']);
        $this->assertEquals($data2, $changes[1]['newValue']);
        $this->assertEquals($data2, $changes[2]['oldValue']);
        $this->assertNull($changes[2]['newValue']);
    }

    /**
     * 测试getOfType方法
     */
    public function testGetOfType(): void
    {
        $object = new \stdClass();
        Context::set('object', $object);

        $result = Context::getOfType('object', \stdClass::class);
        $this->assertSame($object, $result);
    }

    /**
     * 测试getOfType方法抛出异常
     */
    public function testGetOfTypeThrowsExceptionForMissingKey(): void
    {
        $this->expectException(ContextException::class);
        Context::getOfType('nonexistent', \stdClass::class);
    }

    /**
     * 测试getOfType方法类型不匹配异常
     */
    public function testGetOfTypeThrowsExceptionForTypeMismatch(): void
    {
        Context::set('string', 'not an object');

        $this->expectException(ContextException::class);
        Context::getOfType('string', \stdClass::class);
    }

    /**
     * 测试大量数据操作
     */
    public function testLargeDataOperations(): void
    {
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data['key_' . $i] = 'value_' . $i;
        }

        Context::merge($data);

        $this->assertEquals(1000, Context::count());

        for ($i = 0; $i < 1000; $i++) {
            $this->assertEquals('value_' . $i, Context::get('key_' . $i));
        }

        Context::clear();
        $this->assertEquals(0, Context::count());
    }

    /**
     * 测试嵌套fork
     */
    public function testNestedFork(): void
    {
        Context::set('level0', 'value0');

        Context::fork(function () {
            $this->assertTrue(Context::has('level0'));
            Context::set('level1', 'value1');

            Context::fork(function () {
                $this->assertTrue(Context::has('level0'));
                $this->assertTrue(Context::has('level1'));
                Context::set('level2', 'value2');

                $this->assertTrue(Context::has('level2'));
            });

            $this->assertTrue(Context::has('level1'));
            $this->assertFalse(Context::has('level2'));
        });

        $this->assertTrue(Context::has('level0'));
        $this->assertFalse(Context::has('level1'));
        $this->assertFalse(Context::has('level2'));
    }

    /**
     * 测试混合使用run和fork
     */
    public function testMixedRunAndFork(): void
    {
        Context::set('base', 'base_value');

        Context::run(function () {
            $this->assertFalse(Context::has('base'));
            Context::set('run_key', 'run_value');

            Context::fork(function () {
                $this->assertFalse(Context::has('base'));
                $this->assertTrue(Context::has('run_key'));
                Context::set('fork_key', 'fork_value');
            });

            $this->assertTrue(Context::has('run_key'));
            $this->assertFalse(Context::has('fork_key'));
        });

        $this->assertTrue(Context::has('base'));
        $this->assertFalse(Context::has('run_key'));
        $this->assertFalse(Context::has('fork_key'));
    }
}
