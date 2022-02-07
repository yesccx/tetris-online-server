<?php

declare (strict_types = 1);

namespace App\Services;

use App\Cache\GameRoom;
use App\Cache\GameRoomMember;
use Hyperf\AsyncQueue\Annotation\AsyncQueueMessage;

/**
 * 游戏房间相关
 */
class GameRoomService
{

    /**
     * 房间自动关闭处理
     *
     * @AsyncQueueMessage(
     *      delay=30
     * )
     *
     * @param string $roomNumber 房间号
     * @return void
     */
    public function gameRoomAutoCloseJob(string $roomNumber)
    {
        try {
            $room = GameRoom::make()->getInfo($roomNumber);

            // 房间不存在或不在进行中状态时，不再做处理
            if (empty($room) || $room['status'] != 1) {
                return false;
            }

            // 房间正在进行游戏，且不存在在线玩家时，关闭房间
            $roomMembers = GameRoomMember::make()->getMemberList($roomNumber);
            if (collect($roomMembers)->every('is_online', '=', 0)) {
                GameRoom::make()->close($roomNumber);
            }
        } catch (\Throwable$e) {}
    }
}
