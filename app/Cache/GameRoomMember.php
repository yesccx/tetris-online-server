<?php

declare (strict_types = 1);

namespace App\Cache;

use App\Cache\Repository\HashGroupRedis;
use App\Services\GameRoomService;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\SocketIOServer\SidProvider\SidProviderInterface;
use Throwable;

/**
 * 游戏房间成员
 */
class GameRoomMember extends HashGroupRedis
{
    /**
     * @Inject
     * @var GameRoomService
     */
    protected $service;

    /**
     * 添加房间成员
     *
     * @param string $roomNumber
     * @param string $username
     * @param int $isOwner
     * @param int $isReady
     * @return void
     */
    public function addMember(string $roomNumber, string $username, int $isOwner = 0, int $isReady = 0)
    {
        // 计算出可使用的队伍
        $teams = [1, 2, 3, 4];
        $usedTeams = collect($this->getMemberList($roomNumber))->pluck('team')->values()->toArray();
        $availableTeam = array_values(array_diff($teams, $usedTeams))[0];

        $this->add($roomNumber, $username, json_encode([
            'username'          => $username,
            'join_time'         => Carbon::now(),
            'is_owner'          => $isOwner,
            'is_ready'          => $isReady,
            'is_over'           => 0,
            'is_online'         => 1,
            'is_quit'           => 0,
            'points'            => 0,
            'block_index'       => 0,
            'clear_lines'       => 0,
            'cur'               => null,
            'speed_run'         => 1,
            'matrix'            => null,
            'discharge_buffers' => 0,
            'fill_buffers'      => 0,
            'team'              => $availableTeam,
            'over_time'         => 0,
        ]));

        // 房间人数加1
        GameRoom::make()->rememberInfo($roomNumber, function ($roomInfo) {
            $roomInfo['current_count']++;
            return $roomInfo;
        });

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知所有房间内的用户
        $socketIO->of('/game')->to($roomNumber)->emit('join-room', $username);

        // 将当前用户加入到socket房间
        $userFd = OnlineMember::make()->getFd($username);
        $userSid = (string) di()->get(SidProviderInterface::class)->getSid((int) $userFd);
        $socketIO->of('/game')->getAdapter()->add($userSid, $roomNumber);
    }

    /**
     * 重新加入
     *
     * @param string $roomNumber
     * @param string $username
     * @return void
     */
    public function rejoin(string $roomNumber, string $username)
    {
        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 更新在线状态
        GameRoomMember::make()->rememberInfo($roomNumber, $username, function ($info) {
            $info['is_online'] = 1;
            return $info;
        });

        // 通知所有房间内的用户
        $socketIO->of('/game')->to($roomNumber)->emit('join-room', $username);

        // 将当前用户加入到socket房间
        $userFd = OnlineMember::make()->getFd($username);
        $userSid = (string) di()->get(SidProviderInterface::class)->getSid((int) $userFd);
        $socketIO->of('/game')->getAdapter()->add($userSid, $roomNumber);
    }

    /**
     * 移除房间成员
     *
     * @param string $roomNumber
     * @param string $username
     * @param bool $notice 是否进行广播通知
     * @param bool $reStatistics 是否重新统计人数
     * @return void
     */
    public function removeMember(string $roomNumber, string $username, bool $reStatistics = true)
    {
        if (empty($roomNumber)) {
            return true;
        }

        if (!$this->rem($roomNumber, $username)) {
            return true;
        }

        // 房间人数减1
        $roomInfo = GameRoom::make()->rememberInfo($roomNumber, function ($roomInfo) use ($reStatistics) {
            if ($reStatistics) {
                $roomInfo['current_count']--;
            }
            return $roomInfo;
        });
        if (empty($roomInfo)) {
            return true;
        }

        // 房间内没人时或房主离开房间时， 解散房间
        if ($roomInfo['current_count'] == 0 || $roomInfo['owner'] == $username) {
            GameRoom::make()->close($roomInfo['number']);
        } else {
            if ($reStatistics) {
                // 广播通知有人离开房间
                di()->get(\Hyperf\SocketIOServer\SocketIO::class)
                    ->of('/game')
                    ->to($roomNumber)
                    ->emit('leave-room', $username);
            }
        }

        try {
            /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
            $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

            // 将当前用户移出socket房间
            $userFd = OnlineMember::make()->getFd($username);
            $userSid = (string) di()->get(SidProviderInterface::class)->getSid((int) $userFd);
            $socketIO->of('/game')->getAdapter()->del($userSid, $roomNumber);
        } catch (Throwable $e) {}
    }

