<?php

require 'error-handler.php';
require 'register-shutdown-function.php';
require 'socket-constants.php';

$operationSystem = PHP_OS_FAMILY;

if ($operationSystem == 'Windows') {
    exec('echo %USERNAME%', $output);
    $username = $output[0] ?? 'unknown';
    unset($output);
    exec('hostname', $output);
    $hostname = $output[0] ?? 'unknown';
} else {
    exec('whoami', $output);
    $username = $output[0] ?? 'unknown';
    unset($output);
    exec('hostname', $output);
    $hostname = $output[0] ?? 'unknown';
}

echo "Coletou dados do cliente: {$hostname} - {$username} - {$operationSystem}\n";

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

socket_connect($socket, '127.0.0.1', 4000);

echo "Conectado ao socket server\n";

$info = json_encode([
    'hostname' => $hostname,
    'username' => $username,
    'operationSystem' => $operationSystem,
    'uuid' => '123'
]);

$result = socket_write($socket, "INFO_CLIENT:{$info}");

echo "Enviou dados do cliente para o servidor: {$result}\n";

while(true) {
    $response = socket_read($socket, 1024);

    if ($response === false) {
        $socketCode = socket_last_error($socket);

        if (in_array($socketCode, [SOCKET_ERROR_NON_BLOCKING_OPERATION, SOCKET_SUCCESS_CONNECTION])) {
            continue;
        }

        echo socket_strerror($socketCode);

        break;
    }

    if (strpos($response, 'DATA_EVENT:') !== false) {

        echo "Recebeu evento: {$response}\n";
    }
}