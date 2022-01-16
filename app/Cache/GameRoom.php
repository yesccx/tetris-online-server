<?php

declare (strict_types = 1);

namespace App\Cache;

use App\Cache\Repository\HashRedis;
use App\Event\RoomListUpdateEvent;
use PhpParser\Node\Expr\FuncCall;

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
        $info = $this->getInfo($roomNumber);
        if (empty($info)) {
            return false;
        }

        $info['status'] = $status;
        $this->updateInfo($roomNumber, $info);

        return $info;
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
        $info = $this->getInfo($roomNumber);
        if (empty($info)) {
            return false;
        }

        $info['blocks'] = $blocks;
        $this->updateInfo($roomNumber, $info);

        return $info;
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

}
