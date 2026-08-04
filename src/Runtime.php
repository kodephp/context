<?php

declare(strict_types=1);

namespace Kode\Context;

/**
 * 运行时类型枚举
 *
 * 描述当前代码所处的并发执行模型，决定上下文的隔离粒度。
 *
 * @package Kode\Context
 * @author  KodePHP <382601296@qq.com>
 * @license Apache-2.0
 */
enum Runtime: string
{
    /** PHP 原生 Fiber（纤程） */
    case Fiber = 'fiber';

    /** Swoole 协程 */
    case Swoole = 'swoole';

    /** Swow 协程 */
    case Swow = 'swow';

    /** 多线程（ZTS + parallel） */
    case Thread = 'thread';

    /** 多进程（pcntl） */
    case Process = 'process';

    /** 普通同步环境 */
    case Sync = 'sync';

    /**
     * 是否为协程/纤程类运行时
     */
    public function isCoroutine(): bool
    {
        return match ($this) {
            self::Fiber, self::Swoole, self::Swow => true,
            default => false,
        };
    }

    /**
     * 该运行时下多个执行单元是否共享同一份进程内存
     *
     * 共享内存意味着静态属性会被污染，必须依赖上下文隔离。
     */
    public function sharesMemory(): bool
    {
        return match ($this) {
            self::Fiber, self::Swoole, self::Swow, self::Thread => true,
            self::Process, self::Sync => false,
        };
    }

    /**
     * 人类可读描述
     */
    public function label(): string
    {
        return match ($this) {
            self::Fiber => 'PHP Fiber（纤程）',
            self::Swoole => 'Swoole 协程',
            self::Swow => 'Swow 协程',
            self::Thread => '多线程（ZTS）',
            self::Process => '多进程（pcntl）',
            self::Sync => '同步模式',
        };
    }
}
