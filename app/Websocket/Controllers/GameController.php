<?php

declare (strict_types = 1);

namespace App\Websocket\Controllers;

use App\Cache\GameRoom;
use App\Cache\GameRoomMember;
use App\Cache\GameRoomNumber;
use App\Cache\OnlineMember;
use App\Cache\SocketMember;
use App\Traits\WebsocketResponse;
use Carbon\Carbon;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\SocketIOServer\Annotation\Event;
use Hyperf\SocketIOServer\Annotation\SocketIONamespace;
use Hyperf\SocketIOServer\BaseNamespace;
use Hyperf\SocketIOServer\Socket;

/**
 * @SocketIONamespace("/game")
 */
class GameController extends BaseNamespace implements OnCloseInterface
{
    use WebsocketResponse;

    /**
     * 用户登录
     *
     * @Event("login")
     * @param Socket $socket
     * @param array $data
     */
    public function login(Socket $socket, $data = [])
    {
        $username = $data['username'] ?? '';
        $fd = $socket->getFd();

        if (empty($username)) {
            return $this->responseError('参数错误');
        } else if (OnlineMember::make()->has($username)) {
            return $this->responseError('用户名已存在');
        }

        SocketMember::make()->login((string) $fd, $username);

        return $this->responseSuccess();
    }

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
            'blocks'        => [],
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

        // 退出当前的房间
        $roomMember = GameRoomMember::make();
        $currentRoom = $roomMember->getMemberCurrentRoom($username);
        if (!empty($currentRoom)) {
            $roomMember->removeMember($currentRoom, $username);
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
            return !empty($member['ready']);
        })) {
            return $this->responseError('有玩家未准备！');
        }

        // 预告生成方块集
        $blocks = [];
        for ($i = 1; $i <= 10000; $i++) {
            $blocks[] = rand(1, 7);
        }
        $gameRoomSrv->updateBlocks($currentRoom, $blocks);

        // 更新房间状态
        $roomInfo = $gameRoomSrv->updateStatus($currentRoom, 1);

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

        $roomMemberSrv->setGameData($currentRoom, $username, $data['block_index'], $data['blocks'], $data['cur']);

        return $this->responseSuccess();
    }

    /**
     * websocket 关闭事件
     */
    public function onClose($server, int $fd, int $reactorId): void
    {
        $this->logout($server, $fd, $reactorId);
        di()->get(\Hyperf\WebSocketServer\Server::class)->onClose($server, $fd, $reactorId);
    }

    /**
     * 退出登录
     *
     * @return void
     */
    protected function logout($server, int $fd, int $reactorId)
    {
        SocketMember::make()->logout((string) $fd);
        stdout_log()->info('退出登录');
    }

}