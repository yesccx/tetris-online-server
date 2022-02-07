<?php

declare (strict_types = 1);

namespace App\Websocket\Controllers;

use App\Cache\GameRoom;
use App\Cache\GameRoomMember;
use App\Cache\GameRoomNumber;
use App\Cache\OnlineMember;
use App\Cache\SocketMember;
use App\Services\GameRoomService;
use App\Traits\WebsocketResponse;
use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;
use Hyperf\SocketIOServer\Annotation\Event;
use Hyperf\SocketIOServer\Annotation\SocketIONamespace;
use Hyperf\SocketIOServer\BaseNamespace;
use Hyperf\SocketIOServer\Socket;

/**
 * @SocketIONamespace("/game")
 */
class GameController extends BaseNamespace
{
    use WebsocketResponse;

    /**
     * @Inject
     * @var GameRoomService
     */
    protected $service;

    /**
     * 创建房间
     *
     * @Event("create-room")
     * @param Socket $socket
     * @param string $data
     */
    public function createRoom(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        if (empty($fd)) {
            return $this->responseError('未知错误');
        }
        $username = SocketMember::make()->getUserName($fd);

        // 退出之前的房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);
        if (!empty($currentRoom)) {
            $roomMember->removeMember($currentRoom, $username);
        }

        // 创建房间
        $roomNumber = GameRoomNumber::make()->nextRoomNumber();
        $roomInfo = [
            'title'         => $username . '的房间 ' . Carbon::now(),
            'number'        => $roomNumber,
            'current_count' => 0,
            'max_count'     => 4,
            'owner'         => $username,
            'format_number' => (string) 'A' . str_pad($roomNumber, 5, '0', STR_PAD_LEFT),
            'status'        => 0,
            'pause'         => 0,
            'blocks'        => [],
            'speed_start'   => 1,
            'mode'          => 2,
            'start_lines'   => 0,
        ];
        GameRoom::make()->create($roomNumber, $username, $roomInfo);

        return $this->responseData($roomInfo);
    }

    /**
     * 房间列表
     *
     * @Event("room-list")
     * @param Socket $socket
     */
    public function roomList(Socket $socket)
    {
        $list = GameRoom::make()->getList();
        return $this->responseData($list);
    }

    /**
     * 房间信息
     *
     * @Event("room-info")
     * @param Socket $socket
     * @return void
     */
    public function roomInfo(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内');
        }

        $info = GameRoom::make()->getInfo($currentRoom);
        if (empty($info['number'])) {
            return $this->responseError('房间已解散');
        }

        // 当前玩家信息
        $info['userinfo'] = GameRoomMember::make()->getMemberInfo($info['number'], $username);

        // 房间成员(玩家)
        $info['members'] = GameRoomMember::make()->getMemberList($info['number']);

