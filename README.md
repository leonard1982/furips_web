# Furips Generator

Front-end PHP que replica la lógica del `furips2025.jar` sin depender de Java. Lee los datos de Firebird + MySQL y genera los archivos `FURIPS1…` / `FURIPS2…` directamente desde el backend.

## Requisitos

1. PHP 7.3 con la extensión `interbase` habilitada (no utiliza PDO para Firebird).  
2. Firebird 2.5 instalado en Windows, con la biblioteca cliente accesible y el usuario `SYSDBA/masterkey`.  
3. Copia de la base `gestion_documental` (MySQL) para ejecutar las consultas que alimentaban `ConexionSQL`.  
4. `bd.txt` con la ruta completa a la base Firebird actual (`GLOBALSAFE2025.GDB`).  
5. `bd_anterior.txt` con la ruta de la base del año anterior (`GLOBALSAFE2024.GDB`), si se necesitan cruzar rangos históricos.  
6. `mysql.txt` con las credenciales de MySQL (`host`, `port`, `database`, `user`, `password`).  
7. Carpeta `C:\tempo` o ajuste `FURIPS_TEMPO_DIR` para apuntar al directorio que debe contener los archivos resultantes.

## Configuración de archivos

- `bd.txt`: ruta absoluta a la base Firebird actual.  
- `bd_anterior.txt`: ruta absoluta a la base Firebird del año anterior.  
- `mysql.txt`: contiene claves `host`, `port`, `database`, `user` y `password` (línea por línea `clave=valor`).  
- Variables de entorno opcionales:
  - `FURIPS_DB_USER` y `FURIPS_DB_PASSWORD` para cambiar usuario de Firebird.
  - `FURIPS_DB_HOST` o `FURIPS_DB_DSN` para ajustar el DSN completo si el servidor es otro.
  - `FURIPS_TEMPO_DIR` para cambiar la carpeta de trabajo (por defecto `C:\tempo`).
  - `FURIPS_JAR_PATH` queda disponible pero ya no es requerido cuando se usa la implementación PHP.

## Flujo

1. Abre `index.php` en el navegador.  
2. El formulario carga las entidades desde Firebird y permite escribir rango y entidad.  
3. Al generar, el backend escribe el plan, lanza las consultas MySQL/Firebird, construye los FURIPS y los copia a `storage/exports`.  
4. La UI muestra la barra de progreso y ofrece enlaces de descarga (`download.php?jobId=X&file=Y`).  
5. `storage/jobs/<jobId>.json` mantiene el estado y `storage/logs/<jobId>.log` guarda la traza completa.

## Archivos generados

- `globalsafe.txt`: plan actual (`fecha_ini|fecha_fin|entidad|sufijo`).  
- `storage/jobs`: JSON con progreso + resultados.  
- `storage/logs`: log completo de cada ejecución.  
- `storage/exports/<jobId>`: contiene `FURIPS1…`/`FURIPS2…` listos para descargar.  
- `storage/sql/<jobId>.sql`: incluye encabezado con fecha/hora de generacion, entidad (codigo/nombre) y rango (inicio/fin), y luego cada SQL ejecutado indicando `DB:MYSQL` o `DB:FIREBIRD`.  
- La carpeta `C:\tempo` también recibe los `.txt` originales para mantener compatibilidad con procesos que revisen ese directorio.  

## Precauciones

- Si la consulta no devuelve filas, verifica que las bases MySQL/Firebird existan y que los datos estén disponibles para el rango seleccionado (el log en `storage/logs` y los mensajes de error JSON te dirán exactamente qué tabla falta).  
- Para correr pruebas con datos reales, copia las tablas MySQL (`factser`, `polizas`, `usuahosp`, etc.) y la base Firebird antigua en tu entorno y ajusta `mysql.txt`, `bd.txt` y `bd_anterior.txt`.  
- Si más adelante reinstalas el jar, puedes habilitarlo volviendo a añadir `proc_open` en `FuripsJobManager`, pero la versión PHP ya está lista para producción.
