<?php

require __DIR__ . '/error-handler.php';
require __DIR__ . '/register-shutdown-function.php';
require __DIR__ . '/socket-constants.php';

// Importação do parser de protocolo de acordo com a localização do arquivo na pasta src
if (file_exists(__DIR__ . '/http-protocol-parser.php')) {
    require __DIR__ . '/http-protocol-parser.php';
} else {
    require dirname(__DIR__) . '/http-protocol-parser.php';
}

// Estruturas de dados em memória para gerenciar o estado das conexões
$clientsByIpAndPort = [];
$clientsByUuid = [];
$messages = [];

// Criação do socket principal utilizando o protocolo TCP/IP (IPv4)
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

// Liberação imediata da porta do sistema operacional após reinicializações do script
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

// Vinculação do socket à interface de rede e porta de escuta configurada
socket_bind($socket, '0.0.0.0', 4000);
socket_listen($socket);

// Habilitação do modo assíncrono (não-bloqueante) no socket principal do servidor
socket_set_nonblock($socket);

echo "Server is running on port 4000\n";

while(true) {
    // Escuta e aceita novas conexões de entrada sem interromper a execução do loop
    if (($newSocket = socket_accept($socket)) !== false) {
        socket_getpeername($newSocket, $ip, $port);
        echo "Client has connected: {$ip}:{$port}\n";
        
        // Define cada canal aceito como não-bloqueante para isolamento de IO
        socket_set_nonblock($newSocket);
        
        $clientsByIpAndPort["{$ip}:{$port}"] = [
            'socket' => $newSocket, 
            'ip' => $ip, 
            'port' => $port,
            'buffer' => '' // Buffer dedicado para a reconstrução de streams fragmentados
        ];
    }

    // Processamento e roteamento de pacotes para o pool de conexões ativas
    foreach($clientsByIpAndPort as $key => &$client) {
        try {
            $data = @socket_read($client['socket'], 2048);
        } catch (ErrorException $e) {
            unset($clientsByUuid[$client['uuid'] ?? '']);
            unset($clientsByIpAndPort[$key]);
            continue;
        }

        // Tratamento para encerramento limpo de conexão disparado pelo lado do cliente
        if ($data === '') {
            unset($clientsByUuid[$client['uuid'] ?? '']);
            @socket_close($client['socket']);
            unset($clientsByIpAndPort[$key]);
            continue;
        }

        // Monitoramento de falhas críticas ou interrupções de IO na camada de transporte
        if ($data === false) {
            $socketCode = socket_last_error($socket);
            if (!in_array($socketCode, [SOCKET_ERROR_NON_BLOCKING_OPERATION, SOCKET_SUCCESS_CONNECTION])) {
                unset($clientsByUuid[$client['uuid'] ?? '']);
                @socket_close($client['socket']);
                unset($clientsByIpAndPort[$key]);
            }
            continue;
        }

        // Alimentação contínua do buffer de dados para leitura de pacotes assíncronos
        $client['buffer'] .= $data;

        // --- TRATAMENTO DE REQUISIÇÕES HTTP (INTERFACES E CLIENTES HTTP) ---
        
        // Validação e resposta do protocolo CORS Preflight (Verificação OPTIONS do Navegador)
        if (strpos($client['buffer'], 'OPTIONS ') !== false && strpos($client['buffer'], "\r\n\r\n") !== false) {
            $text = "HTTP/1.1 204 No Content\r\n" .
                    "Access-Control-Allow-Origin: *\r\n" .
                    "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n" .
                    "Access-Control-Allow-Headers: Content-Type, Authorization\r\n" .
                    "Connection: close\r\n\r\n";
            @socket_write($client['socket'], $text);
            @socket_close($client['socket']);
            unset($clientsByIpAndPort[$key]);
            continue;
        }

        // Rota HTTP GET: Compila e envia o catálogo de computadores do laboratório logados
        if (strpos($client['buffer'], 'GET /api/v1/clients HTTP') !== false && strpos($client['buffer'], "\r\n\r\n") !== false) {
            $clientsByUuidArray = array_map(function (array $value) {
                if (!array_key_exists('hostname', $value) && !array_key_exists('username', $value)) {
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
            $contentLength = mb_strlen($responseBody, '8bit');

            $text = "HTTP/1.1 200 OK\r\n" .
                    "Access-Control-Allow-Origin: *\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: {$contentLength}\r\n" .
                    "Connection: close\r\n\r\n" .
                    $responseBody;
            
            @socket_write($client['socket'], $text);
            @socket_close($client['socket']);
            unset($clientsByIpAndPort[$key]);
            continue;
        }

        // Rota HTTP POST: Processa as ações e requisições via parser estruturado
        if (strpos($client['buffer'], 'POST /api/v1/events HTTP') !== false) {
            $parts = explode("\r\n\r\n", $client['buffer'], 2);
            if (count($parts) < 2) continue;

            $httpRequest = HttpRequestParser::parse($client['buffer']) ?: '';
            $requestBodyJson = $httpRequest ? $httpRequest->getBody() : '';
            $requestBody = json_decode($requestBodyJson, true);

            if (!is_array($requestBody)) {
                $text = "HTTP/1.1 400 Bad Request\r\nAccess-Control-Allow-Origin: *\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n{\"message\":\"Invalid request body.\"}";
                @socket_write($client['socket'], $text);
                @socket_close($client['socket']);
                unset($clientsByIpAndPort[$key]);
                continue;
            }

            if (!array_key_exists('event', $requestBody) || empty($requestBody['event']) || empty($requestBody['channel'])) {
                $text = "HTTP/1.1 422 Unprocessable Entity\r\nAccess-Control-Allow-Origin: *\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n{\"message\":\"event and channel fields are required.\"}";
                @socket_write($client['socket'], $text);
                @socket_close($client['socket']);
                unset($clientsByIpAndPort[$key]);
                continue;
            }

            $clientsByUuidElement = $clientsByUuid[$requestBody['channel']] ?? null;
            if (!$clientsByUuidElement) {
                $text = "HTTP/1.1 404 Not Found\r\nAccess-Control-Allow-Origin: *\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n{\"message\":\"Client not found.\"}";
                @socket_write($client['socket'], $text);
                @socket_close($client['socket']);
                unset($clientsByIpAndPort[$key]);
                continue;
            }

            // Envelopamento e transmissão da carga útil (payload) para o terminal da máquina laboratório
            $message = "DATA_EVENT:" . json_encode(['event' => $requestBody['event'], 'channel' => $requestBody['channel']]);
            @socket_write($clientsByUuidElement['socket'], $message);

            // Acumula os pacotes TCP fragmentados da resposta para evitar corrupção de payloads extensos
            $responseBody = '';
            $retryCount = 0;
            while ($retryCount < 80) {
                $readChunk = @socket_read($clientsByUuidElement['socket'], 8192);
                if ($readChunk !== false && !empty($readChunk)) {
                    $responseBody .= $readChunk;
                    if (strpos($responseBody, '}') !== false && substr(trim($responseBody), -1) === '}') {
                        break;
                    }
                }
                usleep(10000);
                $retryCount++;
            }

            if (strpos($responseBody, 'DATA_EVENT:') !== false) {
                $responseBodyJson = str_replace('DATA_EVENT:', '', $responseBody);
            } else {
                $responseBodyJson = '';
            }

            $responseBodyJson = trim($responseBodyJson);

            // Sanitização de codificação de caracteres legados do prompt do Windows (CP850 para UTF-8)
            if (empty($responseBodyJson) || json_decode($responseBodyJson) === null) {
                $cleanOutput = !empty($responseBody) ? mb_convert_encoding($responseBody, 'UTF-8', 'CP850') : 'Comando executado com sucesso.';
                $cleanOutput = str_replace('DATA_EVENT:', '', $cleanOutput);
                $responseBodyJson = json_encode(['output' => trim($cleanOutput)]);
            }

            // Medição binária rigorosa do tamanho da resposta em bytes para evitar truncamento no navegador
            $strLen = mb_strlen($responseBodyJson, '8bit');

            $text = "HTTP/1.1 200 OK\r\n" .
                    "Access-Control-Allow-Origin: *\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: {$strLen}\r\n" .
                    "Connection: close\r\n\r\n" .
                    $responseBodyJson;

            @socket_write($client['socket'], $text);
            @socket_close($client['socket']);
            unset($clientsByIpAndPort[$key]);
            continue;
        }

        // --- TRATAMENTO DO PROTOCOLO INTERNO (MÁQUINAS CLIENTES TCP) ---
        
        // Identifica e cataloga o handshake inicial da máquina escrava do laboratório
        if (strpos($client['buffer'], 'INFO_CLIENT:') !== false) {
            $dataModified = str_replace('INFO_CLIENT:', '', $client['buffer']);
            $clientInfo = json_decode($dataModified, true);

            if (is_array($clientInfo) && isset($clientInfo['uuid'])) {
                $client += $clientInfo;
                $clientsByUuid[$clientInfo['uuid']] = &$client;
                echo "Recebeu informações do cliente: {$clientInfo['hostname']} (@{$clientInfo['username']})\n";
                $client['buffer'] = ''; 
            }
            continue;
        }
        
        // Controle de vazamento de memória (Memory Leak): Limpa buffers corrompidos ou órfãos
        if (strlen($client['buffer']) > 8192) {
            $client['buffer'] = '';
        }
    }
    
    // Intervalo de alívio de IO para estabilização do consumo de CPU em ambientes mononúcleo
    usleep(10000);
}