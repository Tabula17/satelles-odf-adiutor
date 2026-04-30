# XVII: 🛰️ satelles-odf-adiutor

![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-blue)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
![Swoole](https://img.shields.io/badge/Swoole-Asynchronous-2ea44f)
![Unoserver](https://img.shields.io/badge/Unoserver-XML--RPC-6f42c1)
![Redis](https://img.shields.io/badge/Redis-optional-red)

**satelles-odf-adiutor** es una biblioteca PHP asincrónica basada en Swoole para convertir documentos ODF mediante Unoserver.

Está pensada para integrarse con flujos de generación de reportes, documentos, tickets y plantillas ODF, permitiendo convertir archivos a PDF u otros formatos de forma eficiente, escalable y distribuida.

Además de la conversión directa contra Unoserver, el proyecto incluye balanceo entre múltiples instancias, monitoreo de salud, cliente XML-RPC, servidor TCP, cliente TCP, cola Redis opcional, workers, almacenamiento de estado y resultados de jobs.

## Contenido

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Configuración de Unoserver](#configuración-de-unoserver)
- [Uso directo con UnoserverLoadBalancer](#uso-directo-con-unoserverloadbalancer)
- [Servidor TCP de conversión](#servidor-tcp-de-conversión)
- [Cliente TCP](#cliente-tcp)
- [Cola Redis y workers](#cola-redis-y-workers)
- [API principal](#api-principal)
- [Modos de conversión](#modos-de-conversión)
- [Ejemplos incluidos](#ejemplos-incluidos)
- [Estructura interna](#estructura-interna)
- [Recomendaciones](#recomendaciones)
- [Licencia](#licencia)

## Características

- Conversión de documentos mediante Unoserver.
- Comunicación XML-RPC con instancias de Unoserver.
- Procesamiento asincrónico con Swoole.
- Balanceo de carga entre múltiples servidores.
- Monitoreo de salud con recuperación automática.
- Reintentos configurables.
- Métricas básicas por servidor.
- Soporte para modo `stream` y modo `file`.
- Cliente y servidor TCP para conversiones remotas.
- Cola Redis opcional para trabajos diferidos.
- Consulta de estado, cancelación y descarga de resultados.
- Tipado mediante objetos de resultado y excepciones propias.

## Requisitos

- PHP `>=8.4`
- Extensión `swoole`
- Extensión `fileinfo`
- Extensión `dom`
- Extensión `sockets`
- Extensión `libxml`
- Unoserver
- LibreOffice
- Composer

Opcionalmente:

- Extensión `redis`, para usar la cola Redis.
- Redis Server, para jobs persistentes.

## Instalación

```bash
composer require xvii/satelles-odf-adiutor
```

El paquete usa autoload PSR-4 bajo el namespace:

```php
Tabula17\Satelles\Odf\Adiutor\
```

## Configuración de Unoserver

Para usar la librería debes tener una o más instancias de Unoserver ejecutándose.

```bash
unoserver --port 2003 &
unoserver --port 2004 &
```

También puedes iniciar más instancias para distribuir mejor la carga:

```bash
unoserver --port 2003 &
unoserver --port 2004 &
unoserver --port 2005 &
unoserver --port 2006 &
```

## Uso directo con UnoserverLoadBalancer

Este modo permite convertir documentos directamente desde PHP usando el balanceador de Unoserver.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$servers = new ConnectionCollection(
    new ConnectionConfig([
        'name' => 'unoserver-2003',
        'host' => '127.0.0.1',
        'port' => 2003,
    ]),
    new ConnectionConfig([
        'name' => 'unoserver-2004',
        'host' => '127.0.0.1',
        'port' => 2004,
    ])
);

$healthMonitor = new ServerHealthMonitor(
    servers: $servers,
    checkInterval: 30,
    failureThreshold: 3,
    retryTimeout: 60
);

$converter = new UnoserverLoadBalancer(
    healthMonitor: $healthMonitor,
    servers: $servers,
    concurrency: 20,
    timeout: 15
);

Coroutine\run(function () use ($converter): void {
    $converter->start();

    $result = $converter->convertAsync(
        filePath: __DIR__ . '/documento.odt',
        outputFormat: 'pdf',
        mode: 'stream'
    );

    if ($result->isStream() && $result->hasBase64Content()) {
        file_put_contents(
            __DIR__ . '/salida.pdf',
            base64_decode($result->base64Content)
        );
    }

    $converter->stop();
});
```

## Servidor TCP de conversión

El proyecto incluye un servidor TCP basado en Swoole mediante la clase:

```php
Tabula17\Satelles\Odf\Adiutor\Server\AdiutorTcp
```

Este servidor permite recibir archivos, convertirlos y devolver resultados a clientes remotos.

Acciones soportadas:

| Acción | Descripción |
|---|---|
| `convert` | Convierte un archivo de forma directa |
| `submit` | Envía un trabajo a la cola |
| `status` | Consulta el estado de un job |
| `cancel` | Cancela un job |
| `wait` | Espera un job y devuelve el archivo resultante |
| `getFile` | Descarga el archivo de un job completado |

Ejemplo disponible en:

```bash
examples/server.php
```

Ejecución:

```bash
php examples/server.php
```

Por defecto, el ejemplo levanta un servidor TCP en el puerto `9508`.

## Cliente TCP

El cliente TCP está disponible mediante:

```php
Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp
```

Permite conectarse al servidor `AdiutorTcp` y ejecutar conversiones remotas.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$config = new TCPServerConfig([
    'host' => '127.0.0.1',
    'port' => 9508,
]);

$client = new AdiutorClientTcp($config);

Coroutine\run(function () use ($client): void {
    $client->convertFile(
        filePath: __DIR__ . '/documento.odt',
        outputPath: __DIR__ . '/salida.pdf',
        format: 'pdf'
    );
});
```

Métodos principales del cliente:

- `convertFile(...)`
- `convertFileWithProgress(...)`
- `convertFileToMemory(...)`
- `convertBase64ToMemory(...)`
- `submitJobWithFile(...)`
- `submitJob(...)`
- `waitForFile(...)`
- `waitForFileWithProgress(...)`
- `getJobStatus(...)`
- `cancelJob(...)`
- `getFile(...)`
- `getFileWithProgress(...)`

Ejemplo disponible en:

```bash
examples/client.php
```

## Cola Redis y workers

El proyecto incluye componentes para manejar conversiones como jobs persistentes usando Redis.

Componentes principales:

- `RedisQueueConfig`
- `RedisJobQueue`
- `RedisJobStateStore`
- `RedisResultStore`
- `RedisRetryScheduler`
- `RetryPolicy`
- `ConversionWorker`
- `ConversionManager`

Ejemplo de configuración:

```php
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisJobQueue;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisJobStateStore;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisQueueConfig;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisResultStore;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisRetryScheduler;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RetryPolicy;

$config = new RedisQueueConfig(
    host: '127.0.0.1',
    port: 6379,
    prefix: 'adiutor'
);

$stateStore = new RedisJobStateStore($config);
$resultStore = new RedisResultStore($config);
$retryPolicy = new RetryPolicy(
    baseDelaySeconds: 2,
    maxDelaySeconds: 60,
    jitterRatio: 0.10
);

$retryScheduler = new RedisRetryScheduler($config, $retryPolicy);

$queue = new RedisJobQueue(
    config: $config,
    stateStore: $stateStore,
    resultStore: $resultStore,
    retryScheduler: $retryScheduler
);
```

La cola Redis permite:

- enviar trabajos asincrónicos
- consultar estado
- almacenar resultados
- programar reintentos
- manejar fallos persistentes
- usar dead-letter queue

## API principal

### `ServerHealthMonitor`

Monitorea el estado de las instancias de Unoserver.

Responsabilidades:

- verificar disponibilidad
- marcar servidores como saludables o no saludables
- aplicar umbral de fallos
- reintentar servidores después de un timeout
- exponer servidores saludables al balanceador

### `UnoserverLoadBalancer`

Coordina la selección de servidores y ejecuta conversiones.

Métodos principales:

- `start()`
- `stop()`
- `isRunning()`
- `convertSync(...)`
- `convertAsync(...)`
- `getServerMetrics()`
- `getServerPool()`
- `getTimeout()`
- `setTimeout(...)`

### `UnoserverXmlRpcClient`

Cliente XML-RPC de bajo nivel para comunicarse con Unoserver.

Responsabilidades:

- abrir conexión
- construir request HTTP/XML-RPC
- enviar conversión
- interpretar respuesta
- manejar faults XML-RPC
- devolver `UnoserverConversionResult`

### `UnoserverConversionResult`

Objeto de resultado de conversión.

Propiedades principales:

- `mode`
- `inputPath`
- `outputPath`
- `base64Content`
- `serverHost`
- `serverPort`

Métodos auxiliares:

- `isStream()`
- `isFile()`
- `hasBase64Content()`
- `hasOutputPath()`

### `ConversionManager`

Coordina operaciones de alto nivel sobre la cola y el worker.

### `ConversionWorker`

Procesa jobs pendientes usando el `UnoserverLoadBalancer`.

### `AdiutorTcp`

Servidor TCP de conversión de archivos.

### `AdiutorClientTcp`

Cliente TCP para consumir el servidor de conversión.

## Modos de conversión

### Modo `stream`

La conversión devuelve el contenido codificado en base64 dentro del resultado.

Es útil cuando quieres procesar el archivo en memoria o devolverlo directamente desde una API.

```php
$result = $converter->convertAsync(
    filePath: 'documento.odt',
    outputFormat: 'pdf',
    mode: 'stream'
);

if ($result->isStream() && $result->hasBase64Content()) {
    file_put_contents(
        'salida.pdf',
        base64_decode($result->base64Content)
    );
}
```

### Modo `file`

La conversión genera un archivo en una ruta de salida.

Es útil para reportes grandes o workflows donde el archivo final debe quedar persistido en disco.

```php
$result = $converter->convertAsync(
    filePath: 'documento.odt',
    outputFormat: 'pdf',
    outPath: 'salida.pdf',
    mode: 'file'
);

if ($result->isFile() && $result->hasOutputPath()) {
    echo "Archivo generado en: {$result->outputPath}\n";
}
```

## Comparativa `stream` vs `file`

| Modo | Resultado | Ideal para |
|---|---|---|
| `stream` | Contenido base64 en memoria | APIs, respuestas HTTP, postprocesado inmediato |
| `file` | Archivo generado en disco | Documentos grandes, almacenamiento, procesos batch |

## Ejemplos incluidos

El directorio `examples` incluye scripts de referencia:

```bash
examples/example.php
examples/server.php
examples/client.php
```

### Conversión directa

```bash
php examples/example.php
```

### Servidor TCP

```bash
php examples/server.php
```

### Cliente TCP

```bash
php examples/client.php
```

Antes de ejecutar los ejemplos asegúrate de tener:

- Unoserver corriendo en los puertos configurados.
- Redis corriendo si vas a usar el ejemplo de servidor con cola.
- Directorios de salida con permisos de escritura.

## Estructura interna

```text
src/
├── Client/
│   └── AdiutorClientTcp.php
├── Config/
│   └── UnoserverLoadBalancerConfig.php
├── Exceptions/
│   ├── AdiutorException.php
│   ├── InvalidArgumentException.php
│   ├── RuntimeException.php
│   └── Unoserver/
├── Server/
│   ├── AdiutorActionsEnum.php
│   └── AdiutorTcp.php
└── Unoserver/
    ├── Job/
    ├── PoolManager/
    ├── Queue/
    ├── Service/
    ├── Worker/
    ├── ServerHealthMonitor.php
    ├── ServerHealthMonitorInterface.php
    ├── UnoserverConversionResult.php
    ├── UnoserverLoadBalancer.php
    ├── UnoserverXmlRpcClient.php
    └── UnoserverXmlRpcClientInterface.php
```

## Recomendaciones

- Usa múltiples instancias de Unoserver para mejorar la concurrencia.
- Usa `stream` para archivos pequeños o respuestas inmediatas.
- Usa `file` para documentos grandes o procesamiento batch.
- Configura correctamente permisos de escritura en directorios de salida.
- Usa Redis si necesitas jobs persistentes, reintentos y consulta de estado.
- Mantén monitoreo activo en entornos productivos.
- Ajusta `concurrency`, `timeout` y `maxRetries` según la carga esperada.
- Evita cargar documentos muy grandes en memoria si no es necesario.

## Licencia

Este proyecto usa licencia **MIT**. Consulta el archivo [LICENSE](LICENSE) para más detalles.
###### 🌌 Ad astra per codicem
