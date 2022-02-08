<?php

declare (strict_types = 1);

namespace App\Cache;

use App\Cache\Repository\HashRedis;
use App\Event\RoomListUpdateEvent;

/**
 * 游戏房间
 */
class GameRoom extends HashRedis
{
    /**
     * 创建房间
     *
     * @param string $roomNumber
     * @param string $owner 房主
     * @param array $data 房间信息
     * @return void
     */
    public function create(string $roomNumber, string $owner, array $data = [])
    {
        $data['owner'] = $owner;
        $this->updateInfo($roomNumber, $data);

        // 房主自动加入房间
        GameRoomMember::make()->addMember($roomNumber, $owner, 1, 1);

        event()->dispatch(new RoomListUpdateEvent);
    }

    /**
     * 更新房间状态
     *
     * @param string $roomNumber
     * @param int $status
     * @return array
     */
    public function updateStatus(string $roomNumber, int $status = 0)
    {
        return $this->rememberInfo($roomNumber, function ($info) use ($status) {
            $info['status'] = $status;
            return $info;
        });
    }

    /**
     * 更新房间暂停状态
     *
     * @param string $roomNumber
     * @param int $status
     * @return array
     */
    public function updatePauseStatus(string $roomNumber, int $status = 0)
    {
        return $this->rememberInfo($roomNumber, function ($info) use ($status) {
            $info['pause'] = $status;
            return $info;
        });
    }

    /**
     * 更新房间方块
     *
     * @param string $roomNumber
     * @param array $blocks
     * @return array
     */
    public function updateBlocks(string $roomNumber, array $blocks = [])
    {
        return $this->rememberInfo($roomNumber, function ($info) use ($blocks) {
            $info['blocks'] = $blocks;
            return $info;
        });
    }

    /**
     * 关闭房间
     *
     * @param string $roomNumber
     * @return void
     */
    public function close(string $roomNumber)
    {
        GameRoomMember::make()->removeAll($roomNumber);
        $this->rem($roomNumber);

        event()->dispatch(new RoomListUpdateEvent);

        di()->get(\Hyperf\SocketIOServer\SocketIO::class)->of('/game')->to($roomNumber)->emit('room-close');
    }

    /**
     * 房间信息
     *
     * @param string $roomNumber
     * @return array
     */
    public function getInfo(string $roomNumber)
    {
        $info = $this->get($roomNumber);
        if (empty($info)) {
            throw new \Exception('房间已不存在');
        }
        return json_decode($info, true);
    }

    /**
     * 更新信息
     *
     * @param string $roomNumber
     * @param array $info
     * @return array
     */
    public function updateInfo(string $roomNumber, array $info)
    {
        $this->add($roomNumber, json_encode($info));

        event()->dispatch(new RoomListUpdateEvent);
    }

    /**
     * 重新更新信息
     *
     * @param string $roomNumber
     * @param callable $handler
     * @return array 更新后的信息
     */
    public function rememberInfo(string $roomNumber, callable $handler)
    {
        $info = $this->getInfo($roomNumber);
        if (empty($info)) {
            return false;
        }
        $newInfo = $handler($info);
        $this->updateInfo($roomNumber, $newInfo);
        return $newInfo;
    }

    /**
     * 房间列表
     *
     * @return array
     */
    public function getList()
    {
        $roomNumbers = array_keys($this->all());
        return collect($roomNumbers)->map(function ($roomNumber) {
            $info = $this->getInfo((string) $roomNumber);
            $info['number'] = (int) $info['number'];
            return $info;
        })->values()->sortBy('number')->values()->toArray();
    }

    /**
     * 初始化清理
     *
     * @return void
     */
    public function initClear()
    {
        $rooms = $this->getList();
        collect($rooms)->each(function ($room) {
            $this->rem((string) $room['number']);
        });
    }

    /**
     * 游戏结束
     *
     * @param string $roomNumber 房间号
     * @return void
     */
    public function gameOver(string $roomNumber)
    {
        // 重置房间数据
        $room = $this->rememberInfo($roomNumber, function ($info) {
            $info['status'] = 0;
            $info['pause'] = 0;
            $info['blocks'] = [];
            return $info;
        });
        if (empty($room)) {
            return false;
        }

        $roomMemberSrv = GameRoomMember::make();

        // 生成结算信息
        $members = $roomMemberSrv->getMemberList($roomNumber);
        $members = collect($members);
        $settlementData = $members->map(function ($member) {
            return [
                'username'          => $member['username'],
                'team'              => $member['team'],
                'over_time'         => $member['over_time'] ?: intval(microtime(true) * 10000),
                'points'            => $member['points'],
                'block_index'       => $member['block_index'],
                'clear_lines'       => $member['clear_lines'],
                'discharge_buffers' => $member['discharge_buffers'],
            ];
        })->sortByDesc('over_time')->values()->toArray();

        // 通知所有房间内的玩家，游戏结束
        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);
        $socketIO->of('/game')->to($roomNumber)->emit('game-over', $settlementData);

        // 重置房间内玩家数据
        $members->each(function ($member) use ($roomMemberSrv, $roomNumber) {
            if (!$member['is_online'] || $member['is_quit']) {
                // 从房间移除已离线、已退出玩家
                $roomMemberSrv->removeMember($roomNumber, $member['username'], false);
            } else {
                // 重置数据
                $roomMemberSrv->rememberInfo($roomNumber, $member['username'], function ($info) {
                    if (!$info['is_owner']) {
                        $info['is_ready'] = 0;
                    }
                    $info['is_over'] = 0;
                    $info['is_online'] = 1;
                    $info['points'] = 0;
                    $info['block_index'] = 0;
                    $info['clear_lines'] = 0;
                    $info['cur'] = null;
                    $info['matrix'] = null;
                    $info['discharge_buffers'] = 0;
                    $info['fill_buffers'] = 0;
                    $info['over_time'] = 0;
                    return $info;
                });
            }
        });
    }
}
