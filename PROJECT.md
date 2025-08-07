# 项目核心目标与技术概要 (PROJECT.md)

## 1. 核心目标

本项目的首要目标是 **彻底移除所有 Redis 依赖**，对应用进行重构，使其能够完全在 **Northflank 的免费套餐** 上部署和运行。

这意味着架构设计必须遵循以下原则：
- **零 Redis 依赖**: 缓存、队列、会话等所有功能都不能使用 Redis。
- **适配免费套餐限制**: 严格遵守 Northflank 免费套餐的资源限制，特别是关于持久化存储卷的规定。

---

## 2. 关键技术栈与选型

- **后端框架**: Laravel 12 + Swoole (via Laravel Octane)
- **数据库**: SQLite (文件数据库)
- **缓存驱动**: `database` (使用 SQLite 数据库中的 `cache` 表)
- **队列驱动**: `database` (使用 SQLite 数据库中的 `jobs` 和 `failed_jobs` 表)
- **会话驱动**: `database` (使用 SQLite 数据库中的 `sessions` 表)

---

## 3. 核心约束：Northflank 部署环境

- **持久化存储卷无法共享**: Northflank 的一个关键限制是，**单个持久化存储卷 (Volume) 只能挂载到一个服务 (Service) 实例上**，不能在多个服务之间共享。
- **Implication**: 这个约束使得将 Web 服务和队列服务拆分为两个独立 Service 的方案不可行，因为它们无法共享存放 SQLite 数据库的存储卷。

---

## 4. 最终部署策略：单服务 + SupervisorD

为了解决上述约束，我们采用 **单服务 (Single-Service) + SupervisorD** 的部署模型。

- **单一服务**: 在 Northflank 上只创建一个 `web` 类型的服务。
- **单一存储卷**: 创建一个持久化存储卷，并挂载到这个单一服务上。
- **SupervisorD 进程管理**:
    - Docker 容器的入口点 (CMD) 是 `supervisord`。
    - SupervisorD 负责在容器内启动并管理两个必要的子进程：
        1.  **Web 进程**: `php artisan octane:start`，负责处理外部 HTTP 请求。
        2.  **队列工作进程**: `php artisan queue:work`，负责在后台处理异步任务。
- **优势**: 此方案让两个进程在同一个容器文件系统内运行，因此可以无缝地共享挂载在存储卷上的 SQLite 数据库，完美地解决了卷无法共享的限制。

---

## 5. 重要历史决策与故障排查

- **移除 `laravel/horizon`**: Horizon 包强依赖 Redis，因此已被从 `composer.json` 中移除。所有相关的配置文件 (`horizon.php`) 和服务提供者 (`HorizonServiceProvider.php`) 也已被删除。
- **重构 `StatisticalService`**: 此服务中原有的基于 Redis `zincrby` 的流量统计逻辑已被完全重构，现在使用数据库的 `updateOrCreate` 操作直接读写数据库。
- **必须创建 `cache` 表**: 由于缓存驱动设置为 `database`，在执行 `php artisan migrate` 之前，必须先执行 `php artisan cache:table` 来创建缓存表所需的迁移文件。
- **前端 `404` 错误**: 后端移除了与 Horizon 相关的 API 路由后，前端页面可能会因为请求一个已不存在的 API (`/api/.../getQueueStats`) 而在浏览器控制台产生 `404` 错误。经排查，这是符合预期的无害错误，因为前端项目是独立仓库，无法直接修改。此问题不影响后端服务的稳定运行。
- **清理配置缓存**: 在移除 `horizon` 等包后，必须执行 `php artisan optimize:clear` 来清除所有缓存，以避免因旧的缓存配置导致应用崩溃。
