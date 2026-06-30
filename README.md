# Socket LSOR

Servidor e cliente em PHP que se comunicam via sockets TCP. O servidor mantém clientes conectados e expõe uma API HTTP simples para listar clientes e enviar comandos remotos a um cliente específico.

## Requisitos

- PHP 7.4 ou superior
- Extensão `sockets` habilitada no PHP
- Composer (para instalar dependências)

Para verificar se a extensão está disponível:

```bash
php -m | grep sockets
```

Instale as dependências do projeto na raiz:

```bash
composer install
```

## Como rodar o Socket Server

Na raiz do projeto, execute:

```bash
php src/socket-server.php
```

O servidor sobe em `127.0.0.1:4000` e exibe a mensagem:

```
Server is running on port 4000
```

Mantenha esse terminal aberto enquanto o servidor estiver em uso.

## Como rodar o Socket Client

Com o servidor já em execução, abra outro terminal e execute:

```bash
php src/socket-client.php
```

O cliente irá:

1. Coletar informações da máquina (`hostname`, `username`, sistema operacional)
2. Gerar um UUID v4 (RFC 4122) com a biblioteca `ramsey/uuid`
3. Conectar ao servidor em `127.0.0.1:4000`
4. Enviar os dados de identificação com o prefixo `INFO_CLIENT:`
5. Aguardar eventos enviados pelo servidor

O UUID é gerado automaticamente a cada execução do cliente:

```php
'uuid' => (new UuidFactory())->uuid4()
```

Esse identificador é usado como `channel` nos endpoints da API. Para descobrir o UUID de um cliente conectado, consulte `GET /api/v1/clients`.

## Endpoints disponíveis

A API usa HTTP sobre conexão TCP bruta (não é um servidor web tradicional). As requisições devem ser enviadas diretamente ao socket na porta `4000`.

### `GET /api/v1/clients`

Lista os clientes conectados que já enviaram suas informações (`INFO_CLIENT`).

**Exemplo com `curl`:**

```bash
curl http://127.0.0.1:4000/api/v1/clients
```

**Resposta de sucesso (200):**

```json
[
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "ip": "127.0.0.1",
    "port": 54321,
    "hostname": "meu-pc",
    "username": "lucas",
    "operationSystem": "Windows"
  }
]
```

---

### `POST /api/v1/events`

Envia um comando para ser executado em um cliente conectado. O campo `channel` deve corresponder ao `uuid` do cliente.

**Corpo da requisição:**

```json
{
  "event": "whoami",
  "channel": "550e8400-e29b-41d4-a716-446655440000"
}
```

- `event` (obrigatório): comando de shell a ser executado no cliente
- `channel` (obrigatório): UUID v4 do cliente de destino (obtido em `GET /api/v1/clients`)

**Exemplo com `curl`:**

```bash
curl -X POST http://127.0.0.1:4000/api/v1/events \
  -H "Content-Type: application/json" \
  -d '{"event":"whoami","channel":"550e8400-e29b-41d4-a716-446655440000"}'
```

**Resposta de sucesso (200):**

```json
{
  "output": "lucas\n"
}
```

**Possíveis erros:**

| Status | Mensagem | Causa |
|--------|----------|-------|
| 400 | `Invalid request body.` | Corpo da requisição inválido ou ausente |
| 422 | `event field is required.` | Campo `event` não informado |
| 422 | `channel field is required.` | Campo `channel` não informado |
| 404 | `Client not found.` | Nenhum cliente conectado com o UUID informado |

## Fluxo de comunicação

```
┌─────────────┐         INFO_CLIENT          ┌──────────────┐
│ Socket      │ ───────────────────────────► │ Socket       │
│ Client      │                              │ Server       │
│             │ ◄─────────────────────────── │              │
│             │         DATA_EVENT           │              │
└─────────────┘                              └──────────────┘
                                                    ^
                              POST /api/v1/events   │
                              (channel = uuid)      │
                                                    │
                                             ┌──────────────┐
                                             │ API HTTP     │
                                             │ (via curl)   │
                                             └──────────────┘
```

1. O **cliente** conecta ao servidor e envia `INFO_CLIENT:{json}` com `uuid`, `hostname`, `username` e `operationSystem`.
2. Uma requisição **HTTP** chega ao servidor com `POST /api/v1/events`, indicando o comando (`event`) e o cliente alvo (`channel`).
3. O servidor encaminha `DATA_EVENT:{json}` ao cliente correspondente.
4. O cliente executa o comando com `shell_exec` e devolve o resultado ao servidor.
5. O servidor responde à requisição HTTP com o output do comando.

## Estrutura do projeto

```
socket-broadcast/
├── src/
│   ├── socket-server.php      # Servidor principal
│   ├── socket-client.php      # Cliente que se conecta ao servidor
│   ├── socket-constants.php   # Constantes de erro do socket
│   ├── error-handler.php      # Tratamento de erros
│   └── register-shutdown-function.php
├── composer.json
└── README.md
```

## Observações

- O servidor escuta apenas em `127.0.0.1` (localhost). Para aceitar conexões externas, altere o endereço em `socket-server.php`.
- O campo `event` é executado diretamente no shell do cliente. Use apenas em ambientes controlados e confiáveis.
- É necessário ter pelo menos um cliente conectado antes de chamar `POST /api/v1/events`, caso contrário a API retorna `404 Client not found.`
