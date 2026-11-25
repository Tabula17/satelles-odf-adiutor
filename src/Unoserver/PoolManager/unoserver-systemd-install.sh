#!/bin/bash
# Instalador del template systemd y del manager de pool de instancias unoserver.
#
# Variables configurables por entorno:
#   UNOSERVER_SHARED_TMP_DIR=/opt/sig-services/tmp/unoserver
#
# Ejemplo:
#   sudo UNOSERVER_SHARED_TMP_DIR=/opt/sig-services/tmp/unoserver bash unoserver-systemd-install.sh

cat << 'EOF'
Antes de utilizar este script asegúrese de tener instalado libreoffice/openoffice y unoserver en su sistema.
Este script crea un template de servicio systemd para ejecutar múltiples instancias de unoserver en diferentes puertos,
cada una con su propio entorno aislado.

Instalación de LibreOffice:
sudo apt update
sudo apt install -y libreoffice

Instalación de Unoserver global mediante pipx (recomendado):
sudo apt update
sudo apt install -y pipx python3-venv
sudo PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin pipx install unoserver --system-site-packages --force

Después de ejecutar este script, puedes usar el comando 'unoserver-manager' para gestionar las instancias de unoserver.

El template usa ProtectSystem=strict, por lo que unoserver solo puede escribir en las rutas
declaradas en ReadWritePaths. El directorio temporal compartido con la aplicación (por defecto
/opt/sig-services/tmp/unoserver) se agrega mediante un drop-in aditivo para no reemplazar
las rutas ya permitidas.
EOF

set -u

# Directorio temporal compartido entre PHP y unoserver (debe ser escribible por ambos).
UNOSERVER_SHARED_TMP_DIR="${UNOSERVER_SHARED_TMP_DIR:-/opt/sig-services/tmp/unoserver}"

