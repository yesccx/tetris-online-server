<?php

declare (strict_types = 1);

namespace App\Websocket\Controllers;

use App\Cache\OnlineMember;
use App\Cache\SocketMember;
use App\Traits\WebsocketResponse;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\SocketIOServer\Annotation\Event;
use Hyperf\SocketIOServer\Annotation\SocketIONamespace;
use Hyperf\SocketIOServer\BaseNamespace;
use Hyperf\SocketIOServer\Socket;

/**
 * @SocketIONamespace("/user")
 */
class UserController extends BaseNamespace implements OnCloseInterface
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
        $username = (string) ($data['username'] ?? '');
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
     * 退出登录
     *
     * @Event("logout")
     * @param Socket $socket
     * @param array $data
     */
    public function logout(Socket $socket)
    {
        $fd = $socket->getFd();
        SocketMember::make()->logout((string) $fd);
        stdout_log()->info('退出登录');

        return $this->responseSuccess();
    }

    /**
     * websocket 关闭事件
     */
    public function onClose($server, int $fd, int $reactorId): void
    {
        SocketMember::make()->logout((string) $fd);
        di()->get(\Hyperf\WebSocketServer\Server::class)->onClose($server, $fd, $reactorId);
    }

}
