<?php

declare (strict_types = 1);

namespace App\Services;

use App\Cache\GameRoom;
use App\Cache\GameRoomMember;
use Hyperf\AsyncQueue\Annotation\AsyncQueueMessage;
use Hyperf\Utils\Coroutine\Concurrent;

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
            if (collect($roomMembers)->every(function ($member) {
                return $member['is_online'] == 0 || $member['is_quit'] == 1;
            })) {
                GameRoom::make()->close($roomNumber);
            }
        } catch (\Throwable$e) {}
    }

    /**
     * 检测游戏是否结束
     *
     * @AsyncQueueMessage
     *
     * @param string $roomNumber 房间号
     * @return void
     */
    public function checkGameOver(string $roomNumber)
    {
        try {
            $room = GameRoom::make()->getInfo($roomNumber);
            if ($room['status'] != 1) {
                return true;
            }

            // 尝试判定游戏是否结束
            $members = GameRoomMember::make()->getMemberList($roomNumber);
            if (!empty($members)) {
                $teamMembers = collect($members)->groupBy('team');

                // 单人游戏和多人游戏判断方式不同
                if ($teamMembers->count() == 1) {
                    $gameOver = collect($members)->every(function ($member) {
                        return $member['is_over'] || $member['is_quit'] || !$member['is_online'];
                    });
                } else {
                    $onlineMembers = collect($members)->filter(function ($member) {
                        return !($member['is_over'] || $member['is_quit']);
                    });

                    // 剩余队伍为1或正在进行中没有在线玩家，游戏结束
                    $gameOver = $onlineMembers->groupBy('team')->count() <= 1 || $onlineMembers->every(function ($member) {
                        return !$member['is_online'];
                    });
                }

                // 游戏结束
                if ($gameOver) {
                    (new Concurrent(1))->create(function () use ($roomNumber) {
                        GameRoom::make()->gameOver($roomNumber);
                    });
                }
            }
        } catch (\Throwable$e) {}
    }
}
