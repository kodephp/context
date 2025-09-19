<?php

declare(strict_types=1);

namespace Kode\Context\Tests\Unit;

use Kode\Context\Context;
use PHPUnit\Framework\TestCase;

/**
 * 运行时兼容性测试
 */
class RuntimeCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        Context::clear();
    }

    protected function tearDown(): void
    {
        Context::clear();
    }

    /**
     * 测试基本功能在各种环境下的兼容性
     */
    public function testBasicFunctionality(): void
    {
        // 设置测试数据
        Context::set('user_id', 123);
        Context::set('trace_id', 'trace_abc123');
        Context::set('request_data', ['method' => 'GET', 'uri' => '/test']);
        
        // 验证数据正确存储和获取
        $this->assertEquals(123, Context::get('user_id'));
        $this->assertEquals('trace_abc123', Context::get('trace_id'));
        $this->assertEquals(['method' => 'GET', 'uri' => '/test'], Context::get('request_data'));
        
        // 验证has方法
        $this->assertTrue(Context::has('user_id'));
        $this->assertFalse(Context::has('nonexistent'));
        
        // 验证copy方法
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
        // 设置外部上下文
        Context::set('outer_key', 'outer_value');
        Context::set('shared_key', 'outer_shared');
        
        $innerResult = Context::run(function () {
            // 验证外部上下文在新作用域中不可见
            $this->assertFalse(Context::has('outer_key'));
            $this->assertFalse(Context::has('shared_key'));
            
            // 设置内部上下文
            Context::set('inner_key', 'inner_value');
            Context::set('shared_key', 'inner_shared');
            
            // 验证内部上下文
            $this->assertTrue(Context::has('inner_key'));
            $this->assertTrue(Context::has('shared_key'));
            $this->assertEquals('inner_value', Context::get('inner_key'));
            $this->assertEquals('inner_shared', Context::get('shared_key'));
            
            return [
                'inner_key' => Context::get('inner_key'),
                'shared_key' => Context::get('shared_key')
            ];
        });
        
        // 验证run方法返回值
        $this->assertIsArray($innerResult);
        $this->assertEquals('inner_value', $innerResult['inner_key']);
        $this->assertEquals('inner_shared', $innerResult['shared_key']);
        
        // 验证回到外部上下文后，外部值恢复
        $this->assertTrue(Context::has('outer_key'));
        $this->assertTrue(Context::has('shared_key'));
        $this->assertEquals('outer_value', Context::get('outer_key'));
        $this->assertEquals('outer_shared', Context::get('shared_key'));
        
        // 验证内部上下文已清除
        $this->assertFalse(Context::has('inner_key'));
    }

    /**
     * 测试复杂数据类型的存储和获取
     */
    public function testComplexDataTypes(): void
    {
        // 测试对象存储
        $obj = new \stdClass();
        $obj->property = 'test';
        Context::set('object', $obj);
        $this->assertSame($obj, Context::get('object'));
        
        // 测试数组存储
        $array = ['key' => 'value', 'nested' => ['a', 'b', 'c']];
        Context::set('array', $array);
        $this->assertEquals($array, Context::get('array'));
        
        // 测试闭包存储（在某些环境下可能不支持）
        $closure = function ($x) { return $x * 2; };
        Context::set('closure', $closure);
        $retrievedClosure = Context::get('closure');
        $this->assertIsCallable($retrievedClosure);
        $this->assertEquals(10, $retrievedClosure(5));
    }
}