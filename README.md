# XVII: 🛰️ satelles-odf-adiutor
![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)
![License](https://img.shields.io/github/license/Tabula17/satelles-odf-adiutor)
![Last commit](https://img.shields.io/github/last-commit/Tabula17/satelles-odf-adiutor)

Biblioteca PHP asincrónica basada en Swoole para la conversión de documentos mediante Unoserver. Proporciona un sistema de balance de carga y monitoreo de salud para manejar múltiples instancias de Unoserver de manera eficiente.

## Características

- Procesamiento asincrónico con Swoole
- Balance de carga entre múltiples instancias de Unoserver
- Monitoreo de salud de servidores
- Manejo de reconexiones y reintentos automáticos
- Soporte para modo stream y archivo
- Alto rendimiento y tolerancia a fallos

## Requisitos

- PHP 8.1 o superior
- Extensión Swoole
- Unoserver
- LibreOffice

## Componentes Principales

- `ServerHealthMonitor`: Monitorea la salud de las instancias de Unoserver
- `UnoserverLoadBalancer`: Maneja la distribución de carga entre servidores
- `UnoserverXmlRpcClient`: Cliente XML-RPC para comunicación con Unoserver

## Instalación

## Libreoffice/Unoserver
Para usar el servicio debes instalar [Unoserver](https://github.com/unoconv/unoserver).
Una vez instalado, puedes iniciar múltiples instancias de Unoserver en diferentes puertos para manejar cargas concurrentes.
```bash
# Ejemplo iniciando 3 instancias
unoserver --port 2003 &
unoserver --port 2004 &
unoserver --port 2005 &
```
Con systemd, puedes crear un servicio para cada instancia de Unoserver:
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


## Instala la librería vía Composer:
   ```bash
   composer require xvii/satelles-odf-adiutor
   ```

## Uso Básico

```php
use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;

// Configuración de servidores
$servers = [
    ['host' => '127.0.0.1', 'port' => 2003],
    ['host' => '127.0.0.1', 'port' => 2004],
    ['host' => '127.0.0.1', 'port' => 2005]
];

// Inicializar monitor de salud
$healthMonitor = new ServerHealthMonitor(
    servers: $servers,
    checkInterval: 30,
    failureThreshold: 3,
    retryTimeout: 60
);

// Configurar balanceador de carga
$converter = new UnoserverLoadBalancer(
    servers: $servers,
    healthMonitor: $healthMonitor,
    concurrency: 20,
    timeout: 15
);

// Iniciar servicios
Coroutine\run(function () use ($converter, $healthMonitor) {
    $healthMonitor->startMonitoring();
    $converter->start();
    
    // Conversión asíncrona
    $generator = $converter->convertAsync(
        filePath: '/ruta/archivo.odt',
        outputFormat: 'pdf',
        outPath: '/ruta/salida.pdf',
        mode: 'stream'
    );

    foreach ($generator as $data) {
        // Procesar datos convertidos
    }
});
```
### En el directorio `examples` se encuentra el script `test.php` que muestra un ejemplo completo de uso de la librería.
Para ejecutarlo, asegúrate de tener Unoserver corriendo en los puertos 2003 al 2005 y ejecuta:

```bash
php examples/test.php
```
Si alguna instancia de Unoserver no está disponible, el sistema intentará reconectarse automáticamente y distribuirá la carga entre las instancias saludables.

## Modos de Operación

### Modo Stream
```php
$generator = $converter->convertAsync(
    filePath: 'documento.odt',
    outputFormat: 'pdf',
    mode: 'stream'
);
```

### Modo Archivo
```php
$generator = $converter->convertAsync(
    filePath: 'documento.odt',
    outputFormat: 'pdf',
    outPath: 'salida.pdf',
    mode: 'file'
);
```

## Monitoreo de Salud

El sistema monitorea automáticamente la salud de los servidores Unoserver:

- Verifica la disponibilidad cada N segundos
- Marca servidores como no saludables después de X fallos
- Reintenta conexiones después de un período de timeout
- Distribuye carga solo entre servidores saludables



## Contribución

Las contribuciones son bienvenidas. Por favor:

1. Fork el repositorio
2. Crea una rama para tu feature
3. Commit tus cambios
4. Push a la rama
5. Crea un Pull Request

## Licencia

Este proyecto está bajo la Licencia MIT.

###### 🌌 Ad astra per codicem
