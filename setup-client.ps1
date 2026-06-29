<#
Caso o erro 'a execução de scripts está desabilitada no sistema' ocorra, 
basta permitir no terminal de forma manual com o comando 
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
#>

<#
.SYNOPSIS
    Script de Setup e Deploy - Client Sockets (Versão PowerShell)
    Focado na automação de checagem, injeção de credenciais e disparo do cliente em ambientes Windows.
#>

param (
    [Parameter(Position=0)]
    [string]$Action = "start",

    [Parameter(Position=1)]
    [string]$IpArg = "",

    [Parameter(Position=2)]
    [string]$PortArg = ""
)

$ErrorActionPreference = "Stop"

$MasterIp = "167.126.20.249"
# $MasterIp = "127.0.0.1"
$MasterPort = "4000"
$ClientFile = "src\socket-client.php"

switch ($Action.ToLower()) {
    "status" {
        Write-Host "=================================================" -ForegroundColor Cyan
        Write-Host "   Status do Cliente Socket - LSOR               " -ForegroundColor Cyan
        Write-Host "=================================================" -ForegroundColor Cyan
        
        $processes = Get-CimInstance Win32_Process -Filter "Name = 'php.exe' AND CommandLine LIKE '%socket-client.php%'"
        if ($processes) {
            Write-Host "[OK] O processo do cliente esta em execucao." -ForegroundColor Green
            
            $connected = $false
            foreach ($p in $processes) {
                $conns = Get-NetTCPConnection -OwningProcess $p.ProcessId -ErrorAction SilentlyContinue | Where-Object State -eq 'Established'
                if ($conns) {
                    $connected = $true
                    break
                }
            }
            
            if ($connected) {
                Write-Host "[OK] Conexao ESTABELECIDA com o servidor (${MasterIp}:${MasterPort})." -ForegroundColor Green
            } else {
                Write-Host "[AVISO] Processo rodando, mas conexao nao estabelecida no momento." -ForegroundColor Yellow
            }
        } else {
            Write-Host "[INFO] O cliente esta PARADO." -ForegroundColor Red
        }
    }
    "stop" {
        Write-Host "=================================================" -ForegroundColor Cyan
        Write-Host "   Parando o Cliente Socket - LSOR               " -ForegroundColor Cyan
        Write-Host "=================================================" -ForegroundColor Cyan
        
        $processes = Get-CimInstance Win32_Process -Filter "Name = 'php.exe' AND CommandLine LIKE '%socket-client.php%'"
        if ($processes) {
            foreach ($p in $processes) {
                Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
                Write-Host "[OK] Processo PID $($p.ProcessId) encerrado." -ForegroundColor Green
            }
            Write-Host "[OK] O cliente foi PARADO com sucesso." -ForegroundColor Green
        } else {
            Write-Host "[INFO] O cliente ja se encontra PARADO." -ForegroundColor Yellow
        }
    }
    "configure" {
        Write-Host "=================================================" -ForegroundColor Cyan
        Write-Host "   Configurando o Cliente Socket - LSOR          " -ForegroundColor Cyan
        Write-Host "=================================================" -ForegroundColor Cyan
        
        $newIp = $IpArg
        if ([string]::IsNullOrWhiteSpace($newIp)) {
            $newIp = Read-Host "Digite o IP do Master [Atual: $MasterIp]"
            if ([string]::IsNullOrWhiteSpace($newIp)) { $newIp = $MasterIp }
        }
        
        $newPort = $PortArg
        if ([string]::IsNullOrWhiteSpace($newPort)) {
            $newPort = Read-Host "Digite a Porta do Master [Atual: $MasterPort]"
            if ([string]::IsNullOrWhiteSpace($newPort)) { $newPort = $MasterPort }
        }
        
        # Atualiza o próprio arquivo setup-client.ps1 com os novos defaults usando Regex
        $scriptContent = Get-Content $PSCommandPath -Raw -Encoding UTF8
        $scriptContent = [System.Text.RegularExpressions.Regex]::Replace($scriptContent, '(?m)^\$MasterIp\s*=\s*".*"', "`$MasterIp = `"$newIp`"")
        $scriptContent = [System.Text.RegularExpressions.Regex]::Replace($scriptContent, '(?m)^\$MasterPort\s*=\s*".*"', "`$MasterPort = `"$newPort`"")
        
        [System.IO.File]::WriteAllText($PSCommandPath, $scriptContent, [System.Text.Encoding]::UTF8)
        
        Write-Host "[OK] Configuracoes salvas com sucesso no arquivo!" -ForegroundColor Green
        
        # Evitar loop de perguntas se o script for chamado por automacao (com argumentos)
        if ([string]::IsNullOrWhiteSpace($IpArg)) {
            $apply = Read-Host "Deseja aplicar e reiniciar o cliente agora? (S/N)"
            if ($apply.Trim().ToLower() -eq 's') {
                & $PSCommandPath restart
            }
        }
    }
    "restart" {
        Write-Host "=================================================" -ForegroundColor Cyan
        Write-Host "   Reiniciando o Cliente Socket - LSOR           " -ForegroundColor Cyan
        Write-Host "=================================================" -ForegroundColor Cyan
        
        & $PSCommandPath stop
        & $PSCommandPath start
    }
    "start" {
        Write-Host "=================================================" -ForegroundColor Cyan
        Write-Host "   Iniciando Setup do Cliente Socket - LSOR      " -ForegroundColor Cyan
        Write-Host "=================================================" -ForegroundColor Cyan

# 1. Checagem de Dependências (PHP)
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    Write-Host "[AVISO] Interpretador PHP não encontrado ou não está mapeado no PATH!" -ForegroundColor Red
    Write-Host "[AVISO] Por favor, instale o PHP e configure o PATH corretamente nas Variáveis de Ambiente para continuar." -ForegroundColor Red
    exit 1
}

$phpVersion = php -r 'echo PHP_VERSION;'
Write-Host "[OK] Interpretador PHP encontrado: $phpVersion" -ForegroundColor Green

# 2. Injeção Automática de Rede
if (-not (Test-Path $ClientFile)) {
    Write-Host "[ERRO] Arquivo do cliente ($ClientFile) não encontrado a partir do diretório atual." -ForegroundColor Red
    Write-Host "[INFO] Certifique-se de executar o script na raiz do projeto (onde a pasta src está localizada)." -ForegroundColor Yellow
    exit 1
}

Write-Host "[INFO] Injetando credenciais de rede (${MasterIp}:${MasterPort}) no fluxo de execucao..." -ForegroundColor Yellow

# Lendo o arquivo preservando o formato bruto (Raw) para não quebrar a codificação ou quebras de linha
$content = Get-Content -Path $ClientFile -Raw -Encoding UTF8

# Regex pattern seguro para buscar o método de conexão socket independentemente do IP já estiver definido
$pattern = 'socket_connect\(\$socket,\s*''[^'']+'',\s*[0-9]+\);'
$replacement = "socket_connect(`$socket, '$MasterIp', $MasterPort);"
$newContent = [System.Text.RegularExpressions.Regex]::Replace($content, $pattern, $replacement)

# Salvando a injeção diretamente no arquivo alvo
[System.IO.File]::WriteAllText("$PWD\$ClientFile", $newContent, [System.Text.Encoding]::UTF8)

Write-Host "[OK] Credenciais injetadas com sucesso. Pronto para conexao." -ForegroundColor Green
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "[INFO] Disparando handshake e inicializando cliente..." -ForegroundColor Yellow
Write-Host ""

# 3. Disparo e Inicialização
# Executa o cliente de forma que ele rode imediatamente e se conecte à VPS
        php $ClientFile
    }
    default {
        Write-Host "Comando nao reconhecido: $Action" -ForegroundColor Red
        Write-Host "Comandos disponiveis atualmente: start, status, stop, restart, configure" -ForegroundColor Yellow
    }
}
