# Spec Patch — `narya-php-sdk` Worker Reliability, Reset, Timeout e Memory Guard

Repo alvo:

```text id="9b0djy"
EreborCodeForge/narya-php-sdk
```

Objetivo: tornar o SDK PHP seguro para **Laravel em worker persistente**, alinhado com o `NaryaRuntimeEngine`, evitando degradação com o tempo por timeout grudado, escrita parcial, buffers grandes, reset incompleto, memória alta e reciclagem tardia.

O SDK hoje declara o worker como orquestrador do loop e reset entre requests. 
O contrato `ApplicationWorker::reset()` já existe e diz explicitamente que deve resetar estado entre requests. 
O `Worker::handleRequest()` já chama reset no `finally`, mas o reset atual é básico: limpa superglobals, remove headers, chama `application->reset()` e roda `gc_collect_cycles()`.  

---

# 1. Criar `WorkerOptions`

## Problema

Hoje o `Worker` recebe só:

```php id="mc68gw"
?ApplicationWorker $application = null,
?callable $handler = null,
int $maxRequests = 10000,
?LifecycleInterface $lifecycle = null
```



Isso é pouco para controlar worker Laravel real.

## Patch

Criar arquivo:

```text id="chhl38"
src/Runtime/WorkerOptions.php
```

Conteúdo esperado:

```php id="2d9wx3"
<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

final readonly class WorkerOptions
{
    public function __construct(
        public int $maxRequests = 10000,
        public int $socketTimeoutSeconds = 30,
        public int $memoryLimitMb = 0,
        public int $gcInterval = 1,
        public bool $enableGcMemCaches = true,
        public bool $resetOutputBuffersAfterRequest = true,
        public bool $clearHeadersAfterRequest = true,
        public int $maxReusableBodyBytes = 262144,
        public int $maxReusablePayloadBytes = 262144,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function shouldCheckMemory(): bool
    {
        return $this->memoryLimitMb > 0;
    }

    public function memoryLimitBytes(): int
    {
        return $this->memoryLimitMb * 1024 * 1024;
    }
}
```

## Critério de aceite

Código antigo continua funcionando:

```php id="5hlkwt"
(new Worker($app))->run();
```

Código novo também:

```php id="6evfx9"
$options = new WorkerOptions(
    maxRequests: 300,
    socketTimeoutSeconds: 30,
    memoryLimitMb: 256,
    gcInterval: 10,
);

(new Worker($app, options: $options))->run();
```

---

# 2. Atualizar assinatura do `Worker` mantendo BC

## Patch

Arquivo:

```text id="9jqz59"
src/Runtime/Worker.php
```

Alterar construtor para:

```php id="008a3k"
public function __construct(
    ?ApplicationWorker $application = null,
    ?callable $handler = null,
    int $maxRequests = 10000,
    ?LifecycleInterface $lifecycle = null,
    ?WorkerOptions $options = null,
) {
    $this->application = $application;
    $this->handler = $handler;
    $this->options = $options ?? new WorkerOptions(maxRequests: $maxRequests);
    $this->maxRequests = $this->options->maxRequests;
    $this->lifecycle = $lifecycle;
}
```

Adicionar propriedade:

```php id="1k6g2z"
private WorkerOptions $options;
```

Atualizar criação do bridge:

```php id="2k5xve"
$this->bridge = new WorkerBridge(
    [$this, 'handleRequest'],
    $args->sockPath,
    $args->maxRequests,
    $this->options
);
```

Mas `WorkerRunArgs` deve poder sobrescrever o `maxRequests` vindo do Runtime Go.

Regra:

```php id="jqi904"
$runtimeOptions = $this->options->withMaxRequests($args->maxRequests);
```

Então adicionar método em `WorkerOptions`:

