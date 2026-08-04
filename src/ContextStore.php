<?php

declare(strict_types=1);

namespace Kode\Context;

/**
 * 单个执行单元（协程/纤程/线程/进程）的上下文存储容器
 *
 * 之所以使用对象而非数组，是因为 WeakMap 的元素无法按引用取出修改；
 * 通过持有对象再修改其公开属性，可以避免 "Indirect modification of overloaded element" 问题，
 * 同时借助 WeakMap 的弱引用特性，在执行单元销毁后自动回收上下文，杜绝内存泄漏。
 *
 * @internal 该类属于内部实现，不保证向后兼容
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
final class ContextStore
{
    /**
     * 当前作用域的上下文数据
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * 作用域栈，用于 run()/fork()/enter() 的嵌套与回滚
     *
     * @var list<array<string, mixed>>
     */
    public array $stack = [];

    /**
     * 进入一个新的作用域层级
     *
     * @param array<string, mixed> $initial 新作用域的初始数据
     * @return int 该层级的深度，用于后续精确回滚
     */
    public function push(array $initial): int
    {
        $this->stack[] = $this->data;
        $this->data = $initial;

        return count($this->stack);
    }

    /**
     * 回滚到指定深度之前的状态
     *
     * 会一并清理深度大于 $depth 的未正常关闭的嵌套作用域，保证栈不会泄漏。
     *
     * @param int $depth push() 返回的深度
     */
    public function unwind(int $depth): void
    {
        while (count($this->stack) >= $depth && $this->stack !== []) {
            /** @var array<string, mixed> $previous */
            $previous = array_pop($this->stack);
            $this->data = $previous;
        }
    }

    /**
     * 当前嵌套深度
     */
    public function depth(): int
    {
        return count($this->stack);
    }

    /**
     * 清空存储
     */
    public function reset(): void
    {
        $this->data = [];
        $this->stack = [];
    }
}