if [[ "${UNOSERVER_SHARED_TMP_DIR}" != /* ]]; then
    echo "ERROR: UNOSERVER_SHARED_TMP_DIR debe ser una ruta absoluta: ${UNOSERVER_SHARED_TMP_DIR}"
    exit 1
fi

if [[ "${UNOSERVER_SHARED_TMP_DIR}" == *" "* ]]; then
    echo "ERROR: UNOSERVER_SHARED_TMP_DIR no puede contener espacios (systemd requiere escapado)."
    exit 1
fi

# Verificar ejecución como root
if [[ $EUID -ne 0 ]]; then
   echo "Este script debe ejecutarse como root o con sudo"
   exit 1
fi

echo -e "\n========== Creando template de servicio systemd para unoserver ==========\n"

# Template systemd service file for unoserver
tee /etc/systemd/system/unoserver@.service > /dev/null << 'EOF'
[Unit]
Description=Unoserver instance on port %i
After=network.target
Documentation=https://github.com/unoconv/unoserver

[Service]
Type=simple
User=unoserver
Group=unoserver

# Directorio de trabajo
WorkingDirectory=/tmp

# Variables de entorno
Environment=HOME=/var/lib/unoserver
Environment=USER=unoserver
Environment=XDG_CACHE_HOME=/var/cache/unoserver
Environment=XDG_CONFIG_HOME=/var/lib/unoserver/.config
Environment=XDG_DATA_HOME=/var/lib/unoserver/.local/share
Environment=DCONF_PROFILE=/var/lib/unoserver/dconf

# Archivo de configuración adicional
EnvironmentFile=-/etc/unoserver/env.conf

# Comando de ejecución
ExecStart=/usr/local/bin/unoserver-wrapper %i

# Reinicio automático
Restart=always
RestartSec=5

# Límites de recursos
MemoryMax=2G
CPUQuota=50%

# Seguridad mejorada
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes

# Directorios con permisos de escritura necesarios
ReadWritePaths=/tmp /var/tmp
ReadWritePaths=/var/lib/unoserver
ReadWritePaths=/var/cache/unoserver
ReadWritePaths=/var/log/unoserver

# Necesario porque LibreOffice usa /tmp
PrivateTmp=no

# La ruta compartida con la aplicación se agrega en:
# /etc/systemd/system/unoserver@.service.d/10-shared-tmp.conf

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload

echo -e "\n========== Creando usuario unoserver ==========\n"

# Crear usuario si no existe
if ! id -u unoserver > /dev/null 2>&1; then
    useradd -r -s /bin/false -d /var/lib/unoserver unoserver
    echo "Usuario 'unoserver' creado."
else
    echo "Usuario 'unoserver' ya existe."
    # Asegurar que el home es correcto
    usermod -d /var/lib/unoserver unoserver
fi

echo -e "\n========== Creando estructura de directorios ==========\n"

# Crear directorios necesarios
mkdir -p /etc/unoserver
mkdir -p /var/lib/unoserver/{.config,.local/share,dconf}
mkdir -p /var/cache/unoserver/fontconfig
mkdir -p /var/log/unoserver

# Directorio temporal compartido con la aplicación (PHP escribe/lee, unoserver escribe).
install -d -o unoserver -g unoserver -m 0775 "${UNOSERVER_SHARED_TMP_DIR}"

# Archivo de configuración dconf mínimo
tee /var/lib/unoserver/dconf/user > /dev/null << 'EOF'
[user]
EOF

# Configurar permisos
chown -R unoserver:unoserver /var/lib/unoserver
chown -R unoserver:unoserver /var/cache/unoserver
chown -R unoserver:unoserver /var/log/unoserver
chown root:unoserver /etc/unoserver

chmod 750 /etc/unoserver
chmod 750 /var/lib/unoserver
chmod 750 /var/lib/unoserver/.config
chmod 750 /var/lib/unoserver/dconf
chmod 750 /var/cache/unoserver
chmod 750 /var/log/unoserver

echo -e "\n========== Configurando permisos de escritura en systemd ==========\n"

# IMPORTANTE: ReadWritePaths en un drop-in REEMPLAZA la lista del unit si no
# se usa el prefijo '+'. Sin '+' se perdería /tmp y LibreOffice dejaría de funcionar.
mkdir -p /etc/systemd/system/unoserver@.service.d
tee /etc/systemd/system/unoserver@.service.d/10-shared-tmp.conf > /dev/null <<EOF
[Service]
# '+' agrega esta ruta a las ya definidas en el template.
ReadWritePaths=+${UNOSERVER_SHARED_TMP_DIR}
EOF

systemctl daemon-reload

echo "Drop-in creado:"
cat /etc/systemd/system/unoserver@.service.d/10-shared-tmp.conf

# Verificación
echo "Directorio home de unoserver:"
getent passwd unoserver
echo -e "\nEstructura creada:"
ls -lahd /var/lib/unoserver/ /var/cache/unoserver/ /etc/unoserver/ "${UNOSERVER_SHARED_TMP_DIR}/"

echo -e "\n========== Creando archivos de configuración ==========\n"

# Archivo de variables de entorno
tee /etc/unoserver/env.conf > /dev/null << 'EOF'
# Variables de entorno adicionales para unoserver
UNOSERVER_EXTRA_OPTS="--conversion-timeout 300"
EOF

# Archivo de configuración del pool
tee /etc/unoserver/pools.conf > /dev/null <<EOF
# Configuración de Pool Manager para Unoserver
# Define los puertos que deseas usar para las instancias de unoserver
# Separadores válidos: espacio, coma o salto de línea.
UNOSERVER_PORTS="2003 2004 2005 2006 2007"
UNOSERVER_LISTEN_INTERFACE="127.0.0.1"
# Debe coincidir con ReadWritePaths del drop-in y con options['tmp_dir'] en PHP.
UNOSERVER_SHARED_TMP_DIR="${UNOSERVER_SHARED_TMP_DIR}"
EOF

# Ajustar permisos de los archivos de configuración.
# pools.conf no contiene secretos y debe ser legible por usuarios no-root
# para que 'unoserver-manager status' no pierda la lista de puertos.
chown root:unoserver /etc/unoserver/env.conf /etc/unoserver/pools.conf
chmod 640 /etc/unoserver/env.conf
chmod 644 /etc/unoserver/pools.conf

echo -e "\n========== Creando wrapper script ==========\n"

# Script wrapper que asigna puertos únicos y carga configuración
tee /usr/local/bin/unoserver-wrapper > /dev/null << 'EOF'
#!/bin/bash

PORT=$1
if [[ ! "$PORT" =~ ^[0-9]+$ ]]; then
    echo "Puerto inválido: $PORT" >&2
    exit 1
fi

while true; do
    UNO_PORT=$(shuf -i 1024-65535 -n 1) # Puerto UNO libre para cada instancia
    [[ "$UNO_PORT" == "$PORT" ]] && continue
    (echo >/dev/tcp/127.0.0.1/$UNO_PORT) >/dev/null 2>&1 || break
done

# Cargar configuración si existe
if [ -f /etc/unoserver/env.conf ]; then
    source /etc/unoserver/env.conf
fi

if [[ -f /etc/unoserver/pools.conf ]]; then
    source /etc/unoserver/pools.conf
fi
# Configurar entorno
export HOME=/var/lib/unoserver
export XDG_CACHE_HOME=/var/cache/unoserver

INTERFACE=${UNOSERVER_LISTEN_INTERFACE:-"127.0.0.1"}

exec /usr/local/bin/unoserver \
    --port "$PORT" \
    --uno-port "$UNO_PORT" \
    --interface "$INTERFACE" \
    ${UNOSERVER_EXTRA_OPTS:-}
EOF

chmod 755 /usr/local/bin/unoserver-wrapper

echo -e "\n========== Creando manager script ==========\n"

tee /usr/local/bin/unoserver-manager > /dev/null << 'ENDOFSCRIPT'
#!/bin/bash
# /usr/local/bin/unoserver-manager

CONFIG_FILE="/etc/unoserver/pools.conf"

# Cargar configuración. Si no es legible, avisar en vez de caer silenciosamente
# en el default (antes eso dejaba PORTS=("2003 2004 2005") como un solo elemento).
if [[ -r "$CONFIG_FILE" ]]; then
    # shellcheck source=/etc/unoserver/pools.conf
    source "$CONFIG_FILE"
else
    echo "AVISO: no se pudo leer $CONFIG_FILE; usando valores por defecto." >&2
fi

INTERFACE=${UNOSERVER_LISTEN_INTERFACE:-"127.0.0.1"}
SHARED_TMP_DIR=${UNOSERVER_SHARED_TMP_DIR:-"/opt/sig-services/tmp/unoserver"}
SERVICE_PREFIX="unoserver@"

# Parseo robusto: admite espacios, comas, punto y coma y saltos de línea.
PORTS=()
while IFS= read -r port; do
    [[ -n "$port" ]] && PORTS+=("$port")
done < <(printf '%s\n' "${UNOSERVER_PORTS:-2003 2004 2005}" | tr ',; \t' '\n\n\n\n')

if [[ ${#PORTS[@]} -eq 0 ]]; then
    PORTS=(2003 2004 2005)
fi

for port in "${PORTS[@]}"; do
    if [[ ! "$port" =~ ^[0-9]+$ ]]; then
        echo "ERROR: puerto inválido en UNOSERVER_PORTS: '$port'" >&2
        exit 1
    fi
done

require_root() {
    if [[ $EUID -ne 0 ]]; then
        exec sudo "$0" "$@"
    fi
}

manage_service() {
    local action=$1
    local port=$2
    local service_name="${SERVICE_PREFIX}${port}"

    # Corrección de unidades problemáticas
    local unit_state
    unit_state=$(systemctl is-enabled "$service_name" 2>&1)
    if [[ "$unit_state" == *"bad-setting"* ]] || [[ "$unit_state" == *"not-found"* ]]; then
        echo "⚠️  Unidad $service_name tiene problemas. Intentando corregir..."
        systemctl disable "$service_name" 2>/dev/null
        systemctl reset-failed "$service_name" 2>/dev/null
        systemctl daemon-reload
    fi

    case $action in
        start|stop|restart|enable|disable)
            systemctl "$action" "$service_name"
            ;;
        status)
            # Sin paginador, sin logs continuos
            systemctl status --no-pager --lines=0 "$service_name"
            ;;
        *)
            echo "Acción no válida: $action"
            return 1
            ;;
    esac
}

show_status_all() {
    # Mostrar resumen compacto
    printf "%-8s %-12s %-12s %-15s %-8s %s\n" "PUERTO" "ESTADO" "MEMORIA" "HABILITADO" "PID" "PUERTO"
    echo "────────────────────────────────────────────────────────────────────────"

    for port in "${PORTS[@]}"; do
        service_name="${SERVICE_PREFIX}${port}"

        # Comandos que no bloquean
        systemctl is-enabled --quiet "$service_name" 2>/dev/null && enabled="habilitado" || enabled="deshabilitado"
        systemctl is-active --quiet "$service_name" 2>/dev/null && status="activo" || status="inactivo"

        if [[ "$status" == "activo" ]]; then
            main_pid=$(systemctl show "$service_name" -p MainPID --value 2>/dev/null)
            memory=$(systemctl show "$service_name" -p MemoryCurrent --value 2>/dev/null |
                     awk '{printf "%.1f MB", $1/1024/1024}')
            [[ -z "$memory" ]] && memory="N/A"
            [[ "$main_pid" == "0" ]] && main_pid="N/A"

            # Verificar puerto (rápido, con timeout corto)
            if timeout 1 bash -c "echo >/dev/tcp/$INTERFACE/$port" 2>/dev/null; then
                port_check="✓ abierto"
            else
                port_check="✗ cerrado"
            fi
        else
            memory="N/A"
            main_pid="N/A"
            port_check="-"
        fi

        printf "%-8s %-12s %-12s %-15s %-8s %s\n" \
            "$port" "$status" "$memory" "$enabled" "$main_pid" "$port_check"
    done
    echo ""

    # Resumen general
    active_count=0
    for port in "${PORTS[@]}"; do
        if systemctl is-active --quiet "${SERVICE_PREFIX}${port}" 2>/dev/null; then
            active_count=$((active_count + 1))
        fi
    done
    echo "Total: $active_count/${#PORTS[@]} instancias activas"
}

show_shared_tmp() {
    echo "Directorio temporal compartido: ${SHARED_TMP_DIR}"
    echo "Drop-in: /etc/systemd/system/unoserver@.service.d/10-shared-tmp.conf"

    if [[ -f /etc/systemd/system/unoserver@.service.d/10-shared-tmp.conf ]]; then
        sed 's/^/  /' /etc/systemd/system/unoserver@.service.d/10-shared-tmp.conf
    else
        echo "  ⚠️  No existe el drop-in: unoserver no podrá escribir en esa ruta con ProtectSystem=strict"
    fi

    echo ""
    echo "ReadWritePaths efectivo del template:"
    systemctl cat "${SERVICE_PREFIX}2003" 2>/dev/null | grep -E '^ReadWritePaths=' | sed 's/^/  /' || true

    echo ""
    if [[ -d "$SHARED_TMP_DIR" ]]; then
        ls -ld "$SHARED_TMP_DIR"
    else
        echo "⚠️  El directorio no existe"
    fi

    # La prueba debe hacerse como unoserver; si no somos root, re-ejecutamos con sudo.
    if [[ $EUID -ne 0 ]]; then
        echo ""
        echo "Para probar escritura real ejecuta: sudo unoserver-manager tmpdir"
        return 0
    fi

    local test_file="${SHARED_TMP_DIR}/.write-test-$$"
    if sudo -u unoserver touch "$test_file" 2>/dev/null; then
        echo "✅ 'unoserver' puede escribir en el directorio (fuera del sandbox de systemd)"
        rm -f "$test_file"
    else
        echo "❌ 'unoserver' NO puede escribir en el directorio"
    fi

    echo ""
    echo "Configuración que debe usar PHP (ConnectionConfig::options):"
    echo "  'tmp_dir' => '${SHARED_TMP_DIR}'"
}

# Verificar permisos para comandos que modifican
case ${1:-} in
    start|stop|restart|enable|disable|create|remove)
        require_root "$@"
        ;;
    tmpdir)
        # Solo necesita root para la prueba de escritura; show_shared_tmp lo gestiona.
        ;;
esac

case ${1:-} in
    start|stop|restart|enable|disable)
        action=$1
        shift

        if [[ -n "${1:-}" ]]; then
            manage_service "$action" "$1"
        else
            for port in "${PORTS[@]}"; do
                echo "[$action] Puerto $port..."
                manage_service "$action" "$port"
            done
        fi
        ;;

    status)
        if [[ -n "${2:-}" ]]; then
            # Estado de un puerto específico
            manage_service status "$2"
        else
            # Estado resumido de todos (sin bloqueo)
            show_status_all
        fi
        ;;

    status-full)
        # Estado detallado de todos (con logs limitados)
        for port in "${PORTS[@]}"; do
            echo "=========================================="
            echo "Puerto ${port}"
            systemctl status --no-pager --lines=5 "${SERVICE_PREFIX}${port}"
            echo ""
        done
        ;;

    tmpdir)
        show_shared_tmp
        ;;

    create)
        port=${2:-}
        if [[ -z "$port" ]]; then
            echo "Error: Especifica un puerto"
            echo "Uso: $0 create <puerto>"
            exit 1
        fi

        echo "Creando instancia en puerto $port..."
        systemctl enable "${SERVICE_PREFIX}${port}"
        systemctl start "${SERVICE_PREFIX}${port}"
        sleep 1
        manage_service status "$port"
        ;;

    remove)
        port=${2:-}
        if [[ -z "$port" ]]; then
            echo "Error: Especifica un puerto"
            echo "Uso: $0 remove <puerto>"
            exit 1
        fi

        echo "Eliminando instancia en puerto $port..."
        systemctl stop "${SERVICE_PREFIX}${port}" 2>/dev/null
        systemctl disable "${SERVICE_PREFIX}${port}" 2>/dev/null
        ;;

    logs)
        port=${2:-}
        if [[ -z "$port" ]]; then
            echo "Error: Especifica un puerto o 'all'"
            exit 1
        fi

        if [[ "$port" == "all" ]]; then
            journalctl -u "${SERVICE_PREFIX}*" -f
        else
            journalctl -u "${SERVICE_PREFIX}${port}" -f
        fi
        ;;

    test)
        # Test rápido de todos los puertos
        echo "Verificando conectividad de puertos..."
        for port in "${PORTS[@]}"; do
            if timeout 1 bash -c "echo >/dev/tcp/$INTERFACE/$port" 2>/dev/null; then
                echo "✅ Puerto $port: responde"
            else
                echo "❌ Puerto $port: no responde"
            fi
        done
        ;;

    *)
        echo "╔════════════════════════════════════════════════╗"
        echo "║     Gestión de Instancias Unoserver            ║"
        echo "╚════════════════════════════════════════════════╝"
        echo ""
        echo "Uso: unoserver-manager <comando> [opciones]"
        echo ""
        echo "Comandos de gestión:"
        echo "  start [puerto]     Iniciar servicio(s)"
        echo "  stop [puerto]      Detener servicio(s)"
        echo "  restart [puerto]   Reiniciar servicio(s)"
        echo "  enable [puerto]    Habilitar inicio automático"
        echo "  disable [puerto]   Deshabilitar inicio automático"
        echo "  create <puerto>    Crear y activar nueva instancia"
        echo "  remove <puerto>    Eliminar instancia existente"
        echo ""
        echo "Comandos de monitoreo:"
        echo "  status [puerto]    Resumen de estado (sin bloqueo)"
        echo "  status-full        Estado detallado de todos"
        echo "  logs <puerto|all>  Ver logs en tiempo real"
        echo "  test               Verificar conectividad de puertos"
        echo "  tmpdir             Verificar directorio temporal compartido"
        echo ""
        echo "Puertos configurados: ${PORTS[*]}"
        echo "Directorio temporal: ${SHARED_TMP_DIR}"
        echo "Configuración: $CONFIG_FILE"
        exit 0
        ;;
esac
ENDOFSCRIPT

chmod 755 /usr/local/bin/unoserver-manager

echo -e "\n========== Instalación completada ==========\n"
echo "Puedes usar el comando 'unoserver-manager' para gestionar las instancias."
echo "Archivos de configuración en /etc/unoserver/:"
ls -lah /etc/unoserver/
echo -e "\nPara iniciar los servicios configurados:"
echo "  unoserver-manager start"
echo "Para ver el estado:"
echo "  unoserver-manager status"
echo "Para verificar el directorio temporal compartido:"
echo "  unoserver-manager tmpdir"
echo -e "\nConfigura la librería PHP con el mismo directorio:"
echo "  new ConnectionConfig(['options' => ['tmp_dir' => '${UNOSERVER_SHARED_TMP_DIR}']])"