```php id="674wj7"
public function withMaxRequests(int $maxRequests): self
{
    return new self(
        maxRequests: $maxRequests,
        socketTimeoutSeconds: $this->socketTimeoutSeconds,
        memoryLimitMb: $this->memoryLimitMb,
        gcInterval: $this->gcInterval,
        enableGcMemCaches: $this->enableGcMemCaches,
        resetOutputBuffersAfterRequest: $this->resetOutputBuffersAfterRequest,
        clearHeadersAfterRequest: $this->clearHeadersAfterRequest,
        maxReusableBodyBytes: $this->maxReusableBodyBytes,
        maxReusablePayloadBytes: $this->maxReusablePayloadBytes,
    );
}
```

---

# 3. Expandir `WorkerRunArgs`

## Problema

`WorkerRunArgs` hoje lê `--sock` e `--max-requests`. 

Precisamos preparar flags futuras sem quebrar compatibilidade.

## Patch

Arquivo:

```text id="wkz3oo"
src/Runtime/WorkerRunArgs.php
```

Adicionar propriedades:

```php id="avha3w"
public int $memoryLimitMb,
public int $socketTimeoutSeconds,
```

Parsing:

```php id="in5sns"
} elseif ($argv[$i] === '--memory-limit-mb' && isset($argv[$i + 1])) {
    $memoryLimitMb = max(0, (int) $argv[++$i]);
} elseif (str_starts_with($argv[$i], '--memory-limit-mb=')) {
    $memoryLimitMb = max(0, (int) substr($argv[$i], 18));
} elseif ($argv[$i] === '--socket-timeout' && isset($argv[$i + 1])) {
    $socketTimeoutSeconds = max(1, (int) $argv[++$i]);
} elseif (str_starts_with($argv[$i], '--socket-timeout=')) {
    $socketTimeoutSeconds = max(1, (int) substr($argv[$i], 17));
}
```

Uso esperado futuro:

```bash id="jxfofi"
php worker.php --sock /tmp/narya/worker-000.sock --max-requests 300 --memory-limit-mb 256 --socket-timeout 30
```

---

# 4. Corrigir timeout grudado no `WorkerBridge`

## Problema

Hoje o socket timeout muda se a request vier com `timeout_ms`, mas não volta para o padrão. 

Isso pode causar request posterior herdando timeout anterior.

## Patch

Arquivo:

```text id="u72uy5"
src/Runtime/WorkerBridge.php
```

Adicionar `WorkerOptions` no construtor:

```php id="u2i1rb"
public function __construct(
    callable $handler,
    string $sockPath,
    int $maxRequests = 10000,
    ?WorkerOptions $options = null,
) {
    $this->handler = $handler;
    $this->sockPath = $sockPath;
    $this->maxRequests = $maxRequests;
    $this->options = $options ?? new WorkerOptions(maxRequests: $maxRequests);

    ...
}
```

Adicionar propriedade:

```php id="jxwion"
private WorkerOptions $options;
```

Substituir lógica de timeout por método:

```php id="7fuv9o"
private function applySocketTimeout(array $request): void
{
    $timeoutSec = $this->options->socketTimeoutSeconds;

    if (isset($request['timeout_ms']) && (int) $request['timeout_ms'] > 0) {
        $timeoutSec = max(1, (int) ceil(((int) $request['timeout_ms']) / 1000));
    }

    stream_set_timeout($this->socket, $timeoutSec);
}
```

No loop:

```php id="yjtmp6"
$this->applySocketTimeout($request);
```

---

# 5. Implementar `writeExact` no PHP

## Problema

`writeFrame()` usa um `fwrite()` único:

```php id="qqezfo"
$written = fwrite($this->socket, $header . $payload);
```

e valida se escreveu tudo. 

Em socket/stream, escrita parcial pode acontecer. O correto é escrever em loop.

## Patch

Adicionar:

```php id="fp7zv6"
private function writeExact(string $data): void
{
    $length = strlen($data);
    $written = 0;

    while ($written < $length) {
        $chunk = fwrite($this->socket, substr($data, $written));

        if ($chunk === false) {
            throw new \RuntimeException('Failed to write to socket');
        }

        if ($chunk === 0) {
            $meta = stream_get_meta_data($this->socket);

            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('Socket write timeout');
            }

            throw new \RuntimeException('Socket write returned zero bytes');
        }

        $written += $chunk;
    }

    fflush($this->socket);
}
```

