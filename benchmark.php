<?php

/**
 * kode/context 性能基准测试
 */

// 引入Composer自动加载文件
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Context\Context;

/**
 * 基准测试函数
 */
function benchmark(string $name, callable $callback, int $iterations = 100000): void
{
    // 预热
    for ($i = 0; $i < 1000; $i++) {
        $callback();
    }
    
    // 清理上下文
    Context::clear();
    
    // 开始测试
    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    
    for ($i = 0; $i < $iterations; $i++) {
        $callback();
    }
    
    $endTime = microtime(true);
    $endMemory = memory_get_usage();
    
    $timeElapsed = ($endTime - $startTime) * 1000; // 转换为毫秒
    $memoryUsed = $endMemory - $startMemory;
    
    printf(
        "%-25s: %8.2f ms (%8d ops/sec) | Memory: %8s\n",
        $name,
        $timeElapsed,
        (int)($iterations / ($timeElapsed / 1000)),
        number_format($memoryUsed)
    );
    
    // 清理上下文
    Context::clear();
}

echo "=== kode/context 性能基准测试 ===\n\n";

// 测试Context::set()
benchmark('Context::set()', function () {
    Context::set('key', 'value');
});

// 测试Context::get()
Context::set('test_key', 'test_value');
benchmark('Context::get()', function () {
    Context::get('test_key');
});

// 测试Context::has()
benchmark('Context::has()', function () {
    Context::has('test_key');
});

// 测试Context::delete()
benchmark('Context::delete()', function () {
    Context::set('temp_key', 'temp_value');
    Context::delete('temp_key');
});

// 测试Context::clear()
benchmark('Context::clear()', function () {
    Context::set('key1', 'value1');
    Context::set('key2', 'value2');
    Context::clear();
});

// 测试Context::copy()
Context::set('copy_key1', 'copy_value1');
Context::set('copy_key2', 'copy_value2');
benchmark('Context::copy()', function () {
    Context::copy();
});

// 测试Context::run()
benchmark('Context::run()', function () {
    Context::run(function () {
        Context::set('inner_key', 'inner_value');
    });
});

echo "\n=== 基准测试完成 ===\n";