    /**
     * 软移除房间成员
     *
     * @param string $roomNumber
     * @param string $username
     * @param bool $notice 是否进行广播通知
     * @param bool $reStatistics 是否重新统计人数
     * @return void
     */
    public function softRemoveMember(string $roomNumber, string $username)
    {
        if (empty($roomNumber)) {
            return true;
        }

        GameRoomMember::make()->rememberInfo($roomNumber, $username, function ($info) {
            $info['is_quit'] = 1;
            // 提前标记游戏结束时间
            $info['over_time'] = intval(microtime(true) * 10000);
            return $info;
        });

        // 房间人数减1
        $roomInfo = GameRoom::make()->rememberInfo($roomNumber, function ($roomInfo) {
            $roomInfo['current_count']--;
            return $roomInfo;
        });
        if (empty($roomInfo)) {
            return true;
        }

        // 房间内没人时，解散房间
        if (!empty($roomInfo) && $roomInfo['current_count'] <= 0) {
            GameRoom::make()->close($roomInfo['number']);
        } else {
            // 广播通知有人离开房间
            di()->get(\Hyperf\SocketIOServer\SocketIO::class)
                ->of('/game')
                ->to($roomNumber)
                ->emit('leave-room', $username);

            // 如果房间没有在线玩家时，30秒后自动关闭房间
            $this->service->gameRoomAutoCloseJob($roomNumber);

            // 检测是否结束游戏
            $this->service->checkGameOver($roomNumber);
        }

        try {
            /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
            $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

            // 将当前用户移出socket房间
            $userFd = OnlineMember::make()->getFd($username);
            $userSid = (string) di()->get(SidProviderInterface::class)->getSid((int) $userFd);
            $socketIO->of('/game')->getAdapter()->del($userSid, $roomNumber);
        } catch (Throwable $e) {}
    }

    /**
     * 标记离线 房间成员
     *
     * @param string $roomNumber
     * @param string $username
     * @param bool $notice 是否进行广播通知
     * @param bool $reStatistics 是否重新统计人数
     * @return void
     */
    public function offlineMember(string $roomNumber, string $username)
    {
        if (empty($roomNumber)) {
            return true;
        }

        GameRoomMember::make()->rememberInfo($roomNumber, $username, function ($info) {
            $info['is_online'] = 0;
            // 提前标记游戏结束时间
            $info['over_time'] = intval(microtime(true) * 10000);
            return $info;
        });

        // 房间内没人时，解散房间
        if (!empty($roomInfo) && $roomInfo['current_count'] <= 0) {
            GameRoom::make()->close($roomInfo['number']);
        } else {
            // 广播通知有人离线
            di()->get(\Hyperf\SocketIOServer\SocketIO::class)
                ->of('/game')
                ->to($roomNumber)
                ->emit('player-offline', $username);

            // 如果房间没有在线玩家时，30秒后自动关闭房间
            $this->service->gameRoomAutoCloseJob($roomNumber);
        }

        try {
            /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
            $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

            // 将当前用户移出socket房间
            $userFd = OnlineMember::make()->getFd($username);
            $userSid = (string) di()->get(SidProviderInterface::class)->getSid((int) $userFd);
            $socketIO->of('/game')->getAdapter()->del($userSid, $roomNumber);
        } catch (Throwable $e) {}
    }

    /**
     * 移除房间内所有成员
     *
     * @param string $roomNumber
     * @return void
     */
    public function removeAll(string $roomNumber)
    {
        $members = $this->getAll($roomNumber);
        collect($members)->each(function ($member) use ($roomNumber) {
            $member = json_decode($member, true);
            $this->removeMember($roomNumber, $member['username']);
        });
    }

    /**
     * 获取用户当前所在房间
     *
     * @param string $username
     * @return string
     */
    public function getMemberCurrentRoom(string $username)
    {
        $result = '';
        collect(GameRoom::make()->all())->keys()->each(function ($roomNumber) use (&$result, $username) {
            $roomNumber = (string) $roomNumber;
            collect($this->getAll($roomNumber))->each(function ($info, $rusername) use (&$result, $username, $roomNumber) {
                $info = json_decode($info, true);
                if ($rusername == $username && $info['is_quit'] != 1) {
                    $result = $roomNumber;
                    return false;
                }
            });

            if (!empty($result)) {
                return false;
            }
        });

        return (string) $result;
    }

