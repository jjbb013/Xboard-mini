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

## 4. 最终部署策略：单服务 + SupervisorD + Entrypoint 脚本

为了解决上述约束，并确保部署的健壮性，我们采用 **单服务 (Single-Service) + SupervisorD + Entrypoint 脚本** 的最终模型。

- **单一服务**: 在 Northflank 上只创建一个 `web` 类型的服务。
- **单一存储卷**: 创建一个持久化存储卷，并挂载到这个单一服务上。
- **Entrypoint 脚本 (`entrypoint.sh`)**:
    - 这是 Docker 容器的**唯一入口点**。
    - 该脚本在容器**每次启动时**运行。
    - 它会检查应用是否已初始化（通过检查一个位于持久化存储卷中的 `.installed.lock` 文件）。
    - **仅在首次启动时**，它会自动执行所有必要的数据库迁移和应用初始化命令 (`php artisan ...`)。
    - 初始化完成后，它会启动 SupervisorD。
- **SupervisorD 进程管理**:
    - SupervisorD 负责在容器内启动并管理两个必要的子进程：
        1.  **Web 进程**: `php artisan octane:start`
        2.  **队列工作进程**: `php artisan queue:work`
- **优势**: 此方案将所有初始化逻辑都封装在镜像内部，使其“自给自足”，不再依赖 Northflank `build` 步骤的特定行为，从根本上解决了 `vendor` 目录缺失和 `APP_KEY` 未设置等一系列问题。

---

## 5. 重要历史决策与故障排查

- **`northflank.yml` 未生效的根本原因**:
    - **问题**: 部署后出现 `MissingAppKeyException` 错误，端口仍为 TCP，存储卷未创建。
    - **原因分析**: 经最终排查，根本原因在于我们将**一次性的初始化命令**（如 `php artisan migrate`）错误地放置在了 Northflank 的 `build` 步骤中。`build` 步骤在一个**临时的、一次性的容器**中运行，该容器在 `build` 结束后被丢弃。最终启动的服务是一个全新的、未被初始化的容器，因此所有配置和数据库表都不存在。
    - **最终解决方案**: 采用 `entrypoint.sh` 脚本。将所有初始化逻辑从 `northflank.yml` 的 `build` 步骤中**彻底移除**，并转移到 `entrypoint.sh` 脚本中。该脚本会在最终的服务容器启动时、且仅在首次启动时执行这些初始化命令。
- **`vendor` 目录缺失问题**:
    - **问题**: 部署失败，日志显示 `Failed opening required '/www/vendor/autoload.php'`。
    - **解决方案**: 将 `composer install` 命令从 `northflank.yml` 的 `build` 步骤中**移动到 `Dockerfile` 中**，将依赖直接构建到镜像里。
- **端口协议必须为 HTTP**:
    - **问题**: Northflank 默认将端口识别为 TCP，导致无法从公网访问。
    - **解决方案**: 在 `northflank.yml` 文件中，必须明确指定端口协议为 `HTTP`。
- **移除 `laravel/horizon`**: Horizon 包强依赖 Redis，已被完全移除。
- **必须创建 `cache` 表**: `database` 缓存驱动需要 `php artisan cache:table` 命令来创建迁移文件。
