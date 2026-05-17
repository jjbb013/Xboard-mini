<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class HealthController extends Controller
{
    /**
     * 健康检查端点
     * 供 NorthFrank / 负载均衡 / 外部监控使用
     */
    public function check()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'schedule' => $this->checkSchedule(),
            'queue' => $this->checkQueue(),
            'disk' => $this->checkDisk(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'ok');
        $statusCode = $allHealthy ? 200 : 503;

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $statusCode);
    }

    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'message' => 'connected'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function checkCache(): array
    {
        try {
            Cache::put('health_check', time(), 10);
            $value = Cache::get('health_check');
            return ['status' => 'ok', 'message' => $value ? 'read/write ok' : 'read failed'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function checkSchedule(): array
    {
        $lastCheck = Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
        if (!$lastCheck) {
            return ['status' => 'warning', 'message' => 'no schedule heartbeat yet'];
        }
        $secondsAgo = time() - $lastCheck;
        if ($secondsAgo > 300) {
            return ['status' => 'error', 'message' => "last heartbeat {$secondsAgo}s ago"];
        }
        return ['status' => 'ok', 'message' => "last heartbeat {$secondsAgo}s ago"];
    }

    protected function checkQueue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
            return [
                'status' => 'ok',
                'message' => "pending: {$pending}, failed: {$failed}"
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function checkDisk(): array
    {
        $free = disk_free_space(base_path());
        $total = disk_total_space(base_path());
        if ($total === false || $free === false) {
            return ['status' => 'warning', 'message' => 'unable to check disk'];
        }
        $usedPercent = round((($total - $free) / $total) * 100, 2);
        $status = $usedPercent > 90 ? 'error' : ($usedPercent > 75 ? 'warning' : 'ok');
        return [
            'status' => $status,
            'message' => "used: {$usedPercent}%"
        ];
    }
}
