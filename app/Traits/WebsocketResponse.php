<?php

namespace App\Traits;

trait WebsocketResponse
{

    /**
     * 响应-失败
     *
     * @param string $message
     * @return array
     */
    protected function responseError(string $message = '失败')
    {
        return $this->buildResponseData(0, $message);
    }
    /**
     * 响应-成功
     *
     * @param string $message
     * @return array
     */
    protected function responseSuccess(string $message = '成功')
    {
        return $this->buildResponseData(1, $message);
    }

    /**
     * 响应-数据
     *
     * @param mixed $data
     * @return array
     */
    protected function responseData($data = [])
    {
        return $this->buildResponseData(1, '成功', $data);
    }

    /**
     * 构建响应数据
     *
     * @param int $success
     * @param string $message
     * @param array $data
     * @return array
     */
    protected function buildResponseData(int $success, string $message, $data = [])
    {
        return [
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ];
    }
}