        return $this->responseData($info);
    }

    /**
     * 房间成员列表
     *
     * @Event("room-member-list")
     * @param Socket $socket
     */
    public function roomMemberList(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内');
        }

        $list = GameRoomMember::make()->getMemberList($currentRoom);
        return $this->responseData($list);
    }

    /**
     * 加入房间
     *
     * @Event("join-room")
     * @return array $data
     */
    public function joinRoom(Socket $socket, $data)
    {
        $number = (string) ($data['number'] ?? '');
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        if (empty($number)) {
            return $this->responseError('房间号不能为空');
        }

        // 判断当前房间人数、状态(未开始)
        $roomInfo = GameRoom::make()->getInfo($number);
        if (empty($roomInfo)) {
            return $this->responseError('房间不存在');
        } else if ($roomInfo['current_count'] == $roomInfo['max_count']) {
            return $this->responseError('房间人数已满');
        } else if ($roomInfo['status'] == 1) {
            return $this->responseError('房间已开始');
        }

        // 退出之前的房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);
        if (!empty($currentRoom)) {
            // 已在当前房间内
            if ($currentRoom == $number) {
                return $this->responseSuccess();
            }
            $roomMember->removeMember($currentRoom, $username);
        }

        GameRoomMember::make()->addMember($number, $username);

        return $this->responseSuccess();
    }

    /**
     * 离开房间
     *
     * @Event("leave-room")
     */
    public function leaveRoom(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 退出当前的房间(游戏开始仅标记为退出)
        $roomMemberSrv = GameRoomMember::make();
        $roomSrv = GameRoom::make();
        $roomNumber = $roomMemberSrv->getMemberCurrentRoom($username);
        if (!empty($roomNumber)) {
            $room = $roomSrv->getInfo($roomNumber);
            if (!empty($room) && $room['status'] == 1) {
                $roomMemberSrv->rememberInfo($roomNumber, $username, function ($info) {
                    $info['is_quit'] = 1;
                    // 提前标记游戏结束时间
                    $info['over_time'] = intval(microtime(true) * 10000);
                    return $info;
                });

                // 房间人数减1
                $room = $roomSrv->rememberInfo($roomNumber, function ($info) {
                    $info['current_count'] = $info['current_count'] - 1;
                    return $info;
                });

                // 房间内没人时， 解散房间
                if ($room['current_count'] <= 0) {
                    $roomSrv->close($room['number']);
                } else {
                    // 如果房间没有在线玩家时，30秒后自动关闭房间
                    $this->service->gameRoomAutoCloseJob($roomNumber);
                }
            } else {
                $roomMemberSrv->removeMember($roomNumber, $username);
            }
        }

        return $this->responseSuccess();
    }

    /**
     * 在线人数
     *
     * @Event("get-online-count")
     */
    public function getOnlineCount(Socket $socket)
    {
        $count = OnlineMember::make()->getCount();

        return $this->responseData(['count' => $count]);
    }

    /**
     * 游戏准备
     *
     * @Event("game-ready")
     */
    public function gameReady(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);

        GameRoomMember::make()->setReadyStatus($currentRoom, $username, 1);

        return $this->responseSuccess();
    }

    /**
     * 游戏取消准备
     *
     * @Event("game-unready")
     */
    public function gameUnready(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);

        GameRoomMember::make()->setReadyStatus($currentRoom, $username, 0);

        return $this->responseSuccess();
    }

    /**
     * 开始游戏
     *
     * @Event("game-start")
     */
    public function gameStart(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        // 判断是否为房主
        $gameRoomSrv = GameRoom::make();
        $info = $gameRoomSrv->getInfo($currentRoom);
        if (empty($info)) {
            return $this->responseError('房间不存在！');
        } else if ($info['owner'] != $username) {
            return $this->responseError('你不是房主！');
        }

        // 判断是否有玩家未准备
        $members = $roomMemberSrv->getMemberList($currentRoom);
        if (!collect($members)->every(function ($member) {
            return !empty($member['is_ready']);
        })) {
            return $this->responseError('有玩家未准备！');
        }

        // 当玩家数大于1时，需要至少两个队伍
        if (count($members) > 1) {
            if (collect($members)->groupBy('team')->count() == 1) {
                return $this->responseError('游戏至少需要两支队伍！');
            }
        }

        // 预告生成方块集
        $blocks = [];
        $blockMap = [
            1 => 'I', 2 => 'L', 3 => 'J',
            4 => 'Z', 5 => 'S', 6 => 'O', 7 => 'T',
        ];
        for ($i = 1; $i <= 10000; $i++) {
            $blocks[] = $blockMap[rand(1, 7)];
        }
        $gameRoomSrv->updateBlocks($currentRoom, $blocks);

        // 更新房间状态
        $roomInfo = $gameRoomSrv->updateStatus($currentRoom, 1);
        if (empty($roomInfo)) {
            return $this->responseError('游戏开始失败');
        }

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知用户游戏开始
        $socketIO->of('/game')->to($currentRoom)->emit('game-start', $roomInfo);

        return $this->responseSuccess();
    }

    /**
     * 游戏中的数据上报
     *
     * @Event("game-data-report")
     * @return string $data
     */
    public function gameDataReport(Socket $socket, array $data)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        $roomMemberSrv->updateGameData($currentRoom, $username, $data);

        return $this->responseSuccess();
    }

    /**
     * 游戏暂停
     *
     * @Event("game-pause")
     */
    public function gamePause(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        // 更新房间暂停状态
        $gameRoomSrv = GameRoom::make();
        $roomInfo = $gameRoomSrv->updatePauseStatus($currentRoom, 1);
        if (empty($roomInfo)) {
            return $this->responseError('游戏暂停失败');
        }

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知用户游戏暂停
        $socketIO->of('/game')->to($currentRoom)->emit('game-pause');

        return $this->responseSuccess();
    }

    /**
     * 游戏暂停恢复
     *
     * @Event("game-unpause")
     */
    public function gameUnpause(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        // 更新房间暂停状态
        $gameRoomSrv = GameRoom::make();
        $roomInfo = $gameRoomSrv->updatePauseStatus($currentRoom, 0);
        if (empty($roomInfo)) {
            return $this->responseError('游戏暂停恢复失败');
        }

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知用户游戏恢复暂停
        $socketIO->of('/game')->to($currentRoom)->emit('game-unpause');

        return $this->responseSuccess();
    }

    /**
     * 游戏消除行
     *
     * @Event("game-block-clear")
     */
    public function gameBlockClear(Socket $socket, $data)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知其他玩家
        $socketIO->of('/game')->to($currentRoom)->emit('game-block-clear', [
            'username' => $username,
            'lines'    => $data,
        ]);

        return $this->responseSuccess();
    }

    /**
     * 游戏设置更新
     *
     * @Event("game-settings")
     */
    public function gameSettings(Socket $socket, $data)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        GameRoom::make()->rememberInfo($currentRoom, function ($info) use (&$data) {
            $info['speed_start'] = $data['speed_start'] ?? $info['speed_start'];
            $info['mode'] = $data['mode'] ?? $info['mode'];

            $data['spped_restart'] = $info['speed_start'];
            $data['mode'] = $info['mode'];

            return $info;
        });

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知其他玩家
        $socketIO->of('/game')->to($currentRoom)->emit('game-settings', $data);

        return $this->responseSuccess();
    }

    /**
     * 玩家设置更新
     *
     * @Event("player-settings")
     */
    public function playerSettings(Socket $socket, $data)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        // 获取当前所在房间
        $roomMemberSrv = GameRoomMember::make();
        $currentRoom = $roomMemberSrv->getMemberCurrentRoom($username);
        if (empty($currentRoom)) {
            return $this->responseError('当前不在房间内！');
        }

        GameRoomMember::make()->rememberInfo($currentRoom, $username, function ($info) use (&$data) {
            $info['team'] = $data['team'] ?? $info['team'];

            return $info;
        });

        /** @var \Hyperf\SocketIOServer\SocketIO $socketIO */
        $socketIO = di()->get(\Hyperf\SocketIOServer\SocketIO::class);

        // 通知其他玩家
        $socketIO->of('/game')->to($currentRoom)->emit('room-update');

        return $this->responseSuccess();
    }

    /**
     * 加入上一次房间
     *
     * @Event("join-last-room")
     * @return array $data
     */
    public function joinLastRoom(Socket $socket)
    {
        $fd = (string) $socket->getFd();
        $username = SocketMember::make()->getUserName($fd);

        $room = GameRoomMember::make()->getMemberCurrentRoom($username);
        if (empty($room)) {
            return $this->responseError('房间不存在');
        }

        GameRoomMember::make()->rejoin((string) $room, $username);

        return $this->responseSuccess();
    }
}