    /**
     * 用户离开当前所在房间
     *
     * @param string $username
     * @return void
     */
    public function leaveMemberCurrentRoom(string $username)
    {
        $roomNumber = $this->getMemberCurrentRoom($username);
        if (!empty($roomNumber)) {
            $this->removeMember($roomNumber, $username);
        }
    }

    /**
     * 判断用户是否已在房间内
     *
     * @param string $username
     * @return bool
     */
    public function memberInRoom(string $username)
    {
        return !empty($this->getMemberCurrentRoom($username));
    }

    /**
     * 房间成员信息
     *
     * @param string $roomNumber
     * @param string $username
     * @return array
     */
    public function getMemberInfo(string $roomNumber, string $username)
    {
        $info = $this->get($roomNumber, $username);
        if (false === $info) {
            return false;
        }
        return json_decode($info, true);
    }

    /**
     * 房间成员列表
     *
     * @param string $roomNumber 房间号
     * @return array
     */
    public function getMemberList(string $roomNumber)
    {
        $members = array_keys($this->getAll($roomNumber));
        return collect($members)->map(function ($username) use ($roomNumber) {
            $info = $this->getMemberInfo((string) $roomNumber, (string) $username);
            return $info;
        })->values()->sortBy('join_time')->values()->toArray();
    }

    /**
     * 设置准备状态
     *
     * @param string $roomNumber 房间号
     * @param string $username 用户名
     * @param int $readyStatus 准备状态 0-未准备 1-准备
     * @return void
     */
    public function setReadyStatus(string $roomNumber, string $username, int $readyStatus)
    {
        $info = $this->rememberInfo($roomNumber, $username, function ($info) use ($readyStatus) {
            $info['is_ready'] = $readyStatus;
            return $info;
        });
        if (!$info) {
            return false;
        }

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知所有房间内的用户
        $socketIO->of('/game')->to($roomNumber)->emit('room-update');
    }

    /**
     * 更新游戏中的数据
     *
     * @param string $roomNumber 房间号
     * @param string $username 用户名
     * @param array $data 数据
     * @return void
     */
    public function updateGameData(string $roomNumber, string $username, array $data)
    {
        $data = $this->rememberInfo($roomNumber, $username, function ($info) use ($data) {
            $info['points'] = $data['points'] ?? $info['points'];
            $info['is_ready'] = $data['is_ready'] ?? $info['is_ready'];
            $info['is_over'] = $data['is_over'] ?? $info['is_over'];
            $info['block_index'] = $data['block_index'] ?? $info['block_index'];
            $info['cur'] = $data['cur'] ?? $info['cur'];
            $info['speed_run'] = $data['speed_run'] ?? $info['speed_run'];
            $info['clear_lines'] = $data['clear_lines'] ?? $info['clear_lines'];
            $info['matrix'] = $data['matrix'] ?? $info['matrix'];
            $info['discharge_buffers'] = $data['discharge_buffers'] ?? $info['discharge_buffers'];
            $info['fill_buffers'] = $data['fill_buffers'] ?? $info['fill_buffers'];

            // 结束时，标记结束时间（用作排名）
            if (!empty($info['is_over'])) {
                $info['over_time'] = intval(microtime(true) * 10000);
            }

            return $info;
        });

        // 当有玩家结束时，尝试判定游戏是否结束
        if (!empty($data['is_over'])) {
            $this->service->checkGameOver($roomNumber);
        }

        return $data;
    }

    /**
     * 重新更新信息
     *
     * @param string $roomNumber 房间号
     * @param string $username 用户名
     * @param callable $handler
     * @return array 更新后的信息
     */
    public function rememberInfo(string $roomNumber, string $username, callable $handler)
    {
        $info = $this->getMemberInfo($roomNumber, $username);
        if (empty($info)) {
            return false;
        }
        $newInfo = $handler($info);
        $this->add($roomNumber, $username, json_encode($newInfo));
        return $newInfo;
    }

    /**
     * 初始化清理
     *
     * @return void
     */
    public function initClear()
    {
        $rooms = GameRoom::make()->all();
        collect($rooms)->each(function ($room) {
            $room = json_decode($room, true);
            $roomNumber = (string) $room['number'];
            $members = $this->getAll($roomNumber);
            collect($members)->each(function ($member) use ($roomNumber) {
                $member = json_decode($member, true);
                $this->rem($roomNumber, $member['username']);
            });
        });
    }
}
