<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Log;
use App\Models\StatUser;
use App\Models\StatServer;
use App\Models\User;

class CleanupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:database
                            {--type=all : 清理类型 (all|stats|logs|users)}
                            {--days=90 : 保留天数}
                            {--dry-run : 试运行，不实际删除}
                            {--batch=1000 : 每批删除数量}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理数据库过期数据，控制存储空间';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch');
        $cutoffTime = now()->subDays($days)->timestamp;

        $this->info("清理配置：类型={$type}, 保留天数={$days}, 截止时间=" . date('Y-m-d H:i:s', $cutoffTime));
        if ($dryRun) {
            $this->warn('【试运行模式】不会实际删除数据');
        }

        $totalDeleted = 0;

        if (in_array($type, ['all', 'stats'])) {
            $totalDeleted += $this->cleanupStats($cutoffTime, $dryRun, $batchSize);
        }

        if (in_array($type, ['all', 'logs'])) {
            $totalDeleted += $this->cleanupLogs($cutoffTime, $dryRun, $batchSize);
        }

        if (in_array($type, ['all', 'users'])) {
            $totalDeleted += $this->cleanupInactiveUsers($cutoffTime, $dryRun, $batchSize);
        }

        $this->info("清理完成，共处理 {$totalDeleted} 条记录");
        return 0;
    }

    /**
     * 清理过期统计数据
     */
    protected function cleanupStats(int $cutoffTime, bool $dryRun, int $batchSize): int
    {
        $this->info('正在清理过期统计数据...');
        $deleted = 0;

        foreach ([StatUser::class, StatServer::class] as $modelClass) {
            $model = new $modelClass;
            $query = $model->where('created_at', '<', $cutoffTime);
            $count = $query->count();

            if ($count === 0) {
                continue;
            }

            $this->info("  {$model->getTable()}: 发现 {$count} 条过期记录");

            if ($dryRun) {
                $deleted += $count;
                continue;
            }

            // 分批删除，避免大事务
            $batchDeleted = 0;
            while ($batchDeleted < $count) {
                $ids = $model->where('created_at', '<', $cutoffTime)
                    ->limit($batchSize)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                $model->whereIn('id', $ids)->delete();
                $batchDeleted += $ids->count();
                usleep(50000); // 暂停 50ms，减轻数据库压力
            }

            $deleted += $batchDeleted;
            $this->info("  {$model->getTable()}: 已删除 {$batchDeleted} 条");
        }

        return $deleted;
    }

    /**
     * 清理过期系统日志
     */
    protected function cleanupLogs(int $cutoffTime, bool $dryRun, int $batchSize): int
    {
        $this->info('正在清理过期系统日志...');
        $query = Log::where('created_at', '<', $cutoffTime);
        $count = $query->count();

        if ($count === 0) {
            $this->info('  无过期日志需要清理');
            return 0;
        }

        $this->info("  v2_log: 发现 {$count} 条过期记录");

        if ($dryRun) {
            return $count;
        }

        $batchDeleted = 0;
        while ($batchDeleted < $count) {
            $ids = Log::where('created_at', '<', $cutoffTime)
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            Log::whereIn('id', $ids)->delete();
            $batchDeleted += $ids->count();
            usleep(50000);
        }

        $this->info("  v2_log: 已删除 {$batchDeleted} 条");
        return $batchDeleted;
    }

    /**
     * 清理长期未活跃用户（仅标记，不删除数据）
     */
    protected function cleanupInactiveUsers(int $cutoffTime, bool $dryRun, int $batchSize): int
    {
        $this->info('正在检查长期未活跃用户...');
        $query = User::where('last_login_at', '<', $cutoffTime)
            ->where('banned', 0)
            ->whereNull('plan_id');
        $count = $query->count();

        if ($count === 0) {
            $this->info('  无符合条件的用户');
            return 0;
        }

        $this->info("  v2_user: 发现 {$count} 位长期未登录且未订阅的用户");

        if ($dryRun) {
            return $count;
        }

        // 出于安全考虑，不自动删除用户，仅输出提示
        $this->warn('  用户数据未自动删除，请手动确认后处理');
        return 0;
    }
}
