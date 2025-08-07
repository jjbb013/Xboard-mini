<?php
namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatisticalService
{
    protected $startAt;
    protected $endAt;

    public function __construct()
    {
        ini_set('memory_limit', -1);
    }

    public function setStartAt($timestamp)
    {
        $this->startAt = $timestamp;
    }

    public function setEndAt($timestamp)
    {
        $this->endAt = $timestamp;
    }

    /**
     * 生成统计报表
     */
    public function generateStatData(): array
    {
        $startAt = $this->startAt;
        $endAt = $this->endAt;
        if (!$startAt || !$endAt) {
            $startAt = strtotime(date('Y-m-d'));
            $endAt = strtotime('+1 day', $startAt);
        }
        $data = [];
        $data['order_count'] = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
        $data['order_total'] = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->sum('total_amount');
        $data['paid_count'] = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->count();
        $data['paid_total'] = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');
        $commissionLogBuilder = CommissionLog::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $data['commission_count'] = $commissionLogBuilder->count();
        $data['commission_total'] = $commissionLogBuilder->sum('get_amount');
        $data['register_count'] = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();
        $data['invite_count'] = User::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotNull('invite_user_id')
            ->count();
        $data['transfer_used_total'] = StatServer::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->select(DB::raw('SUM(u) + SUM(d) as total'))
            ->value('total') ?? 0;
        return $data;
    }

    /**
     * 直接记录服务器流量数据到数据库
     */
    public function statServer($serverId, $serverType, $u, $d)
    {
        if (!$this->startAt) {
            $this->setStartAt(strtotime(date('Y-m-d')));
        }
        StatServer::updateOrCreate(
            [
                'server_id' => $serverId,
                'server_type' => $serverType,
                'record_at' => $this->startAt,
                'record_type' => 'd' // 使用 'd' 代表天记录
            ],
            [
                'u' => DB::raw("u + {$u}"),
                'd' => DB::raw("d + {$d}")
            ]
        );
    }

    /**
     * 直接记录用户流量数据到数据库
     */
    public function statUser($rate, $userId, $u, $d)
    {
        if (!$this->startAt) {
            $this->setStartAt(strtotime(date('Y-m-d')));
        }
        StatUser::updateOrCreate(
            [
                'user_id' => $userId,
                'server_rate' => $rate,
                'record_at' => $this->startAt,
                'record_type' => 'd' // 使用 'd' 代表天记录
            ],
            [
                'u' => DB::raw("u + {$u}"),
                'd' => DB::raw("d + {$d}")
            ]
        );
    }

    public function getStatRecord($type)
    {
        switch ($type) {
            case "paid_total": {
                return Stat::select([
                    '*',
                    DB::raw('paid_total / 100 as paid_total')
                ])
                    ->where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "commission_total": {
                return Stat::select([
                    '*',
                    DB::raw('commission_total / 100 as commission_total')
                ])
                    ->where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
            case "register_count": {
                return Stat::where('record_at', '>=', $this->startAt)
                    ->where('record_at', '<', $this->endAt)
                    ->orderBy('record_at', 'ASC')
                    ->get();
            }
        }
    }

    public function getRanking($type, $limit = 20)
    {
        switch ($type) {
            case 'server_traffic_rank': {
                return $this->buildServerTrafficRank($limit);
            }
            case 'user_consumption_rank': {
                return $this->buildUserConsumptionRank($limit);
            }
            case 'invite_rank': {
                return $this->buildInviteRank($limit);
            }
        }
    }

    /**
     * 获取指定日期范围内的节点流量排行
     * @param mixed ...$times 可选值：'today', 'tomorrow', 'last_week'，或指定日期范围，格式：timestamp
     * @return array
     */

    public static function getServerRank(...$times)
    {
        $startAt = 0;
        $endAt = Carbon::tomorrow()->endOfDay()->timestamp;

        if (count($times) == 1) {
            switch ($times[0]) {
                case 'today':
                    $startAt = Carbon::today()->startOfDay()->timestamp;
                    $endAt = Carbon::today()->endOfDay()->timestamp;
                    break;
                case 'yesterday':
                    $startAt = Carbon::yesterday()->startOfDay()->timestamp;
                    $endAt = Carbon::yesterday()->endOfDay()->timestamp;
                    break;
                case 'last_week':
                    $startAt = Carbon::now()->subWeek()->startOfWeek()->timestamp;
                    $endAt = Carbon::now()->endOfDay()->timestamp;
                    break;
            }
        } else if (count($times) == 2) {
            $startAt = $times[0];
            $endAt = $times[1];
        }

        $statistics = Server::whereHas(
            'stats',
            function ($query) use ($startAt, $endAt) {
                $query->where('record_at', '>=', $startAt)
                    ->where('record_at', '<', $endAt)
                    ->where('record_type', 'd');
            }
        )
            ->withSum('stats as u', 'u') // 预加载 u 的总和
            ->withSum('stats as d', 'd') // 预加载 d 的总和
            ->get()
            ->map(function ($item) {
                return [
                    'server_name' => optional($item->parent)->name ?? $item->name,
                    'server_id' => $item->id,
                    'server_type' => $item->type,
                    'u' => (int) $item->u,
                    'd' => (int) $item->d,
                    'total' => (int) $item->u + (int) $item->d,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->toArray();
        return $statistics;
    }

    private function buildInviteRank($limit)
    {
        $stats = User::select([
            'invite_user_id',
            DB::raw('count(*) as count')
        ])
            ->where('created_at', '>=', $this->startAt)
            ->where('created_at', '<', $this->endAt)
            ->whereNotNull('invite_user_id')
            ->groupBy('invite_user_id')
            ->orderBy('count', 'DESC')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $stats->pluck('invite_user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['invite_user_id']]))
                continue;
            $stats[$k]['email'] = $users[$v['invite_user_id']]['email'];
        }
        return $stats;
    }

    private function buildUserConsumptionRank($limit)
    {
        $stats = StatUser::select([
            'user_id',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['user_id']]))
                continue;
            $stats[$k]['email'] = $users[$v['user_id']]['email'];
        }
        return $stats;
    }

    private function buildServerTrafficRank($limit)
    {
        return StatServer::select([
            'server_id',
            'server_type',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }
}
