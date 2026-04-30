## Scripts para generar pool de conexiones


1.Copiar `unoserver@.service` a `/etc/systemd/system/ `

2.Crear el directorio `/etc/unoserver` y copiar los archivos `unoserver.conf` y `env.conf` allí, ajustando los parámetros según tus necesidades (puertos, número de instancias, etc.)

3.Copiar `unoserver-pool` en `/usr/local/bin/` y ajustar los permisos de ejecución.
```bash
sudo chmod +x /usr/local/bin/unoserver-pool
```
4.Crear el usuario dedicado (opcional pero recomendado para el template avanzado)
```bash
sudo useradd -r -s /bin/false -d /nonexistent unoserver
```
5.El script ```/usr/local/bin/unoserver-pool``` permite agregar/quitar puertos dinámicamente con ```create```/```remove```, o iniciar el pool completo con ```start```. Ejemplo para iniciar el pool:
```bash
sudo unoserver-pool enable #Activa en systemd todos los servicios de Unoserver configurados en /etc/unoserver/unoserver.conf
sudo unoserver-pool start #Inicia el pool
```
>Nota: El script asume que cada instancia de Unoserver se ejecuta con el template `unoserver@.service` y que los puertos están configurados en `/etc/unoserver/unoserver.conf`. Asegúrate de que estos archivos estén correctamente configurados para que el pool funcione correctamente.
6.Verificar el estado del pool con:
```bash
sudo unoserver-pool show_status_all
```
7.Agregar o quitar puertos dinámicamente:
```bash
sudo unoserver-pool create 2006 #Crea el puerto 2006, lo habilita en systemd y lo inicia
sudo unoserver-pool remove 2006 #Deshabilita el puerto 2006 y lo elimina del pool
```