Alterar `writeFrame`:

```php id="hsppln"
private function writeFrame(string $payload): void
{
    $size = strlen($payload);

    if ($size > self::MAX_PAYLOAD_SIZE) {
        throw new \RuntimeException(
            "Payload exceeds limit: {$size} > " . self::MAX_PAYLOAD_SIZE
        );
    }

    $this->writeExact(pack('N', $size) . $payload);
}
```

---

# 6. Melhorar `readExact` com timeout real

## Problema

Hoje `readExact()` trata `''` como EOF limpo/parcial. 

Mas em stream com timeout, `fread()` pode retornar vazio e o metadado `timed_out` informar timeout.

## Patch

Alterar:

```php id="4fojti"
if ($chunk === false || $chunk === '') {
```

para:

```php id="r86hpr"
if ($chunk === false) {
    throw new \RuntimeException("Failed to read from socket");
}

if ($chunk === '') {
    $meta = stream_get_meta_data($this->socket);

    if (!empty($meta['timed_out'])) {
        throw new \RuntimeException("Socket read timeout after reading " . strlen($data) . " bytes");
    }

    if ($data === '') {
        return null;
    }

    throw new \RuntimeException("Unexpected EOF after reading " . strlen($data) . " bytes");
}
```

---

# 7. Adicionar `MemoryGuard`

## Problema

A degradação com Laravel geralmente aparece como crescimento de memória por request. Hoje o bridge só marca recycle quando `requestCount >= maxRequests`. 

Precisamos reciclar também por memória.

## Patch

Criar arquivo:

```text id="96ldui"
src/Runtime/MemoryGuard.php
```

Conteúdo:

```php id="wfjlgr"
<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

final readonly class MemoryGuard
{
    public function __construct(
        private WorkerOptions $options
    ) {
    }

    public function shouldRecycle(): bool
    {
        if (!$this->options->shouldCheckMemory()) {
            return false;
        }

        return memory_get_usage(true) >= $this->options->memoryLimitBytes();
    }

    public function usage(): int
    {
        return memory_get_usage(true);
    }

    public function peak(): int
    {
        return memory_get_peak_usage(true);
    }
}
```

No `WorkerBridge`, adicionar:

```php id="5qj1e9"
private MemoryGuard $memoryGuard;
```

No construtor:

```php id="on6995"
$this->memoryGuard = new MemoryGuard($this->options);
```

Ao montar `_meta`:

```php id="s8nt17"
$shouldRecycle = $this->requestCount >= $this->maxRequests
    || $this->memoryGuard->shouldRecycle();

$response['_meta'] = [
    'req_count' => $this->requestCount,
    'mem_usage' => $this->memoryGuard->usage(),
    'mem_peak' => $this->memoryGuard->peak(),
    'recycle' => $shouldRecycle,
];
```

---

# 8. Criar `WorkerResetter`

## Problema

O `Worker::reset()` está fazendo tudo diretamente. 

Para Laravel, precisamos reset mais previsível, testável e configurável.

## Patch

Criar:

```text id="bufgqd"
src/Runtime/WorkerResetter.php
```

Conteúdo:

```php id="jo83wl"
<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\ApplicationWorker;

final readonly class WorkerResetter
{
    public function __construct(
        private WorkerOptions $options
    ) {
    }

    public function reset(?ApplicationWorker $application = null): void
    {
        $this->resetSuperglobals();

        if ($this->options->clearHeadersAfterRequest && function_exists('header_remove')) {
            header_remove();
        }

        if ($application !== null) {
            $application->reset();
        }

        if ($this->options->resetOutputBuffersAfterRequest) {
            $this->clearOutputBuffers();
        }

        $this->runGc();
    }

    private function resetSuperglobals(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_FILES = [];

        $_SERVER = array_filter(
            $_SERVER,
            static fn ($key): bool => is_string($key) && str_starts_with($key, 'PHP_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private function runGc(): void
    {
        gc_collect_cycles();

        if ($this->options->enableGcMemCaches && function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }
}
```

No `Worker`:

```php id="556e4a"
private WorkerResetter $resetter;
private int $handledRequests = 0;
```

