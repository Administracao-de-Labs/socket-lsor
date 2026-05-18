<?php

require 'error-handler.php';
require 'register-shutdown-function.php';
require 'socket-constants.php';

$clients = array();
$messages = array();

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

socket_bind($socket, '127.0.0.1', 4000);

socket_listen($socket);

socket_set_nonblock($socket);

echo "Server is running on port 4000\n";

while(true) {
    if (($newSocket = socket_accept($socket)) !== false) {
        var_dump($newSocket);

        socket_getpeername($newSocket, $ip, $port);
        
        echo "Client has connected: {$ip}:{$port}\n";
        
        $clients["{$ip}:{$port}"] = [
            'socket' => $newSocket, 
            'ip' => $ip, 
            'port' => $port
        ];

        echo "Client added to clients array\n";

        var_dump("Clientes:\n");
        var_dump($clients);
    }

    foreach($clients as $key => &$client) {
        try {
            $data = socket_read($client['socket'], 1024);
        } catch (ErrorException $e) {
            $clientIp = $client['ip'];
            $clientPort = $client['port'];

            echo "Client [$key] {$clientIp}:{$clientPort}\n";
            echo $e->getMessage() . PHP_EOL;

            unset($clients[$key]);

            continue;
        }

        if ($data === false) {
            $socketCode = socket_last_error($socket);

            // var_dump($socketCode);
            // var_dump(socket_strerror($socketCode));
            // var_dump($data);

            if (! in_array($socketCode, [SOCKET_ERROR_NON_BLOCKING_OPERATION, SOCKET_SUCCESS_CONNECTION])) {
                $clientIp = $client['ip'];
                $clientPort = $client['port'];

                echo "Client [$key] {$clientIp}:{$clientPort}\n";
                echo socket_strerror($socketCode) . PHP_EOL;

                unset($clients[$key]);
            }

            continue;
        }

        var_dump("Leu dados do cliente: {$data}\n");
        
        if (strpos($data, 'INFO_CLIENT:') !== false) {
            $dataModified = str_replace('INFO_CLIENT:', '', $data);
            $clientInfo = json_decode($dataModified, true);

            $client += $clientInfo;

            echo "Recebeu informações do cliente: {$data}\n";

            continue;
        }

        if (strpos($data, 'POST /api/v1/events HTTP') !== false) {
            $dados = explode(PHP_EOL, $data);

            $requestBody = json_decode($dados[count($dados) - 1], true);

            if (! is_array($requestBody)) {
                $text = <<<TEXT
                HTTP/1.1 400 Bad Request
                Content-Type: application/json
                Content-Length: 35
                
                {"message":"Invalid request body."}
                TEXT;

                socket_write($client['socket'], $text);

                unset($clients[$key]);
                
                socket_close($client['socket']);
                
                continue;
            }

            if (! array_key_exists('event', $requestBody)) {
                $text = <<<TEXT
                HTTP/1.1 422 Unprocessable Entity
                Content-Type: application/json
                Content-Length: 38
                
                {"message":"event field is required."}
                TEXT;
                
                socket_write($client['socket'], $text);

                unset($clients[$key]);
                
                socket_close($client['socket']);
                
                continue;
            }

            if (! array_key_exists('channel', $requestBody)) {
                $text = <<<TEXT
                HTTP/1.1 422 Unprocessable Entity
                Content-Type: application/json
                Content-Length: 40
                
                {"message":"channel field is required."}
                TEXT;
                socket_write($client['socket'], $text);

                unset($clients[$key]);
                
                socket_close($client['socket']);
                
                continue;
            }

            $message = json_encode([
                'event' => $requestBody['event'], 
                'channel' => $requestBody['channel']
            ]);

            $message = "DATA_EVENT:{$message}";

            foreach($clients as $key2 => $client2) {

                $text = <<<TEXT
                HTTP/1.1 200 OK
                Content-Type: application/json
                Content-Length: 40
                
                {"message":"Evento enviado com sucesso"}
                TEXT;

                if ($key2 == $key) {
                    $result = socket_write(
                        $client2['socket'], 
                        $text
                    );

                    socket_close($client2['socket']);
                    unset($clients[$key2]);

                    continue;
                }

                $result = socket_write(
                    $client2['socket'], 
                    $message
                );
    
                if ($result === false) {
                    echo "Failed to send message to client2\n";
                }
            }
        }

        if (strpos($data, 'GET /api/v1/clients HTTP') !== false) {
            $dados = explode(PHP_EOL, $data);

            $clientsArray = array_map(function (array $value) {
                if (
                    ! array_key_exists('hostname', $value) 
                    && ! array_key_exists('username', $value) 
                    && ! array_key_exists('operationSystem', $value)) {
                        return null;
                }

                return [
                    'ip' => $value['ip'], 
                    'port' => $value['port'], 
                    'hostname' => $value['hostname'] ?? 'unknown', 
                    'username' => $value['username'] ?? 'unknown', 
                    'operationSystem' => $value['operationSystem'] ?? 'unknown'
                ];
            }, $clients);

            $responseBody = json_encode(array_values(array_filter($clientsArray)));
            $contentLength = strlen($responseBody);

            $text = <<<TEXT
            HTTP/1.1 200 OK
            Content-Type: application/json
            Content-Length: {$contentLength}
            
            {$responseBody}
            TEXT;
            
            socket_write($client['socket'], $text);

            unset($clients[$key]);
            
            socket_close($client['socket']);

            continue;
        }
    }
}