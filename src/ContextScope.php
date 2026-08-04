<?php

declare(strict_types=1);

namespace Kode\Context;

/**
 * 上下文作用域句柄（RAII 风格）
 *
 * 适用于无法使用闭包包裹的场景，例如 PSR-15 中间件、框架生命周期钩子：
 *
 * ```php
 * $scope = Context::enter(['request_id' => $id]);
 * try {
 *     $response = $handler->handle($request);
 * } finally {
 *     $scope->close();
 * }
 * ```
 *
 * 即使忘记调用 close()，对象析构时也会自动回滚，避免上下文泄漏。
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class ContextScope
{
    private bool $closed = false;

    /**
     * @param ContextStore $store 所属执行单元的存储
     * @param int          $depth 进入作用域时的深度
     *
     * @internal 请使用 Context::enter() 创建
     */
    public function __construct(
        private readonly ContextStore $store,
        private readonly int $depth,
    ) {
    }

    /**
     * 关闭作用域并回滚上下文
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->store->unwind($this->depth);
    }

    /**
     * 作用域是否已关闭
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->close();
    }
}