No construtor:

```php id="ev9ort"
$this->resetter = new WorkerResetter($this->options);
```

Substituir método `reset()` por:

```php id="derxay"
private function reset(): void
{
    $this->handledRequests++;

    if ($this->options->gcInterval > 1 && ($this->handledRequests % $this->options->gcInterval) !== 0) {
        $withoutGc = new WorkerOptions(
            maxRequests: $this->options->maxRequests,
            socketTimeoutSeconds: $this->options->socketTimeoutSeconds,
            memoryLimitMb: $this->options->memoryLimitMb,
            gcInterval: $this->options->gcInterval,
            enableGcMemCaches: false,
            resetOutputBuffersAfterRequest: $this->options->resetOutputBuffersAfterRequest,
            clearHeadersAfterRequest: $this->options->clearHeadersAfterRequest,
            maxReusableBodyBytes: $this->options->maxReusableBodyBytes,
            maxReusablePayloadBytes: $this->options->maxReusablePayloadBytes,
        );

        (new WorkerResetter($withoutGc))->reset($this->application);
        return;
    }

    $this->resetter->reset($this->application);
}
```

Preferível: implementar `WorkerOptions::withoutMemoryCacheGc()` para não duplicar construtor.

---

# 9. Criar `RequestGlobalsHydrator`

## Objetivo

Dar base oficial para adapters Laravel/Symfony preencherem `$_SERVER`, `$_GET`, `$_COOKIE`, headers e body de forma consistente.

Hoje `WorkerRequest` recebe `headers`, `body`, `server`, `query`, etc. 

## Patch

Criar:

```text id="j9iblr"
src/Runtime/RequestGlobalsHydrator.php
```

Conteúdo esperado:

```php id="ugf243"
<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\NaryaRequest;

final class RequestGlobalsHydrator
{
    public function hydrate(NaryaRequest $request): void
    {
        $_GET = $request->getQueryParams();
        $_POST = [];
        $_REQUEST = $_GET;
        $_COOKIE = [];
        $_FILES = [];

        $raw = $request->getRaw();

        $_SERVER = array_merge(
            $_SERVER,
            isset($raw['server']) && is_array($raw['server']) ? $raw['server'] : []
        );

        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_URI'] = $request->getUri();
        $_SERVER['QUERY_STRING'] = $request->getQuery();
        $_SERVER['REMOTE_ADDR'] = $request->getRemoteAddr();
        $_SERVER['HTTP_HOST'] = $request->getHost();
        $_SERVER['REQUEST_SCHEME'] = $request->getScheme();

        foreach ($request->getHeaders() as $name => $values) {
            $serverKey = $this->headerToServerKey((string) $name);
            $_SERVER[$serverKey] = is_array($values) ? implode(',', $values) : (string) $values;
        }
    }

    private function headerToServerKey(string $name): string
    {
        $key = strtoupper(str_replace('-', '_', $name));

        if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            return $key;
        }

        return 'HTTP_' . $key;
    }
}
```

Uso no adapter Laravel:

```php id="relcy5"
(new RequestGlobalsHydrator())->hydrate($naryaRequest);
```

---

# 10. Otimizar `WorkerRequest::fromArray` para body em array de bytes

## Problema

Hoje, se o body vier como array, ele concatena byte a byte:

```php id="ui22f0"
$body = '';
foreach ($data['body'] as $byte) {
    $body .= chr($byte);
}
```



Isso pode ser caro para payload grande.

## Patch

Substituir por helper:

```php id="al49io"
private static function normalizeBody(mixed $body): string
{
    if (is_string($body)) {
        return $body;
    }

    if (!is_array($body)) {
        return (string) $body;
    }

    if ($body === []) {
        return '';
    }

    $chunks = [];
    $chunk = '';

    foreach ($body as $i => $byte) {
        $chunk .= chr((int) $byte);

        if (($i % 8192) === 0 && $chunk !== '') {
            $chunks[] = $chunk;
            $chunk = '';
        }
    }

    if ($chunk !== '') {
        $chunks[] = $chunk;
    }

    return implode('', $chunks);
}
```

