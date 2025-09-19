<?php

/**
 * Web应用中使用kode/context的示例
 * 模拟一个简单的HTTP请求处理流程
 */

// 引入Composer自动加载文件
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Context\Context;

/**
 * 模拟生成Trace ID
 */
function generateTraceId(): string
{
    return 'trace_' . bin2hex(random_bytes(8));
}

/**
 * 模拟日志记录函数
 */
function logMessage(string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $traceId = Context::get('trace_id', 'unknown');
    $userId = Context::get('user_id', 'anonymous');
    
    $logContext = json_encode(array_merge($context, [
        'trace_id' => $traceId,
        'user_id' => $userId
    ]));
    
    echo "[{$timestamp}] {$message} Context: {$logContext}\n";
}

/**
 * 模拟用户服务
 */
class UserService
{
    public static function getCurrentUser(): array
    {
        $userId = Context::get('user_id');
        if (!$userId) {
            throw new RuntimeException('User not authenticated');
        }
        
        // 模拟从数据库获取用户信息
        return [
            'id' => $userId,
            'name' => 'User_' . $userId,
            'email' => "user{$userId}@example.com"
        ];
    }
    
    public static function getUserPermissions(int $userId): array
    {
        // 模拟权限检查
        return $userId === 1 ? ['admin', 'user'] : ['user'];
    }
}

/**
 * 模拟请求处理中间件
 */
class RequestContextMiddleware
{
    public function handle(array $request, callable $next)
    {
        // 设置请求上下文
        Context::set('request', $request);
        Context::set('trace_id', generateTraceId());
        
        logMessage('开始处理请求', ['uri' => $request['uri'] ?? '/']);
        
        try {
            $response = $next();
            logMessage('请求处理完成');
            return $response;
        } catch (Exception $e) {
            logMessage('请求处理出错', ['error' => $e->getMessage()]);
            return ['status' => 500, 'body' => 'Internal Server Error'];
        }
    }
}

/**
 * 模拟认证中间件
 */
class AuthMiddleware
{
    public function handle(array $request, callable $next)
    {
        // 模拟从请求头获取用户ID
        $userId = $request['headers']['X-User-ID'] ?? null;
        
        if ($userId) {
            Context::set('user_id', (int)$userId);
            logMessage('用户已认证', ['user_id' => $userId]);
        } else {
            logMessage('匿名用户访问');
        }
        
        return $next();
    }
}

/**
 * 模拟控制器
 */
class HomeController
{
    public function index(): array
    {
        logMessage('访问首页控制器');
        
        $user = null;
        $permissions = [];
        
        if (Context::has('user_id')) {
            $user = UserService::getCurrentUser();
            $permissions = UserService::getUserPermissions($user['id']);
        }
        
        return [
            'status' => 200,
            'body' => json_encode([
                'message' => 'Hello World',
                'user' => $user,
                'permissions' => $permissions,
                'trace_id' => Context::get('trace_id')
            ])
        ];
    }
}

/**
 * 模拟应用处理流程
 */
function handleRequest(array $request): array
{
    // 创建中间件链
    $middlewareChain = [
        new RequestContextMiddleware(),
        new AuthMiddleware()
    ];
    
    // 创建处理函数
    $handler = function () {
        $controller = new HomeController();
        return $controller->index();
    };
    
    // 应用中间件（从外到内）
    foreach (array_reverse($middlewareChain) as $middleware) {
        $next = $handler;
        $handler = function () use ($middleware, $request, $next) {
            return $middleware->handle($request, $next);
        };
    }
    
    // 执行处理
    return $handler();
}

// 模拟HTTP请求
echo "=== Web应用上下文管理示例 ===\n\n";

// 模拟匿名用户请求
echo "--- 匿名用户请求 ---\n";
$anonymousRequest = [
    'method' => 'GET',
    'uri' => '/',
    'headers' => []
];

$response = handleRequest($anonymousRequest);
echo "响应: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n";

// 清空上下文
Context::clear();

// 模拟认证用户请求
echo "--- 认证用户请求 ---\n";
$authenticatedRequest = [
    'method' => 'GET',
    'uri' => '/dashboard',
    'headers' => [
        'X-User-ID' => '123'
    ]
];

$response = handleRequest($authenticatedRequest);
echo "响应: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== 示例完成 ===\n";