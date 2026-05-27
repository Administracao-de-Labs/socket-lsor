<?php

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

socket_bind($socket, '127.0.0.1', 4000);

socket_listen($socket);

socket_set_nonblock($socket);

while(true) {
    if (($newSocket = socket_accept($socket)) !== false) {
        echo "Client has connected: {$newSocket}\n";

        socket_getpeername($newSocket, $ip, $port);

        echo "Client has connected: {$ip}:{$port}\n";

        $data = socket_read($newSocket, 1024);

        echo "Data: {$data}\n";

        $response = <<<TEXT
        HTTP/1.1 400 Bad Request
        Content-Type: application/json
        Content-Length: 35
        
        {"message":"Invalid request body."}
        TEXT; 

        socket_write($newSocket, $response);

        socket_close($newSocket);
    }
}