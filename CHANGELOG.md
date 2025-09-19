# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-09-19

### Added
- Initial release of `kode/context`
- Support for PHP 8.1+ with Fiber runtime
- Support for Swoole coroutine context isolation
- Support for Swow coroutine context isolation
- Basic context operations: `set`, `get`, `has`, `delete`, `clear`
- Context snapshot with `copy` method
- Isolated context scopes with `run` method
- Comprehensive unit tests
- GitHub Actions for continuous integration
- PHPStan for static analysis
- Full documentation in README.md

### Supported Environments
- PHP 8.1+ (Native/CLI)
- PHP 8.3+ (Fiber)
- Swoole 4.0+
- Swow (latest)

### Known Limitations
- Does not support cross-coroutine communication (by design)
- Not recommended for storing large amounts of data
- Resource handles should not be stored in context