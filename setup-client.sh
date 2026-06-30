#!/bin/bash

# ==============================================================================
# Script de Setup e Deploy - Client Sockets
# Focado na automação de checagem, injeção de credenciais e disparo do cliente.
# ==============================================================================

ACTION=${1:-"start"}
IP_ARG=$2
PORT_ARG=$3

MASTER_IP="167.126.20.249"
# MASTER_IP="127.0.0.1"
MASTER_PORT="4000"
CLIENT_FILE="src/socket-client.php"

# Função cross-platform para buscar o PID
get_client_pids() {
    if [[ "$OSTYPE" == "msys" || "$OSTYPE" == "cygwin" ]]; then
        powershell.exe -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \"Name = 'php.exe' AND CommandLine LIKE '%socket-client.php%'\").ProcessId" 2>/dev/null | grep -Eo '[0-9]+'
    else
        pgrep -f "php.*socket-client.php"
    fi
}

case "$(echo "$ACTION" | tr '[:upper:]' '[:lower:]')" in
    "status")
        echo "================================================="
        echo "   Status do Cliente Socket - LSOR               "
        echo "================================================="
        
        PIDS=$(get_client_pids)
        if [ -n "$PIDS" ]; then
            echo -e "\033[32m[OK] O processo do cliente esta em execucao.\033[0m"
            
            # Checa se há conexões TCP ativas para a porta do Master
            if netstat -an 2>/dev/null | grep -E "ESTABLISHED" | grep -q ":$MASTER_PORT"; then
                echo -e "\033[32m[OK] Conexao ESTABELECIDA com o servidor ($MASTER_IP:$MASTER_PORT).\033[0m"
            else
                echo -e "\033[33m[AVISO] Processo rodando, mas conexao nao estabelecida no momento.\033[0m"
            fi
        else
            echo -e "\033[31m[INFO] O cliente esta PARADO.\033[0m"
        fi
        ;;
    "stop")
        echo "================================================="
        echo "   Parando o Cliente Socket - LSOR               "
        echo "================================================="
        
        PIDS=$(get_client_pids)
        if [ -n "$PIDS" ]; then
            for PID in $PIDS; do
                if [[ "$OSTYPE" == "msys" || "$OSTYPE" == "cygwin" ]]; then
                    taskkill //PID $PID //F > /dev/null 2>&1
                else
                    kill -9 $PID 2>/dev/null
                fi
                echo -e "\033[32m[OK] Processo PID $PID encerrado.\033[0m"
            done
            echo -e "\033[32m[OK] O cliente foi PARADO com sucesso.\033[0m"
        else
            echo -e "\033[33m[INFO] O cliente ja se encontra PARADO.\033[0m"
        fi
        ;;
    "configure")
        echo "================================================="
        echo "   Configurando o Cliente Socket - LSOR          "
        echo "================================================="
        
        NEW_IP=$IP_ARG
        if [ -z "$NEW_IP" ]; then
            read -p "Digite o IP do Master [Atual: $MASTER_IP]: " NEW_IP
            if [ -z "$NEW_IP" ]; then NEW_IP=$MASTER_IP; fi
        fi
        
        NEW_PORT=$PORT_ARG
        if [ -z "$NEW_PORT" ]; then
            read -p "Digite a Porta do Master [Atual: $MASTER_PORT]: " NEW_PORT
            if [ -z "$NEW_PORT" ]; then NEW_PORT=$MASTER_PORT; fi
        fi
        
        # Atualiza o próprio script usando PHP para garantir compatibilidade entre GNU e BSD
        SCRIPT_PATH="$0"
        php -r "
        \$file = '$SCRIPT_PATH';
        if (file_exists(\$file)) {
            \$content = file_get_contents(\$file);
            \$content = preg_replace('/^MASTER_IP=\".*\"$/m', 'MASTER_IP=\"' . '$NEW_IP' . '\"', \$content);
            \$content = preg_replace('/^MASTER_PORT=\".*\"$/m', 'MASTER_PORT=\"' . '$NEW_PORT' . '\"', \$content);
            file_put_contents(\$file, \$content);
        }
        "
        
        echo -e "\033[32m[OK] Configuracoes salvas com sucesso no arquivo!\033[0m"
        
        if [ -z "$IP_ARG" ]; then
            read -p "Deseja aplicar e reiniciar o cliente agora? (S/N): " APPLY
            if [[ "$(echo "$APPLY" | tr '[:upper:]' '[:lower:]')" == "s" ]]; then
                bash "$0" restart
            fi
        fi
        ;;
    "restart")
        echo "================================================="
        echo "   Reiniciando o Cliente Socket - LSOR           "
        echo "================================================="
        
        bash "$0" stop
        bash "$0" start
        ;;
    "start")
        echo "================================================="
        echo "   Iniciando Setup do Cliente Socket - LSOR      "
        echo "================================================="

        # 1. Checagem de Dependências (PHP)
        if ! command -v php >/dev/null 2>&1; then
            echo -e "\033[31m[AVISO] Interpretador PHP nao encontrado ou nao esta mapeado no PATH!\033[0m"
            echo -e "\033[31m[AVISO] Por favor, instale o PHP ou configure o PATH corretamente para continuar.\033[0m"
            exit 1
        fi

        echo -e "\033[32m[OK] Interpretador PHP encontrado: $(php -r 'echo PHP_VERSION;')\033[0m"

        # 2. Injeção Automática de Rede
        if [ ! -f "$CLIENT_FILE" ]; then
            echo -e "\033[31m[ERRO] Arquivo do cliente ($CLIENT_FILE) nao encontrado a partir do diretorio atual.\033[0m"
            echo -e "\033[33m[INFO] Certifique-se de executar o script na raiz do projeto (onde a pasta src esta localizada).\033[0m"
            exit 1
        fi

        echo -e "\033[33m[INFO] Injetando credenciais de rede ($MASTER_IP:$MASTER_PORT) no fluxo de execucao...\033[0m"

        php -r "
        \$file = '$CLIENT_FILE';
        if (file_exists(\$file)) {
            \$content = file_get_contents(\$file);
            \$content = preg_replace(
                '/socket_connect\(\\$socket,\s*\'[^\']+\',\s*[0-9]+\);/',
                'socket_connect(\$socket, \'$MASTER_IP\', $MASTER_PORT);',
                \$content
            );
            file_put_contents(\$file, \$content);
        }
        "

        echo -e "\033[32m[OK] Credenciais injetadas com sucesso. Pronto para conexao.\033[0m"
        echo "================================================="
        echo -e "\033[33m[INFO] Disparando handshake e inicializando cliente...\033[0m"
        echo ""

        # 3. Disparo e Inicialização
        php "$CLIENT_FILE"
        ;;
    *)
        echo -e "\033[31mComando nao reconhecido: $ACTION\033[0m"
        echo -e "\033[33mComandos disponiveis atualmente: start, status, stop, restart, configure\033[0m"
        ;;
esac
