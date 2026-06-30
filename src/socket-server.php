<?php

require 'error-handler.php';
require 'register-shutdown-function.php';
require 'socket-constants.php';
require 'http-protocol-parser.php';

$clientsByIpAndPort = [];
$clientsByUuid = [];
$messages = [];

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

socket_bind($socket, '0.0.0.0', 4000);

socket_listen($socket);

socket_set_nonblock($socket);

echo "Server is running on port 4000\n";

while(true) {
    if (($newSocket = socket_accept($socket)) !== false) {
        var_dump($newSocket);

        socket_getpeername($newSocket, $ip, $port);
        
        echo "Client has connected: {$ip}:{$port}\n";

        socket_set_nonblock($newSocket);
        
        $clientsByIpAndPort["{$ip}:{$port}"] = [
            'socket' => $newSocket, 
            'ip' => $ip, 
            'port' => $port
        ];

        echo "Client added to clients array\n";

        var_dump("Clientes:\n");
        var_dump($clientsByIpAndPort);
    }

    foreach($clientsByIpAndPort as $key => &$client) {
        try {
            $data = socket_read($client['socket'], 1024);
        } catch (ErrorException $e) {
            $clientIp = $client['ip'];
            $clientPort = $client['port'];

            echo "Client [$key] {$clientIp}:{$clientPort}\n";
            echo $e->getMessage() . PHP_EOL;

            if ($client['uuid'] ?? null) {
                unset($clientsByUuid[$client['uuid']]);
            }

            unset($clientsByIpAndPort[$key]);

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

                if ($client['uuid'] ?? null) {
                    unset($clientsByUuid[$client['uuid']]);
                }

                unset($clientsByIpAndPort[$key]);
            }

            continue;
        }

        var_dump("Leu dados do cliente: {$data}\n");
        
        if (strpos($data, 'INFO_CLIENT:') !== false) {
            $dataModified = str_replace('INFO_CLIENT:', '', $data);
            $clientInfo = json_decode($dataModified, true);

            $client += $clientInfo;

            $clientUuid = $clientInfo['uuid'] ?? null;

            if (! $clientUuid) {
                socket_write($client['socket'], "ERROR:uuid is required in info client event.");

                socket_close($client['socket']);

                unset($clientsByIpAndPort[$key]);

                continue;
            }

            $clientByUuidElement = $clientsByUuid[$clientUuid] ?? null;

            if (! $clientByUuidElement) {
                $clientsByUuid[$clientUuid] = $client;
            }

            echo "Recebeu informações do cliente: {$data}\n";

            continue;
        }

        if (strpos($data, 'POST /api/v1/events HTTP') !== false) {
            $httpRequest = HttpRequestParser::parse($data) ?: '';

            $requestBodyJson = $httpRequest ? $httpRequest->getBody() : '';

            $requestBody = json_decode($requestBodyJson, true);

            if (! is_array($requestBody)) {
                $text = <<<TEXT
                HTTP/1.1 400 Bad Request
                Content-Type: application/json
                Content-Length: 35
                Access-Control-Allow-Origin: http://localhost:5173
                
                {"message":"Invalid request body."}
                TEXT;

                socket_write($client['socket'], $text);

                unset($clientsByIpAndPort[$key]);
                
                socket_close($client['socket']);
                
                continue;
            }

            if (! array_key_exists('event', $requestBody) || empty($requestBody['event'])) {
                $text = <<<TEXT
                HTTP/1.1 422 Unprocessable Entity
                Content-Type: application/json
                Content-Length: 38
                Access-Control-Allow-Origin: http://localhost:5173
                
                {"message":"event field is required."}
                TEXT;
                
                socket_write($client['socket'], $text);

                unset($clientsByIpAndPort[$key]);
                
                socket_close($client['socket']);
                
                continue;
            }

            if (! array_key_exists('channel', $requestBody) || empty($requestBody['channel'])) {
                $text = <<<TEXT
                HTTP/1.1 422 Unprocessable Entity
                Content-Type: application/json
                Content-Length: 40
                Access-Control-Allow-Origin: http://localhost:5173
                
                {"message":"channel field is required."}
                TEXT;
                socket_write($client['socket'], $text);

                unset($clientsByIpAndPort[$key]);
                
                socket_close($client['socket']);
                
                continue;
            }

            $message = json_encode([
                'event' => $requestBody['event'], 
                'channel' => $requestBody['channel']
            ]);

            $message = "DATA_EVENT:{$message}";

            $clientsByUuidElement = $clientsByUuid[$requestBody['channel']] ?? null;

            if (! $clientsByUuidElement) {
                $text = <<<TEXT
                HTTP/1.1 404 Not Found
                Content-Type: application/json
                Content-Length: 31
                Access-Control-Allow-Origin: http://localhost:5173
                
                {"message":"Client not found."}
                TEXT;

                socket_write($client['socket'], $text);

                socket_close($client['socket']);

                unset($clientsByIpAndPort[$key]);
                
                continue;
            }

            $result = socket_write(
                $clientsByUuidElement['socket'], 
                $message
            );

            if ($result === false) {
                echo "Failed to send message to client\n";
            }

            while (($responseBody = socket_read($clientsByUuidElement['socket'], 1024)) == false) {
                //
            }

            echo 'Recebeu output do cliente' . PHP_EOL;

            var_dump($responseBody);

            $clientOutput = str_replace('DATA_EVENT:', '', $responseBody);
            
            // Converte a string nativa do terminal do Windows (CP850) para UTF-8 caso venha crua ou quebrada
            if (json_decode($clientOutput) === null) {
                $convertedOutput = mb_convert_encoding($clientOutput, 'UTF-8', 'CP850');
                $responseBodyJson = json_encode(['output' => trim($convertedOutput)]);
            } else {
                $responseBodyJson = json_encode(['output' => trim($clientOutput)]);
            }

            $strLen = strlen($responseBodyJson);

            $text = <<<TEXT
            HTTP/1.1 200 OK
            Content-Type: application/json
            Content-Length: {$strLen}
            Access-Control-Allow-Origin: http://localhost:5173
            
            {$responseBodyJson}
            TEXT;

            socket_write($client['socket'], $text);

            socket_close($client['socket']);

            unset($clientsByIpAndPort[$key]);
        }

        if (strpos($data, 'GET /api/v1/clients HTTP') !== false) {
            $dados = explode(PHP_EOL, $data);

            $clientsByUuidArray = array_map(function (array $value) {
                if (
                    ! array_key_exists('hostname', $value) 
                    && ! array_key_exists('username', $value) 
                    && ! array_key_exists('operationSystem', $value)) {
                        return null;
                }

                return [
                    'uuid' => $value['uuid'] ?? 'unknown',
                    'ip' => $value['ip'], 
                    'port' => $value['port'], 
                    'hostname' => $value['hostname'] ?? 'unknown', 
                    'username' => $value['username'] ?? 'unknown', 
                    'operationSystem' => $value['operationSystem'] ?? 'unknown'
                ];
            }, $clientsByUuid);

            $responseBody = json_encode(array_values(array_filter($clientsByUuidArray)));
            $contentLength = strlen($responseBody);

            $text = <<<TEXT
            HTTP/1.1 200 OK
            Content-Type: application/json
            Content-Length: {$contentLength}
            Access-Control-Allow-Origin: *
            
            {$responseBody}
            TEXT;
            
            socket_write($client['socket'], $text);

            unset($clientsByIpAndPort[$key]);
            
            socket_close($client['socket']);

            continue;
        }

        if (strpos($data, 'OPTIONS /api/v1/events HTTP') !== false) {
            $text = <<<TEXT
            HTTP/1.1 204 No Content
            Content-Type: application/json
            Content-Length: 0
            Access-Control-Allow-Origin: http://localhost:5173
            Access-Control-Allow-Methods: POST
            Access-Control-Allow-Headers: Content-Type
            TEXT;
            
            socket_write($client['socket'], $text);

            unset($clientsByIpAndPort[$key]);
            
            socket_close($client['socket']);

            continue;
        }
    }
}