Usar:

```php id="3aftim"
$body = self::normalizeBody($data['body'] ?? '');
```

---

# 11. Adicionar lifecycle por request

## Problema

O `LifecycleManager` hoje tem apenas `boot()` e `shutdown()`. 

Para Laravel real, é útil ter hooks:

```text id="zpfb78"
beforeRequest
afterRequest
onException
```

## Patch

Criar contrato opcional:

```text id="ckuxbh"
src/Contracts/RequestLifecycleInterface.php
```

```php id="uwiyhq"
<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

interface RequestLifecycleInterface extends LifecycleInterface
{
    public function beforeRequest(NaryaRequest $request): void;

    public function afterRequest(NaryaRequest $request, array|NaryaResponse|null $response, ?\Throwable $error): void;
}
```

Atualizar `LifecycleManager`:

```php id="f2s40g"
private array $beforeRequestCallbacks = [];
private array $afterRequestCallbacks = [];

public function beforeRequest(NaryaRequest $request): void
{
    foreach ($this->beforeRequestCallbacks as $cb) {
        $cb($request);
    }
}

public function afterRequest(NaryaRequest $request, array|NaryaResponse|null $response, ?\Throwable $error): void
{
    foreach ($this->afterRequestCallbacks as $cb) {
        $cb($request, $response, $error);
    }
}

public function onBeforeRequest(callable $callback): self
{
    $this->beforeRequestCallbacks[] = $callback;
    return $this;
}

public function onAfterRequest(callable $callback): self
{
    $this->afterRequestCallbacks[] = $callback;
    return $this;
}
```

No `Worker::handleRequest()`:

```php id="whinax"
$naryaRequest = WorkerRequest::fromArray($request);
$response = null;
$error = null;

try {
    if ($this->lifecycle instanceof RequestLifecycleInterface) {
        $this->lifecycle->beforeRequest($naryaRequest);
    }

    if ($this->application !== null) {
        $response = $this->application->handle($naryaRequest);
        return $response instanceof NaryaResponse ? $response->toArray() : $response;
    }

    ...
} catch (\Throwable $e) {
    $error = $e;
    throw $e;
} finally {
    if ($this->lifecycle instanceof RequestLifecycleInterface) {
        $this->lifecycle->afterRequest($naryaRequest, $response, $error);
    }

    $this->reset();
}
```

---

# 12. Response meta opcional no `WorkerResponse`

## Problema

`WorkerResponse::toArray()` retorna só status, headers, body e error. 

O bridge injeta `_meta`, mas para alguns casos avançados pode ser útil a aplicação pedir recycle diretamente.

## Patch

Atualizar `WorkerResponse`:

```php id="91h81v"
public function __construct(
    private int $status = 200,
    private array $headers = [],
    private string $body = '',
    private string $error = '',
    private array $meta = [],
) {
}
```

Atualizar factory:

```php id="my4ljr"
public static function create(
    int $status = 200,
    array $headers = [],
    string $body = '',
    string $error = '',
    array $meta = []
): self {
    return new self($status, $headers, $body, $error, $meta);
}
```

Adicionar:

```php id="jnrh23"
public static function recycle(int $status = 200, array $headers = [], string $body = '', string $error = ''): self
{
    return new self($status, $headers, $body, $error, ['recycle' => true]);
}
```

Atualizar `toArray()`:

```php id="trg4xy"
$data = [
    'status' => $this->status,
    'headers' => $this->headers,
    'body' => $this->body,
    'error' => $this->error,
];

if ($this->meta !== []) {
    $data['_meta'] = $this->meta;
}

return $data;
```

No `WorkerBridge::processRequest()`, ao montar response final, preservar `_meta` vindo do handler e mesclar com meta do bridge:

```php id="dqtfae"
$appMeta = is_array($response['_meta'] ?? null) ? $response['_meta'] : [];

$response['_meta'] = array_merge($appMeta, [
    'req_count' => $this->requestCount,
    'mem_usage' => $this->memoryGuard->usage(),
    'mem_peak' => $this->memoryGuard->peak(),
    'recycle' => ($appMeta['recycle'] ?? false) || $shouldRecycle,
]);
```

