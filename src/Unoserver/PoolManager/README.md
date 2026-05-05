# Pool Manager para Unoserver

Este directorio contiene utilidades para instalar y administrar un pool local de instancias de **Unoserver** ejecutadas como servicios `systemd`.

El script principal es:

- `unoserver-systemd-install.sh`

Su objetivo es preparar el sistema para ejecutar múltiples instancias de Unoserver en distintos puertos, cada una administrada por `systemd`, con usuario dedicado, directorios aislados, configuración centralizada y un comando de gestión llamado `unoserver-manager`.

## ¿Qué problema resuelve?

Unoserver normalmente expone un único servicio de conversión en un puerto determinado. Para escenarios con mayor concurrencia, conviene levantar varias instancias en distintos puertos y distribuir las conversiones entre ellas.

Este proyecto puede balancear carga entre múltiples instancias de Unoserver mediante `UnoserverLoadBalancer`, por lo que este script ayuda a preparar ese pool de servicios en el sistema operativo.

Por ejemplo, después de instalar el pool puedes tener instancias escuchando en:

- `127.0.0.1:2003`
- `127.0.0.1:2004`
- `127.0.0.1:2005`
- `127.0.0.1:2006`
- `127.0.0.1:2007`

## Requisitos previos

Antes de usar el script debes tener instalados:

- LibreOffice u OpenOffice
- Python 3
- `pipx`
- `unoserver`
- `systemd`

En sistemas basados en Debian/Ubuntu, una instalación típica sería:

```bash
sudo apt update
sudo apt install -y libreoffice pipx python3-venv

sudo PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin \
    pipx install unoserver --system-site-packages --force
```

Verifica que `unoserver` quede disponible en:

```bash
/usr/local/bin/unoserver
```

## Instalación

Ejecuta el script como `root` o usando `sudo`:

```bash
sudo ./unoserver-systemd-install.sh
```

Si el archivo no tiene permisos de ejecución:

```bash
chmod +x unoserver-systemd-install.sh
sudo ./unoserver-systemd-install.sh
```

## ¿Qué hace el script?

El script `unoserver-systemd-install.sh` realiza una instalación completa del entorno necesario para gestionar varias instancias de Unoserver.

### 1. Muestra instrucciones iniciales

Al comenzar, imprime un mensaje explicando que debes tener instalado LibreOffice/OpenOffice y Unoserver.

También muestra comandos sugeridos para instalar LibreOffice y Unoserver mediante `pipx`.

### 2. Verifica permisos de root

El script valida que se esté ejecutando como `root`.

Si no se ejecuta con privilegios suficientes, finaliza con un mensaje de error.

Esto es necesario porque crea archivos en:

- `/etc/systemd/system`
- `/etc/unoserver`
- `/usr/local/bin`
- `/var/lib/unoserver`
- `/var/cache/unoserver`
- `/var/log/unoserver`

### 3. Crea el template systemd `unoserver@.service`

El script crea el archivo:

```text
/etc/systemd/system/unoserver@.service
```

Este archivo es un template de `systemd`. Permite iniciar múltiples servicios usando el mismo template, variando solamente el puerto.

Por ejemplo:

```bash
systemctl start unoserver@2003
systemctl start unoserver@2004
systemctl start unoserver@2005
```

Cada instancia recibe el puerto usando `%i`, que es el identificador de instancia de `systemd`.

El servicio se ejecuta con:

- usuario `unoserver`
- grupo `unoserver`
- directorio de trabajo `/tmp`
- variables `HOME` y `XDG_*` aisladas
- reinicio automático
- límites de memoria y CPU
- restricciones de seguridad básicas
- rutas de escritura controladas

El comando real que ejecuta cada servicio es:

```text
/usr/local/bin/unoserver-wrapper %i
```

### 4. Recarga systemd

Después de crear el template, ejecuta:

```bash
systemctl daemon-reload
```

Esto permite que `systemd` detecte la nueva unidad `unoserver@.service`.

### 5. Crea el usuario dedicado `unoserver`

El script crea un usuario de sistema llamado:

```text
unoserver
```

Si el usuario ya existe, no lo recrea, pero asegura que su home sea:

```text
/var/lib/unoserver
```

El usuario se crea como cuenta de sistema, sin shell interactiva:

```bash
useradd -r -s /bin/false -d /var/lib/unoserver unoserver
```

Esto reduce riesgos de seguridad y evita ejecutar LibreOffice/Unoserver como `root`.

### 6. Crea la estructura de directorios

El script crea los directorios necesarios para aislar la ejecución de LibreOffice/Unoserver:

```text
/etc/unoserver
/var/lib/unoserver
/var/lib/unoserver/.config
/var/lib/unoserver/.local/share
/var/lib/unoserver/dconf
/var/cache/unoserver/fontconfig
/var/log/unoserver
```

También crea un archivo mínimo de configuración `dconf`:

```text
/var/lib/unoserver/dconf/user
```

Luego ajusta propietarios y permisos para que el usuario `unoserver` pueda escribir únicamente donde corresponde.

### 7. Crea archivos de configuración

El script crea:

```text
/etc/unoserver/env.conf
/etc/unoserver/pools.conf
```

#### `/etc/unoserver/env.conf`

Define opciones adicionales para Unoserver.

Por defecto:

```bash
UNOSERVER_EXTRA_OPTS="--conversion-timeout 300"
```

Puedes modificar este archivo para agregar opciones globales a todas las instancias.

#### `/etc/unoserver/pools.conf`

Define los puertos que componen el pool:

```bash
UNOSERVER_PORTS="2003 2004 2005 2006 2007"
```

Estos puertos son usados por `unoserver-manager` para iniciar, detener, habilitar, reiniciar y monitorear todas las instancias configuradas.

### 8. Crea el wrapper `unoserver-wrapper`

El script crea:

```text
/usr/local/bin/unoserver-wrapper
```

Este wrapper es el comando que realmente inicia cada instancia de Unoserver.

Recibe el puerto como argumento:

```bash
unoserver-wrapper 2003
```

Internamente selecciona dinámicamente un puerto UNO libre en `127.0.0.1`, dentro del rango `1024-65535`, evitando reutilizar un puerto que ya esté abierto y evitando que coincida con el puerto principal de Unoserver.

Por ejemplo, si la instancia pública de Unoserver escucha en:

```text
127.0.0.1:2003
```
el wrapper puede asignar internamente un puerto UNO libre como:
```text
127.0.0.1:34567
```
El puerto UNO interno puede cambiar en cada arranque del servicio. Esto evita conflictos entre instancias de LibreOffice/UNO cuando se ejecutan múltiples servicios en paralelo.

El wrapper ejecuta Unoserver con:

- `--port`
- `--uno-port`
- `--interface 127.0.0.1`
- opciones adicionales desde `/etc/unoserver/env.conf`

### 9. Crea el manager `unoserver-manager`

El script crea:

```text
/usr/local/bin/unoserver-manager
```

Este comando permite administrar el pool de instancias sin tener que llamar manualmente a `systemctl` para cada puerto.

Lee la configuración desde:

```text
/etc/unoserver/pools.conf
```

Si no encuentra configuración, usa por defecto:

```text
2003 2004 2005
```

## Uso de `unoserver-manager`

### Iniciar todas las instancias configuradas

```bash
sudo unoserver-manager start
```

### Detener todas las instancias

```bash
sudo unoserver-manager stop
```

### Reiniciar todas las instancias

```bash
sudo unoserver-manager restart
```

### Habilitar inicio automático

```bash
sudo unoserver-manager enable
```

### Deshabilitar inicio automático

```bash
sudo unoserver-manager disable
```

### Gestionar un puerto específico

```bash
sudo unoserver-manager start 2003
sudo unoserver-manager stop 2003
sudo unoserver-manager restart 2003
sudo unoserver-manager enable 2003
sudo unoserver-manager disable 2003
```

### Ver estado resumido del pool

```bash
unoserver-manager status
```

Este comando muestra una tabla con:

