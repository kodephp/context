<?php

declare(strict_types=1);

/**
 * kode/context 性能基准测试
 *
 * 该脚本用于测试 Context 类在各种操作上的性能表现
 * 支持跨平台运行，自动收集系统信息
 */

require_once __DIR__ . '/vendor/autoload.php';

use Kode\Context\Context;

/**
 * 获取系统信息
 *
 * @return array<string, string>
 */
function getSystemInfo(): array
{
    $info = [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'os_version' => php_uname('s') . ' ' . php_uname('r'),
        'architecture' => php_uname('m'),
        'runtime' => Context::getRuntime(),
        'opcache' => extension_loaded('opcache') ? 'enabled' : 'disabled',
        'jit' => (function_exists('opcache_get_status') && ($status = opcache_get_status(false)) && ($status['jit']['enabled'] ?? false)) ? 'enabled' : 'disabled',
    ];

    if (PHP_OS_FAMILY === 'Linux') {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuInfo !== false) {
            if (preg_match('/model name\s*:\s*(.+)/i', $cpuInfo, $matches)) {
                $info['cpu_model'] = trim($matches[1]);
            }
            if (preg_match('/cpu cores\s*:\s*(\d+)/i', $cpuInfo, $matches)) {
                $info['cpu_cores'] = $matches[1];
            }
        }

        $memInfo = @file_get_contents('/proc/meminfo');
        if ($memInfo !== false && preg_match('/MemTotal\s*:\s*(\d+)/i', $memInfo, $matches)) {
            $info['total_memory'] = round((int)$matches[1] / 1024 / 1024, 1) . ' GB';
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $info['cpu_model'] = trim((string)shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null') ?: 'Unknown');
        $info['cpu_cores'] = trim((string)shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: 'Unknown');
        $memSize = shell_exec('sysctl -n hw.memsize 2>/dev/null');
        if ($memSize) {
            $info['total_memory'] = round((int)$memSize / 1024 / 1024 / 1024, 1) . ' GB';
        }
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $info['cpu_model'] = trim((string)shell_exec('wmic cpu get name 2>nul | findstr /v "Name"') ?: 'Unknown');
        $info['cpu_cores'] = trim((string)shell_exec('wmic cpu get numberOfCores 2>nul | findstr /r "[0-9]"') ?: 'Unknown');
    }

    return $info;
}

/**
 * 格式化内存大小
 *
 * @param int $bytes 字节数
 * @return string
 */
function formatMemory(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

/**
 * 基准测试函数
 *
 * @param string   $name       测试名称
 * @param callable $callback   测试回调
 * @param int      $iterations 迭代次数
 * @return array{time: float, ops: int, memory: int}
 */
function benchmark(string $name, callable $callback, int $iterations = 100000): array
{
    for ($i = 0; $i < 1000; $i++) {
        $callback();
    }

    Context::reset();

    $startTime = hrtime(true);
    $startMemory = memory_get_usage();

    for ($i = 0; $i < $iterations; $i++) {
        $callback();
    }

    $endTime = hrtime(true);
    $endMemory = memory_get_usage();

    $timeElapsed = ($endTime - $startTime) / 1_000_000;
    $memoryUsed = $endMemory - $startMemory;

    printf(
        "  %-35s: %10.3f ms | %12d ops/sec | Memory: %10s\n",
        $name,
        $timeElapsed,
        (int)($iterations / ($timeElapsed / 1000)),
        formatMemory(max(0, $memoryUsed))
    );

    Context::reset();

    return [
        'time' => $timeElapsed,
        'ops' => (int)($iterations / ($timeElapsed / 1000)),
        'memory' => $memoryUsed,
    ];
}

/**
 * 运行完整基准测试套件
 *
 * @param int $iterations 迭代次数
 * @return array<string, array{time: float, ops: int, memory: int}>
 */
function runBenchmarkSuite(int $iterations = 100000): array
{
    $results = [];

    $results['set'] = benchmark('Context::set()', function () {
        Context::set('key', 'value');
    }, $iterations);

    Context::set('test_key', 'test_value');
    $results['get'] = benchmark('Context::get()', function () {
        Context::get('test_key');
    }, $iterations);

    $results['has'] = benchmark('Context::has()', function () {
        Context::has('test_key');
    }, $iterations);

    $results['delete'] = benchmark('Context::delete()', function () {
        Context::set('temp_key', 'temp_value');
        Context::delete('temp_key');
    }, $iterations);

    $results['clear'] = benchmark('Context::clear()', function () {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');
        Context::clear();
    }, $iterations);

    Context::set('copy_key1', 'copy_value1');
    Context::set('copy_key2', 'copy_value2');
    $results['copy'] = benchmark('Context::copy()', function () {
        Context::copy();
    }, $iterations);

    $results['run'] = benchmark('Context::run()', function () {
        Context::run(function () {
            Context::set('inner_key', 'inner_value');
        });
    }, $iterations);

    Context::set('fork_key', 'fork_value');
    $results['fork'] = benchmark('Context::fork()', function () {
        Context::fork(function () {
            Context::set('inner_key', 'inner_value');
        });
    }, $iterations);

    Context::set('keys_key1', 'keys_value1');
    Context::set('keys_key2', 'keys_value2');
    $results['keys'] = benchmark('Context::keys()', function () {
        Context::keys();
    }, $iterations);

    Context::set('count_key1', 'count_value1');
    Context::set('count_key2', 'count_value2');
    Context::set('count_key3', 'count_value3');
    $results['count'] = benchmark('Context::count()', function () {
        Context::count();
    }, $iterations);

    Context::set('all_key1', 'all_value1');
    Context::set('all_key2', 'all_value2');
    $results['all'] = benchmark('Context::all()', function () {
        Context::all();
    }, $iterations);

    $results['merge'] = benchmark('Context::merge()', function () {
        Context::merge([
            'merge_key1' => 'merge_value1',
            'merge_key2' => 'merge_value2'
        ]);
    }, $iterations);

    $snapshot = ['restore_key1' => 'restore_value1', 'restore_key2' => 'restore_value2'];
    $results['restore'] = benchmark('Context::restore()', function () use ($snapshot) {
        Context::restore($snapshot);
    }, $iterations);

    $results['getRuntime'] = benchmark('Context::getRuntime()', function () {
        Context::getRuntime();
    }, $iterations);

    $results['isCoroutine'] = benchmark('Context::isCoroutine()', function () {
        Context::isCoroutine();
    }, $iterations);

    return $results;
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║           kode/context v2.0.0 性能基准测试                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$systemInfo = getSystemInfo();

echo "┌─ 系统信息 ─────────────────────────────────────────────────────┐\n";
printf("│ %-16s: %-48s │\n", 'PHP 版本', $systemInfo['php_version']);
printf("│ %-16s: %-48s │\n", '操作系统', $systemInfo['os_version']);
printf("│ %-16s: %-48s │\n", '架构', $systemInfo['architecture']);
printf("│ %-16s: %-48s │\n", '运行时模式', $systemInfo['runtime']);
printf("│ %-16s: %-48s │\n", 'OPcache', $systemInfo['opcache']);
printf("│ %-16s: %-48s │\n", 'JIT', $systemInfo['jit']);
if (isset($systemInfo['cpu_model'])) {
    printf("│ %-16s: %-48s │\n", 'CPU 型号', substr($systemInfo['cpu_model'], 0, 48));
}
if (isset($systemInfo['cpu_cores'])) {
    printf("│ %-16s: %-48s │\n", 'CPU 核心数', $systemInfo['cpu_cores']);
}
if (isset($systemInfo['total_memory'])) {
    printf("│ %-16s: %-48s │\n", '总内存', $systemInfo['total_memory']);
}
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "┌─ 基准测试结果 (迭代次数: 100,000) ──────────────────────────────┐\n";
echo "│                                                                 │\n";

$results = runBenchmarkSuite();

echo "│                                                                 │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$totalOps = array_sum(array_column($results, 'ops'));
$avgTime = array_sum(array_column($results, 'time')) / count($results);

echo "┌─ 测试摘要 ─────────────────────────────────────────────────────┐\n";
printf("│ %-16s: %-48s │\n", '总操作数/秒', number_format($totalOps));
printf("│ %-16s: %-48s │\n", '平均执行时间', number_format($avgTime, 3) . ' ms');
printf("│ %-16s: %-48s │\n", '测试方法数', count($results));
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "┌─ 性能评级 ─────────────────────────────────────────────────────┐\n";
$setOps = $results['set']['ops'];
if ($setOps > 10_000_000) {
    $rating = '★★★★★ 优秀 (超过 1000 万 ops/sec)';
} elseif ($setOps > 5_000_000) {
    $rating = '★★★★☆ 良好 (超过 500 万 ops/sec)';
} elseif ($setOps > 1_000_000) {
    $rating = '★★★☆☆ 一般 (超过 100 万 ops/sec)';
} else {
    $rating = '★★☆☆☆ 较慢 (低于 100 万 ops/sec)';
}
printf("│ %-64s │\n", $rating);
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "基准测试完成！\n\n";
