<?php

declare (strict_types = 1);

namespace App\Cache;

use App\Cache\Repository\HashRedis;
use App\Services\GameRoomService;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * Socket用户(fd<->username)
 */
class SocketMember extends HashRedis
{
    /**
     * @Inject
     * @var GameRoomService
     */
    protected $service;

    /**
     * 登录
     *
     * @param string $fd websocket fd
     * @param string $username
     * @return bool
     */
    public function login(string $fd, string $username)
    {
        $this->logout($fd);
        OnlineMember::make()->login($fd, $username);
        $this->updateInfo($fd, [
            'username' => $username,
        ]);

        return true;
    }

    /**
     * 获取用户信息
     *
     * @param string $fd
     * @return array
     */
    public function getInfo(string $fd)
    {
        return json_decode($this->get($fd), true);
    }

    /**
     * 更新用户信息
     *
     * @param string $fd
     * @param array $info
     * @return void
     */
    public function updateInfo(string $fd, array $info)
    {
        if (empty($fd)) {
            return true;
        }

        $this->add($fd, json_encode($info));
    }

    /**
     * 重新更新信息
     *
     * @param string $fd
     * @param callable $handler
     * @return array 更新后的信息
     */
    public function rememberInfo(string $fd, callable $handler)
    {
        $info = $this->getInfo($fd);
        $newInfo = $handler($info);
        $this->updateInfo($fd, $newInfo);
        return $newInfo;
    }

    /**
     * 获取用户名
     *
     * @param string $fd
     * @return string
     */
    public function getUserName(string $fd)
    {
        return $this->getInfo($fd)['username'] ?? '';
    }

    /**
     * 退出登录
     *
     * @param string $fd websocket fd
     * @return bool
     */
    public function logout(string $fd)
    {
        $username = $this->getUserName($fd);

        // 从所在房间内移除，或标记为离线
        if (!empty($username)) {
            try {
                $roomMemberSrv = GameRoomMember::make();
                $roomSrv = GameRoom::make();
                $roomNumber = $roomMemberSrv->getMemberCurrentRoom($username);
                if (!empty($roomNumber)) {
                    $room = $roomSrv->getInfo($roomNumber);
                    if (!empty($room) && $room['status'] == 1) {
                        $roomMemberSrv->rememberInfo($roomNumber, $username, function ($info) {
                            $info['is_online'] = 0;
                            // 提前标记游戏结束时间
                            $info['over_time'] = intval(microtime(true) * 10000);

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
                        $roomMemberSrv->leaveMemberCurrentRoom($username);
                    }
                }
            } catch (Throwable $e) {
                throw $e;
            } finally {
                OnlineMember::make()->logout($username);
            }
        }

        $this->rem($fd);

        return true;
    }

    /**
     * 初始化清理
     *
     * @return void
     */
    public function initClear()
    {
        OnlineMember::make()->initClear();
        $this->delete();
    }
}
