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
sudo unoserver-pool start
```
6.Verificar el estado del pool con:
```bash
sudo systemctl status unoserver@2003
sudo systemctl status unoserver@2004
sudo systemctl status unoserver@2005
```
o
```bash
sudo unoserver-pool show_status_all
```
7.Agregar o quitar puertos dinámicamente:
```bash
sudo unoserver-pool create 2006
sudo unoserver-pool remove 2006
```
