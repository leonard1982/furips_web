# Bitacora de cambios FURIPS

Este archivo registra los cambios funcionales y tecnicos realizados en este proyecto para facilitar continuidad entre desarrolladores o asistentes.

## 2026-02-12

- Se reviso la estructura de `FURIPS1` en `src/FuripsJobManager.php` por reporte de corrimiento de columnas.
- Se corrigio el bloque de campos vacios previo a `PLACA_AMB` en `buildLineOne`:
  - Antes habia 10 vacios y la `PLACA_AMB` quedaba en la posicion 74.
  - Ahora hay 11 vacios (campos 64-74), por lo que `PLACA_AMB` queda en el campo 75.
- Efecto esperado de la correccion:
  - Se elimina el corrimiento de campos desde la mitad del archivo hacia adelante.
  - Desaparece la celda vacia al final de la linea causada por `padToLength`.
- Se amplio el log SQL por ejecucion en `storage/sql/<jobId>.sql`:
  - Se agrega encabezado con fecha/hora de generacion, `job_id`, codigo/nombre de entidad y rango (`fecha inicio`/`fecha fin`).
  - Cada sentencia ahora se marca con tipo de base (`DB:MYSQL` o `DB:FIREBIRD`) y tipo de operacion (`QUERY` o `EXECUTE`).
  - Se expone la ruta del archivo SQL en el estado del job y en la respuesta de `api/generate.php` (`sql_log`).
- Se publico en `main` el estado base hasta este punto en GitHub:
  - Commit: `ad3fc1b`
  - Fecha: `2026-02-12`
- Se creo rama de trabajo/copia para cambios mayores de filtro:
  - Rama: `feature/filtro-firebird-factser-2026-02-12`
  - Base: `main` en commit `ad3fc1b`.
- Cambio funcional mayor aplicado en rama de trabajo:
  - El filtro principal de periodo/entidad se movio a Firebird usando `FACTSER`.
  - Condiciones en Firebird para lista de facturas:
    - `FACTSER.FECHA BETWEEN :inicio AND :fin`
    - `FACTSER.CODCOMP = 'FV'`
    - `ENTIDAD.CODIGO = :codigo_entidad`
    - `FACTSER.FECASENT IS NOT NULL`
    - `FACTSER.FECANULADA IS NULL`
  - La consulta MySQL deja de filtrar por fechas y ahora cruza por `polizas_facturas.nfactura_tns IN (facturas_firebird)`.
- Mejora UX en formulario de generacion:
  - Se agrego un overlay centrado de espera durante la generacion (`Espere por favor` / `Estamos generando los archivos`).
  - El overlay bloquea interacciones del formulario mientras se procesa la solicitud.
  - Se muestra barra de progreso dentro del overlay, sincronizada con la barra de progreso existente.
- Ajuste de codificacion de texto en salida FURIPS:
  - Se fortalecio `removeAccents()` en `src/FuripsJobManager.php` para normalizar cadenas en UTF-8/latin1 y limpiar patrones mojibake comunes.
  - Se agrega transliteracion a ASCII para evitar caracteres corruptos en nombres (ejemplo reportado: `PEÏ¿½ARANDA`).

### Cierre de promocion a main (2026-02-12)

- Se promovieron a `main` los cambios trabajados en la rama de copia con mensajes en espanol:
  - Commit `90e4814`: `Mover filtrado principal de FURIPS a Firebird (FACTSER)`.
  - Commit `40ca88e`: `Mejorar interfaz de carga y normalizar codificacion de texto FURIPS`.
- Estado final:
  - `main` queda actualizado y publicado en GitHub (`origin/main`).
  - La rama `feature/filtro-firebird-factser-2026-02-12` se conserva como referencia historica.

### Ajuste de cruce Firebird/MySQL (2026-02-15)

- Se detecto diferencia entre facturas filtradas en Firebird y registros finales en MySQL.
- Causa: en `buildFuripsQuery` se mantenia el filtro `pf.facturado = 'SI'`, lo que recortaba la lista de facturas devuelta por Firebird.
- Correccion aplicada:
  - Se elimina el filtro `pf.facturado = 'SI'`.
  - El cruce en MySQL queda solo por `pf.nfactura_tns IN (facturas_firebird)`.
- Resultado esperado:
  - Si Firebird devuelve N facturas y existen en `polizas_facturas` por `nfactura_tns`, el generador debe procesar ese mismo total sin recorte por estado de facturacion.
- Ajuste adicional de conteo y generacion:
  - `CANTIDADFURIPS` ahora se actualiza con el total de facturas encontradas en Firebird (no con el total de filas de MySQL).
  - El proceso recorre la lista de facturas de Firebird y genera una linea por cada factura.
  - Si una factura no trae relacion completa en MySQL, se genera igual el FURIPS con campos MySQL en blanco.

## Convencion sugerida para siguientes cambios

- Registrar fecha (`YYYY-MM-DD`), archivo(s) tocados y motivo del ajuste.
- Anotar impacto en estructura de campos si se modifica `FURIPS1` o `FURIPS2`.
- Si se corrigen posiciones de columnas, incluir numero de campo afectado.
