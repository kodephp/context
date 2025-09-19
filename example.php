<?php

/**
 * kode/context 使用示例
 */

// 引入Composer自动加载文件
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Context\Context;

// 示例1: 基本用法
echo "=== 基本用法示例 ===\n";

// 设置上下文数据
Context::set('user_id', 12345);
Context::set('trace_id', uniqid('trace_'));
Context::set('request_uri', $_SERVER['REQUEST_URI'] ?? '/example');

// 获取上下文数据
echo "User ID: " . Context::get('user_id') . "\n";
echo "Trace ID: " . Context::get('trace_id') . "\n";
echo "Request URI: " . Context::get('request_uri') . "\n";

// 检查键是否存在
if (Context::has('user_id')) {
    echo "User ID 存在\n";
}

// 示例2: 使用run方法创建隔离上下文
echo "\n=== 隔离上下文示例 ===\n";

// 设置外部上下文
Context::set('app_name', 'MyApp');
Context::set('version', '1.0');

echo "外部上下文 - App Name: " . Context::get('app_name') . "\n";

// 在隔离的上下文中运行
$result = Context::run(function () {
    // 在新的上下文中，外部值不可见
    echo "内部上下文 - App Name: " . var_export(Context::get('app_name'), true) . "\n"; // null
    
    // 设置内部上下文值
    Context::set('temp_value', 'temporary');
    Context::set('app_name', 'SubApp'); // 不影响外部
    
    echo "内部上下文 - Temp Value: " . Context::get('temp_value') . "\n";
    echo "内部上下文 - App Name: " . Context::get('app_name') . "\n";
    
    return "内部执行完成";
});

echo "Run方法返回值: " . $result . "\n";

// 回到外部上下文后，值已恢复
echo "外部上下文 - App Name: " . Context::get('app_name') . "\n";
echo "外部上下文 - Temp Value: " . var_export(Context::get('temp_value'), true) . "\n"; // null

// 示例3: 复制上下文快照
echo "\n=== 上下文快照示例 ===\n";

$contextSnapshot = Context::copy();
echo "当前上下文快照:\n";
print_r($contextSnapshot);

// 示例4: 删除和清空
echo "\n=== 删除和清空示例 ===\n";

Context::set('to_be_deleted', 'value');
echo "删除前 - to_be_deleted: " . var_export(Context::get('to_be_deleted'), true) . "\n";

Context::delete('to_be_deleted');
echo "删除后 - to_be_deleted: " . var_export(Context::get('to_be_deleted'), true) . "\n";

// 清空所有上下文
Context::clear();
echo "清空后上下文快照:\n";
print_r(Context::copy());

// 新增方法示例
echo "\n=== 新增方法示例 ===\n";

// 设置多个值
Context::set('user_id', 123);
Context::set('username', 'john_doe');
Context::set('role', 'admin');

// 获取所有键名
$keys = Context::keys();
echo "Keys: " . json_encode($keys) . "\n"; // ["user_id","username","role"]

// 获取上下文数量
$count = Context::count();
echo "Count: $count\n"; // 3

// 获取所有数据
$all = Context::all();
echo "All data: " . json_encode($all) . "\n"; // {"user_id":123,"username":"john_doe","role":"admin"}

// 合并数组到上下文
Context::merge([
    'email' => 'john@example.com',
    'role' => 'user' // 覆盖已存在的role
]);

echo "After merge: " . json_encode(Context::all()) . "\n"; 
// {"user_id":123,"username":"john_doe","role":"user","email":"john@example.com"}

// 非覆盖模式合并
Context::merge([
    'role' => 'super_admin', // 不会覆盖
    'department' => 'IT'
], false);

echo "After non-overwrite merge: " . json_encode(Context::all()) . "\n";
// {"user_id":123,"username":"john_doe","role":"user","email":"john@example.com","department":"IT"}

echo "\n=== 示例完成 ===\n";