---

# 13. Atualizar README com Laravel worker recomendado

O README já orienta que `reset()` deve limpar estado request-scoped. 

Adicionar seção:

````md id="sna1ad"
## Laravel Worker Safety

For Laravel, use low max_requests first:

```php
$options = new WorkerOptions(
    maxRequests: (int) getenv('NARYA_MAX_REQUESTS') ?: 300,
    memoryLimitMb: (int) getenv('NARYA_MEMORY_LIMIT_MB') ?: 256,
    socketTimeoutSeconds: 30,
    gcInterval: 10,
);

$laravelWorker = new LaravelNaryaWorker($kernel, enableTerminate: true);

(new Worker(
    application: $laravelWorker,
    options: $options,
))->run();
````

Required Laravel reset checklist:

* forget current request instance;
* clear scoped instances;
* clear resolved Facades;
* optionally call Kernel::terminate;
* clear output buffers;
* run GC periodically.

````

---

# 14. Testes obrigatórios

Criar testes em:

```text id="1lyh6q"
tests/Unit
tests/Integration
````

## 14.1 `WorkerRunArgsTest`

Arquivo:

```text id="b7jwil"
tests/Unit/WorkerRunArgsTest.php
```

Casos:

```php id="fvk879"
public function testParsesSockAndMaxRequests(): void
```

Input:

```php id="bb2iim"
['worker.php', '--sock', '/tmp/a.sock', '--max-requests', '300']
```

Assert:

```php id="uej0qv"
$sockPath === '/tmp/a.sock'
$maxRequests === 300
```

Também testar formato:

```php id="x09agc"
--sock=/tmp/a.sock
--max-requests=300
```

## 14.2 `WorkerResetterTest`

Arquivo:

```text id="6i9fs7"
tests/Unit/WorkerResetterTest.php
```

Casos obrigatórios:

1. limpa `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`;
2. mantém apenas chaves `PHP_*` em `$_SERVER`;
3. chama `ApplicationWorker::reset()`;
4. limpa output buffers;
5. chama `gc_mem_caches()` quando disponível.

Teste principal:

```php id="oc1vub"
public function testResetClearsRequestStateAndCallsApplicationReset(): void
```

## 14.3 `WorkerBridgeTimeoutTest`

Deve provar que timeout não fica grudado.

Cenário:

1. primeira request com `timeout_ms = 1000`;
2. segunda request sem timeout;
3. assert que `stream_set_timeout` voltou ao default de `WorkerOptions`.

Como `stream_get_meta_data()` em socket real é difícil de inspecionar após loop, criar método protegido/testável ou extrair para:

```text id="7dhpj0"
src/Runtime/SocketTimeoutResolver.php
```

Teste:

```php id="l6wkdp"
public function testTimeoutFallsBackToDefaultWhenRequestDoesNotDefineTimeout(): void
```

## 14.4 `FrameCodecTest`

Recomendado extrair leitura/escrita de frame para classe testável:

```text id="grl1nu"
src/Protocol/FrameCodec.php
```

Com métodos:

```php id="x79diw"
writeFrame($stream, string $payload): void
readFrame($stream): ?string
```

Testes:

```php id="lnlqy8"
public function testWriteFrameHandlesPartialWrites(): void
public function testReadFrameReturnsNullOnCleanEof(): void
public function testReadFrameThrowsOnEmptyPayload(): void
public function testReadFrameThrowsWhenPayloadExceedsLimit(): void
```

Para partial write, criar stream fake ou wrapper customizado.

## 14.5 `MemoryGuardTest`

Arquivo:

```text id="38dnm4"
tests/Unit/MemoryGuardTest.php
```

Casos:

```php id="ds71r6"
public function testDoesNotRecycleWhenMemoryLimitIsDisabled(): void
public function testRecycleWhenMemoryLimitIsReached(): void
```

Para o segundo, usar `memoryLimitMb: 1` e alocar string grande.

## 14.6 `WorkerRequestTest`

