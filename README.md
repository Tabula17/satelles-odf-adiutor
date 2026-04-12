# XVII: 🛰️ satelles-odf-adiutor

![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)
![License](https://img.shields.io/github/license/Tabula17/satelles-odf-adiutor)
![Last commit](https://img.shields.io/github/last-commit/Tabula17/satelles-odf-adiutor)
![Swoole](https://img.shields.io/badge/Swoole-Asynchronous-2ea44f)
![Unoserver](https://img.shields.io/badge/Unoserver-XML--RPC-6f42c1)

**satelles-odf-adiutor** es una biblioteca PHP asincrónica basada en Swoole para la conversión de documentos mediante Unoserver.

Está pensada para integrarse con flujos de generación de reportes, documentos, tickets y plantillas ODF, permitiendo convertir archivos a otros formatos de forma eficiente, escalable y distribuida.

## Contenido

- [Características](#características)
- [Why this library?](#why-this-library)
- [Requisitos](#requisitos)
- [Compatibilidad recomendada](#compatibilidad-recomendada)
- [Instalación](#instalación)
- [Configuración de Unoserver](#configuración-de-unoserver)
- [Quick start](#quick-start)
- [API principal](#api-principal)
- [Modos de operación](#modos-de-operación)
- [Comparativa `file` vs `stream`](#comparativa-file-vs-stream)
- [Ejemplos incluidos](#ejemplos-incluidos)
- [Monitoreo de salud](#monitoreo-de-salud)
- [Recomendaciones de uso](#recomendaciones-de-uso)
- [Estructura interna](#estructura-interna)
- [Contribución](#contribución)
- [Licencia](#licencia)

## Características

- Integración con Unoserver mediante XML-RPC
- Procesamiento asincrónico con Swoole
- Balance de carga entre múltiples instancias de Unoserver
- Monitoreo de salud de servidores
- Manejo de reconexiones y reintentos automáticos
- Soporte para modo `stream` y modo `file`
- Resultados tipados mediante objetos de respuesta
- Alto rendimiento y tolerancia a fallos

## Why this library?

Si ya generas documentos ODF en tu sistema, esta biblioteca te permite:

- convertir reportes y tickets a PDF u otros formatos
- distribuir la carga entre varias instancias de Unoserver
- evitar cuellos de botella en entornos con alta concurrencia
- obtener respuestas estructuradas y trazables
- integrar conversiones en flujos asincrónicos con Swoole

## Requisitos

- PHP 8.1 o superior
- Extensión Swoole
- Unoserver
- LibreOffice

## Compatibilidad recomendada

Aunque la biblioteca mantiene compatibilidad con PHP 8.1+, el entorno recomendado para despliegue actual es:

- PHP 8.4
- Swoole 6.2

## Instalación
```bash 
composer require xvii/satelles-odf-adiutor
```

## Configuración de Unoserver

Para usar la librería debes tener Unoserver ejecutándose en una o más instancias.

### Ejemplo con múltiples puertos
```bash 
# Ejemplo iniciando 3 instancias
unoserver --port 2003 &
unoserver --port 2004 &
unoserver --port 2005 &
```
### Ejemplo con systemd
```ini
# Usando systemd para múltiples instancias
for port in {2003..2005}; do
cat > /etc/systemd/system/unoserver-$port.service <<EOF
[Unit]
Description=Unoserver instance $port

[Service]
ExecStart=/usr/bin/unoserver --port $port
Restart=always
EOF

systemctl enable unoserver-$port
systemctl start unoserver-$port
done
```

Repite la configuración para cada instancia cambiando el puerto.
## Quick start
```php
<?php

use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;

$servers= new ConnectionCollection(
    ['host' => '127.0.0.1', 'port' => 2003],
    ['host' => '127.0.0.1', 'port' => 2004],
    ['host' => '127.0.0.1', 'port' => 2005],
];

$healthMonitor = new ServerHealthMonitor(
    servers: $servers,
    checkInterval: 30,
    failureThreshold: 3,
    retryTimeout: 60
);

$converter = new UnoserverLoadBalancer(
    healthMonitor: $healthMonitor,
    concurrency: 20,
    timeout: 15
);

Coroutine\run(function () use ($converter, $healthMonitor): void {
    $healthMonitor->startMonitoring();
    $converter->start();

    $result = $converter->convertAsync(
        filePath: '/ruta/documento.odt',
        outputFormat: 'pdf',
        outPath: '/ruta/salida.pdf',
        mode: 'file'
    );

    if ($result->isFile()) {
        echo "Archivo generado en: {$result->outputPath}\n";
    }

    $converter->stop();
    $healthMonitor->stopMonitoring();
});
```

## API principal

### `ServerHealthMonitor`

Se encarga de monitorear el estado de las instancias de Unoserver.

Responsabilidades principales:

- comprobar disponibilidad
- marcar servidores saludables o no saludables
- reintentar después de fallos
- exponer el conjunto de servidores saludables

### `UnoserverLoadBalancer`

Coordina la selección de servidores y ejecuta conversiones con reintentos.

Métodos principales:

- `start()`
- `stop()`
- `convertSync(...)`
- `convertAsync(...)`
- `getServerMetrics()`

### `UnoserverXmlRpcClient`

Cliente XML-RPC de bajo nivel para comunicación directa con Unoserver.

Responsabilidades:

- abrir conexión
- enviar request HTTP/XML-RPC
- procesar respuesta
- interpretar faults XML-RPC
- devolver un `UnoserverConversionResult`

### `UnoserverConversionResult`

Objeto de resultado de conversión.

Propiedades expuestas:

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
## Modos de operación

### Modo `file`

Unoserver escribe directamente el archivo convertido en la ruta de salida.
```php
$result = $converter->convertAsync( 
    filePath: 'documento.odt', 
    outputFormat: 'pdf', 
    outPath: 'salida.pdf', 
    mode: 'file' ); 
if ($result->isFile() && $result->hasOutputPath()) { 
    echo "OK: {$result->outputPath}\n"; 
  } 
```

### Modo `stream`

La conversión devuelve el contenido codificado en base64 dentro del resultado.

```php
$result = $converter->convertAsync( 
    filePath: 'documento.odt', 
    outputFormat: 'pdf', 
    mode: 'stream' ); 
if ($result->isStream() && $result->hasBase64Content()) { 
    file_put_contents( 'salida.pdf', base64_decode($result->base64Content) ); 
}
```


## Comparativa `file` vs `stream`

| Modo | Ventaja principal | Ideal para |
|------|-------------------|------------|
| `file` | Unoserver genera el archivo final en disco | Reportes grandes, workflows de almacenamiento |
| `stream` | Resultado en memoria para postprocesado inmediato | Procesamiento dinámico, APIs, respuestas HTTP |

## Ejemplos incluidos

En el directorio `examples` encontrarás scripts de ejemplo:

- `examples/example.php`
- `examples/test.php`

Para ejecutarlos:
```bash
php examples/example.php
# o
php examples/test.php
```


Asegúrate de tener Unoserver corriendo en los puertos configurados en cada ejemplo.

## Monitoreo de salud

El sistema monitorea automáticamente la salud de los servidores Unoserver:

- verifica la disponibilidad cada N segundos
- marca servidores como no saludables después de X fallos
- reintenta conexiones después de un período de timeout
- distribuye carga solo entre servidores saludables

## Recomendaciones de uso

- Usa `mode: 'file'` cuando quieras que Unoserver genere el archivo final directamente.
- Usa `mode: 'stream'` cuando quieras recibir el contenido en memoria.
- Mantén múltiples instancias de Unoserver si esperas alta concurrencia.
- Configura correctamente los directorios de salida y permisos de escritura.
- Prefiere `file` para documentos grandes si no necesitas procesarlos en memoria.

## Estructura interna

### Capa de cliente
`UnoserverXmlRpcClient` encapsula:

- conexión por socket
- envío HTTP/XML-RPC
- lectura de respuesta
- validación de faults XML-RPC
- extracción del contenido de respuesta

### Capa de balanceo
`UnoserverLoadBalancer` encapsula:

- selección de servidor
- reintentos
- métricas de ejecución
- coordinación con `ServerHealthMonitor`

### Capa de salud
`ServerHealthMonitor` se encarga de mantener actualizado el estado de los servidores disponibles.

## Contribución

Las contribuciones son bienvenidas. Por favor:

1. Haz fork del repositorio
2. Crea una rama para tu feature
3. Haz commit de tus cambios
4. Push a la rama
5. Abre un Pull Request

## Licencia

Este proyecto está bajo la Licencia MIT.

###### 🌌 Ad astra per codicem