- puerto
- estado del servicio
- memoria usada
- si está habilitado
- PID
- verificación de puerto abierto/cerrado

### Ver estado de un puerto específico

```bash
unoserver-manager status 2003
```

### Ver estado detallado de todas las instancias

```bash
unoserver-manager status-full
```

### Ver logs

Logs de una instancia:

```bash
unoserver-manager logs 2003
```

Logs de todas las instancias:

```bash
unoserver-manager logs all
```

### Verificar conectividad de puertos

```bash
unoserver-manager test
```

Este comando intenta conectarse a cada puerto configurado en `127.0.0.1`.

### Crear una nueva instancia

```bash
sudo unoserver-manager create 2008
```

Esto habilita e inicia una instancia `systemd` para el puerto indicado.

Nota: si quieres que el puerto forme parte del pool administrado por comandos globales, agrégalo también a:

```text
/etc/unoserver/pools.conf
```

### Eliminar una instancia

```bash
sudo unoserver-manager remove 2008
```

Esto detiene y deshabilita el servicio `unoserver@2008`.

## Archivos creados por la instalación

| Ruta | Descripción |
|---|---|
| `/etc/systemd/system/unoserver@.service` | Template systemd para múltiples instancias |
| `/etc/unoserver/env.conf` | Variables y opciones adicionales para Unoserver |
| `/etc/unoserver/pools.conf` | Lista de puertos del pool |
| `/usr/local/bin/unoserver-wrapper` | Wrapper que ejecuta cada instancia |
| `/usr/local/bin/unoserver-manager` | CLI para gestionar el pool |
| `/var/lib/unoserver` | Home y configuración aislada |
| `/var/cache/unoserver` | Cache de LibreOffice/Unoserver |
| `/var/log/unoserver` | Directorio de logs |

## Relación con `satelles-odf-adiutor`

Una vez levantado el pool, puedes configurar el balanceador del proyecto para usar esos puertos.

Ejemplo:

```php
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;

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
    ]),
    new ConnectionConfig([
        'name' => 'unoserver-2005',
        'host' => '127.0.0.1',
        'port' => 2005,
    ])
);
```

Estos servidores pueden ser usados por:

- `ServerHealthMonitor`
- `UnoserverLoadBalancer`
- `ConversionWorker`
- `AdiutorTcp`

## Notas de seguridad

El template creado por el script aplica algunas restricciones:

- ejecuta el servicio con usuario no privilegiado
- usa `NoNewPrivileges=yes`
- protege partes del sistema con `ProtectSystem=strict`
- protege el home con `ProtectHome=yes`
- permite escritura solo en rutas necesarias
- limita memoria con `MemoryMax=2G`
- limita CPU con `CPUQuota=50%`

Si LibreOffice o Unoserver necesitan acceso a otras rutas para tus documentos, ajusta `ReadWritePaths` en:

```text
/etc/systemd/system/unoserver@.service
```

Después de modificar el servicio, recarga `systemd`:

```bash
sudo systemctl daemon-reload
sudo unoserver-manager restart
```

## Comandos rápidos

```bash
sudo unoserver-manager enable
sudo unoserver-manager start
unoserver-manager status
unoserver-manager test
unoserver-manager logs all
```

## Troubleshooting

### `unoserver` no existe

Verifica la instalación:

```bash
which unoserver
ls -lah /usr/local/bin/unoserver
```

### El servicio no inicia

Revisa el estado:

```bash
systemctl status unoserver@2003 --no-pager
journalctl -u unoserver@2003 -n 100 --no-pager
```

### El puerto no responde

Ejecuta:

```bash
unoserver-manager test
unoserver-manager status
```

### Cambié los puertos y no se aplican

Edita:

```text
/etc/unoserver/pools.conf
```

Luego ejecuta:

```bash
sudo unoserver-manager enable
sudo unoserver-manager restart
unoserver-manager status
```

## Nota

El script instala el comando `unoserver-manager`.

Si en documentación antigua aparece `unoserver-pool`, debe considerarse reemplazado por `unoserver-manager`.