Testar `body` em array de bytes.

```php id="2y2bse"
public function testBodyArrayOfBytesIsConvertedToString(): void
```

Input:

```php id="sltu8b"
'body' => [72, 101, 108, 108, 111]
```

Assert:

```php id="33rowf"
$request->getBody() === 'Hello'
```

Também criar teste com payload maior para garantir que não explode tempo/memória.

## 14.7 `RequestGlobalsHydratorTest`

Casos:

1. preenche `$_GET`;
2. preenche `$_SERVER['REQUEST_METHOD']`;
3. converte header `Content-Type` para `CONTENT_TYPE`;
4. converte `X-Request-Id` para `HTTP_X_REQUEST_ID`;
5. preserva `server` vindo do runtime.

---

# 15. Testes de integração obrigatórios

## 15.1 Worker recicla por `maxRequests`

Criar fixture:

```text id="dlmpkb"
tests/fixtures/worker_recycle.php
```

O teste deve simular bridge com maxRequests baixo.

Critério:

1. `maxRequests = 3`;
2. enviar 3 requests;
3. `_meta.recycle === true` na terceira.

## 15.2 Worker recicla por memória

Fixture handler:

```php id="bxjwhh"
$leak[] = str_repeat('A', 1024 * 1024 * 10);
```

Com:

```php id="msxqoz"
memoryLimitMb: 20
```

Critério:

```php id="szbzju"
_meta.recycle === true
```

## 15.3 Reset é chamado após exception

Handler lança exception.

Critério:

1. response 500;
2. `ApplicationWorker::reset()` chamado;
3. output buffer limpo;
4. superglobals limpos.

---

# 16. Comandos obrigatórios de comprovação

O agente deve rodar e anexar no PR:

```bash id="yjzlzc"
composer install
vendor/bin/phpunit
```

Se tiver extension msgpack no CI:

```bash id="ab2ksc"
php -m | grep msgpack
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
```

Rodar teste de stress manual:

```bash id="u3nngl"
for i in $(seq 1 1000); do
  php tests/stress/worker_memory_probe.php
done
```

Criar script:

```text id="l3hj0s"
tests/stress/worker_memory_probe.php
```

Ele deve executar várias requests fake no `WorkerBridge`/handler e imprimir:

```text id="7nz23m"
req_count
memory_get_usage(true)
memory_get_peak_usage(true)
recycle
```

Critério:

* `mem_usage` não cresce indefinidamente em rota normal;
* `recycle` aciona ao passar limite de requests;
* `recycle` aciona ao passar limite de memória;
* reset roda mesmo com exception.

---

# 17. Critérios finais de aceite

O PR só deve ser aceito se provar:

1. `WorkerOptions` controla max requests, timeout, memória e GC.
2. `--max-requests` vindo do Runtime Go continua funcionando.
3. Timeout do socket não fica herdado entre requests.
4. Escrita parcial no socket é tratada corretamente.
5. Leitura diferencia EOF limpo, EOF parcial e timeout.
6. `ApplicationWorker::reset()` é chamado após sucesso e após exception.
7. Output buffers são limpos após request.
8. `gc_mem_caches()` é usado quando disponível.
9. `_meta.recycle` é acionado por request count e por memory limit.
10. `WorkerResponse::recycle()` permite reciclagem cooperativa pela aplicação.
11. Body em array de bytes é convertido sem concatenação byte a byte ingênua.
12. Testes unitários e integração passam.

---

Para diagnóstico inicial:

```env id="pxvu9a"
NARYA_MAX_REQUESTS=100
NARYA_MEMORY_LIMIT_MB=256
NARYA_GC_INTERVAL=1
NARYA_SOCKET_TIMEOUT=30
```

Depois de estabilizar:

```env id="oqxo49"
NARYA_MAX_REQUESTS=300
NARYA_MEMORY_LIMIT_MB=512
NARYA_GC_INTERVAL=10
NARYA_SOCKET_TIMEOUT=30
```

Esse patch deixa o SDK preparado para Laravel persistente com reciclagem previsível, menos retenção de memória e melhor integração com o Runtime Go.
