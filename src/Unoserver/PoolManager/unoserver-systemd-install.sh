#!/bin/bash

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
EOF

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

# Verificación
echo "Directorio home de unoserver:"
getent passwd unoserver
echo -e "\nEstructura creada:"
ls -lahd /var/lib/unoserver/ /var/cache/unoserver/ /etc/unoserver/

echo -e "\n========== Creando archivos de configuración ==========\n"

# Archivo de variables de entorno
tee /etc/unoserver/env.conf > /dev/null << 'EOF'
# Variables de entorno adicionales para unoserver
UNOSERVER_EXTRA_OPTS="--timeout 300"
EOF

# Archivo de configuración del pool (CORREGIDO: nombre correcto)
tee /etc/unoserver/pools.conf > /dev/null << 'EOF'
# Configuración de Pool Manager para Unoserver
# Define los puertos que deseas usar para las instancias de unoserver
UNOSERVER_PORTS="2003 2004 2005 2006 2007"
EOF

# Ajustar permisos de los archivos de configuración
chown root:unoserver /etc/unoserver/env.conf /etc/unoserver/pools.conf
chmod 640 /etc/unoserver/env.conf /etc/unoserver/pools.conf

echo -e "\n========== Creando wrapper script ==========\n"

# Script wrapper que asigna puertos únicos y carga configuración
tee /usr/local/bin/unoserver-wrapper > /dev/null << 'EOF'
#!/bin/bash

PORT=$1
UNO_PORT=$((PORT + 10000))  # Puerto único para cada instancia

# Cargar configuración si existe
if [ -f /etc/unoserver/env.conf ]; then
    source /etc/unoserver/env.conf
fi

# Configurar entorno
export HOME=/var/lib/unoserver
export XDG_CACHE_HOME=/var/cache/unoserver

exec /usr/local/bin/unoserver \
    --port "$PORT" \
    --uno-port "$UNO_PORT" \
    --interface 127.0.0.1 \
    ${UNOSERVER_EXTRA_OPTS:-}
EOF

chmod 755 /usr/local/bin/unoserver-wrapper

echo -e "\n========== Creando manager script ==========\n"

# Script manager CORREGIDO
tee /usr/local/bin/unoserver-manager > /dev/null << 'ENDOFSCRIPT'
#!/bin/bash

# Archivo de configuración CORREGIDO
CONFIG_FILE="/etc/unoserver/pools.conf"

# Cargar configuración si existe
if [[ -f "$CONFIG_FILE" ]]; then
    source "$CONFIG_FILE"
fi

# Valores por defecto
PORTS=${UNOSERVER_PORTS:-"2003 2004 2005"}
SERVICE_PREFIX="unoserver@"

# Función para requerir sudo si no es root
require_root() {
    if [[ $EUID -ne 0 ]]; then
        echo "Este comando requiere permisos de administrador."
        echo "Ejecutando con sudo..."
        exec sudo "$0" "$@"
    fi
}

manage_service() {
    local action=$1
    local port=$2
    local service_name="${SERVICE_PREFIX}${port}"

    # Verificar estado de la unidad
    local unit_state=$(systemctl is-enabled "$service_name" 2>&1)

    if [[ "$unit_state" == *"bad-setting"* ]] || [[ "$unit_state" == *"not-found"* ]]; then
        echo "⚠️  Unidad $service_name tiene problemas. Intentando corregir..."
        systemctl disable "$service_name" 2>/dev/null
        systemctl reset-failed "$service_name" 2>/dev/null
        systemctl daemon-reload
    fi

    case $action in
        start|stop|restart|enable|disable|status)
            systemctl "$action" "$service_name"
            ;;
        *)
            echo "Acción no válida: $action"
            return 1
            ;;
    esac
}

show_status_all() {
    printf "%-8s %-10s %-12s %s\n" "PUERTO" "ESTADO" "MEMORIA" "ACTIVO"
    echo "-----------------------------------------------------"

    for port in $PORTS; do
        service_name="${SERVICE_PREFIX}${port}"

        # Obtener estado
        status=$(systemctl is-active "$service_name" 2>/dev/null || echo "no existe")
        enabled=$(systemctl is-enabled "$service_name" 2>/dev/null || echo "no configurado")

        # Obtener uso de memoria si está activo
        if [[ "$status" == "active" ]]; then
            memory=$(systemctl show "$service_name" -p MemoryCurrent --value 2>/dev/null |
                     awk '{printf "%.1f MB", $1/1024/1024}')
            [[ -z "$memory" ]] && memory="N/A"
        else
            memory="N/A"
        fi

        printf "%-8s %-10s %-12s %s\n" "$port" "$status" "$memory" "$enabled"
    done
}

# Verificar permisos para comandos que modifican
case $1 in
    start|stop|restart|enable|disable|create|remove)
        require_root "$@"
        ;;
esac

case $1 in
    start|stop|restart|enable|disable|status)
        action=$1
        shift

        if [[ -n "$1" ]]; then
            # Acción sobre puerto específico
            manage_service "$action" "$1"
        else
            # Acción sobre todos los puertos
            for port in $PORTS; do
                echo "[$action] Puerto $port..."
                manage_service "$action" "$port"
            done
        fi
        ;;

    status-all)
        show_status_all
        ;;

    create)
        port=$2
        if [[ -z "$port" ]]; then
            echo "Error: Especifica un puerto"
            echo "Uso: $0 create <puerto>"
            exit 1
        fi

        echo "Creando instancia en puerto $port..."
        systemctl enable "${SERVICE_PREFIX}${port}"
        systemctl start "${SERVICE_PREFIX}${port}"
        ;;

    remove)
        port=$2
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
        port=$2
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

    *)
        echo "Gestión de instancias Unoserver"
        echo ""
        echo "Uso: unoserver-manager <comando> [puerto]"
        echo ""
        echo "Comandos:"
        echo "  start [puerto]     - Iniciar servicio(s)"
        echo "  stop [puerto]      - Detener servicio(s)"
        echo "  restart [puerto]   - Reiniciar servicio(s)"
        echo "  enable [puerto]    - Habilitar inicio automático"
        echo "  disable [puerto]   - Deshabilitar inicio automático"
        echo "  status [puerto]    - Ver estado de servicio(s)"
        echo "  status-all         - Tabla resumen de todos los servicios"
        echo "  create <puerto>    - Crear y activar nueva instancia"
        echo "  remove <puerto>    - Eliminar instancia existente"
        echo "  logs <puerto|all>  - Ver logs en tiempo real"
        echo ""
        echo "Puertos configurados: $PORTS"
        echo "Puedes cambiarlos en $CONFIG_FILE"
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
echo "  unoserver-manager status-all"