<?php


class SocketProxy
{
    /***
     * @var  array fd 为key的数组
     */
    protected $client_list;
    /**
     * @var swoole_server
     */
    protected $serv;
    protected $index = 0;
    protected $mode = SWOOLE_PROCESS;

    const ProxyStep_1 = 1;
    const ProxyStep_2 = 2;

    function run()
    {
        $serv = new swoole_server("0.0.0.0", 8989, $this->mode);
        $serv->set(array(
            'worker_num' => 100, //worker process num
            'backlog' => 128, //listen backlog
            'open_tcp_keepalive' => 1,
            // 'log_file' => '/data/log/proxy/tcp.log', //swoole error log
        ));
        $serv->on('WorkerStart', array($this, 'onStart'));
        $serv->on('Receive', array($this, 'onReceive'));
        $serv->on('Close', array($this, 'onClose'));
        $serv->on('WorkerStop', array($this, 'onShutdown'));
        $serv->start();
    }

    function onStart($serv)
    {
        $this->serv = $serv;
        //echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    function onShutdown($serv)
    {
        echo "Server: onShutdown\n";
    }

    function onClose($serv, $fd, $from_id)
    {
        //echo "onClose: frontend[$fd]\n";
    }

    function doRequest(Swoole\Server $serv, $request_fd, $from_id, $data)
    {
        $ip = $this->client_list[$request_fd]['ip'];
        $port = $this->client_list[$request_fd]['port'];
        //echo "向远程发送数据：".$ip." ".$port." \n";

        if(isset($this->client_list[$request_fd]['client'])){
           // echo "连续请求：\n";
            $socket = $this->client_list[$request_fd]['client'];
            //echo "待发送的数据：".bin2hex($data)."\n";
            $socket->send($data);
        }else{
            //连接到后台服务器
            $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
            $this->client_list[$request_fd]['client'] = $socket;

            $socket->on('connect', function (swoole_client $socket) use ($data,$request_fd) {
                //echo "连接成功：\n";
                //echo "待发送的数据：".bin2hex($data)."\n";
                $socket->send($data);
            });

            $socket->on('receive', function (swoole_client $socket, $data) use ($request_fd) {
                //echo "连接成功：\n";
                //echo "接受到的数据 : ".bin2hex($data)."\n";
                $this->serv->send($request_fd, $data);
            });

            $socket->on('error', function (swoole_client $socket_client) use ($request_fd,$socket) {
                //echo "连接远程服务器失败\n";
                $this->serv->send($request_fd, "HTTP/1.1 500 server error");
                if(isset($this->client_list[$request_fd]))
                    unset($this->client_list[$request_fd]);

                if($socket->isConnected()){
                    $socket->close();
                }
            });

            $socket->on('close', function (swoole_client $socket) use ($request_fd) {
                if(isset($this->client_list[$request_fd])){
                    unset($this->client_list[$request_fd]);
                }
            });


            if (!$socket->connect($ip, $port)) {
                echo " ERROR: cannot connect to IP: $ip .\n";
                $this->serv->send($request_fd, "HTTP/1.1 500 not server ");
                $socket->close();
                if(isset($this->client_list[$request_fd]))
                    unset($this->client_list[$request_fd]);
                //$this->serv->close($fd);
            }
        }

    }

    function onReceive( Swoole\Server $serv, $fd, $from_id, $data)
    {
        if(!isset($this->client_list[$fd])) {
            //echo "客户端请求代理".bin2hex($data)." \n";
            $this->client_list[$fd]['status'] =  self::ProxyStep_1;
            $serv->send( $fd, hex2bin("0500") );
            return;
        }

        $client = &$this->client_list[$fd];
        switch ($client['status']){
            case self::ProxyStep_1:
                $client['status'] = self::ProxyStep_2;
                $hexdata= bin2hex($data);
                //echo "要代理的地址：".$hexdata."\n";
                $addr = substr($hexdata,8,8);
                $port = substr($hexdata,16,4);
                $addr = long2ip(hexdec($addr));
                $port =  hexdec($port);
                //echo "端口：$port =>".$port."\n";
                //echo "IP: $addr=> ".$addr."\n";
                $client['ip'] = $addr;
                $client['port'] = $port;
                $serv->send($fd ,hex2bin("05000001000000000000"));
                break;

            case  self::ProxyStep_2:
                $this->doRequest($serv,$fd,$from_id,$data);
            //05 01 00 01  6a 27 a2 f7 : 01bb
        }
        return;
    }

}
