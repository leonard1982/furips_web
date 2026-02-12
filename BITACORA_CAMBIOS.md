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

## Convencion sugerida para siguientes cambios

- Registrar fecha (`YYYY-MM-DD`), archivo(s) tocados y motivo del ajuste.
- Anotar impacto en estructura de campos si se modifica `FURIPS1` o `FURIPS2`.
- Si se corrigen posiciones de columnas, incluir numero de campo afectado.
