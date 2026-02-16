<?php

declare(strict_types=1);

require_once __DIR__ . '/FirebirdConnection.php';
require_once __DIR__ . '/SqlLogger.php';
require_once __DIR__ . '/MysqlConnection.php';

final class FuripsJobManager
{
    private $config;
    private $tempoDir;
    private $planFile;
    private $jobDir;
    private $logDir;
    private $exportDir;
    private $sqlDir;
    private $mysqlConnection;
    /** @var SqlLogger|null */
    private $sqlLogger;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->tempoDir = $config['tempo']['dir'];
        $this->planFile = $config['tempo']['plan_file'];
        $this->jobDir = $config['storage']['jobs'];
        $this->logDir = $config['storage']['logs'];
        $this->exportDir = $config['storage']['exports'];
        $this->sqlDir = $config['storage']['sql'];
        $this->mysqlConnection = new MysqlConnection($config['mysql']);

        $this->ensureDirectory($this->tempoDir);
        $this->ensureDirectory(dirname($this->planFile));
        $this->ensureDirectory($this->jobDir);
        $this->ensureDirectory($this->logDir);
        $this->ensureDirectory($this->sqlDir);
        $this->ensureDirectory($this->exportDir);
        $this->purgeOldTempoFiles(30);
    }

    public function run(string $startDate, string $endDate, string $entityCode): array
    {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);

        if ($start > $end) {
            throw new InvalidArgumentException('La fecha inicial no puede ser mayor que la fecha final.');
        }

        $entityCode = trim($entityCode);
        if ($entityCode === '') {
            throw new InvalidArgumentException('Debe seleccionar una entidad.');
        }

        $jobId = bin2hex(random_bytes(8));
        $suffix = $this->buildSuffix();
        $planContent = $start . '|' . $end . '|' . $entityCode . '|' . $suffix . PHP_EOL;
        $entityName = $this->resolveEntityName($entityCode);

        $this->sqlLogger = new SqlLogger($this->sqlDir, $jobId);
        $this->sqlLogger->writeHeader($jobId, $entityCode, $entityName, $start, $end);
        $sqlLogPath = $this->sqlLogger->getPath();
        $this->mysqlConnection->setLogger($this->sqlLogger);

        file_put_contents($this->planFile, $planContent);

        $this->saveJobState($jobId, [
            'job_id' => $jobId,
            'start_date' => $start,
            'end_date' => $end,
            'entity_code' => $entityCode,
            'entity_name' => $entityName,
            'suffix' => $suffix,
            'sql_log' => $sqlLogPath,
            'plan' => [
                'path' => $this->planFile,
                'content' => trim($planContent),
            ],
            'status' => 'plan-ready',
            'progress' => 20,
            'message' => 'Plan listo para generar FURIPS.',
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $logFile = $this->logDir . DIRECTORY_SEPARATOR . $jobId . '.log';
        $logHandle = fopen($logFile, 'w');
        if ($logHandle === false) {
            throw new RuntimeException('No se pudo crear el archivo de log.');
        }

        fwrite($logHandle, "Plan generado: " . trim($planContent) . PHP_EOL);

        $this->saveJobState($jobId, [
            'status' => 'running',
            'progress' => 40,
            'message' => 'Consultando Firebird y generando archivos.',
            'log' => $logFile,
        ]);

        try {
            $files = $this->generateFurips($start, $end, $entityCode, $suffix, $logHandle);
            fwrite($logHandle, "Proceso completo." . PHP_EOL);
        } catch (\Throwable $exception) {
            fwrite($logHandle, 'Proceso abortado: ' . $exception->getMessage() . PHP_EOL);
            fclose($logHandle);
            throw $exception;
        } finally {
            $this->mysqlConnection->setLogger(null);
            $this->sqlLogger = null;
        }

        fclose($logHandle);

        $this->saveJobState($jobId, [
            'progress' => 70,
            'message' => 'Archivos generados.',
        ]);

        $outputs = $this->collectOutputs($suffix, $jobId);

        $this->saveJobState($jobId, [
            'status' => 'completed',
            'progress' => 100,
            'message' => 'Furips generados.',
            'outputs' => $outputs,
            'finished_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return [
            'job_id' => $jobId,
            'plan' => trim($planContent),
            'outputs' => $outputs,
            'log' => $logFile,
            'sql_log' => $sqlLogPath,
        ];
    }

    private function generateFurips(string $start, string $end, string $entityCode, string $suffix, $logHandle): array
    {
        $connection = new FirebirdConnection($this->config['firebird']);
        if ($this->sqlLogger !== null) {
            $connection->setLogger($this->sqlLogger);
        }

        try {
            $facturas = $this->fetchFacturasFromFirebird($connection, $start, $end, $entityCode);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'No se pudo consultar Firebird para obtener facturas por rango/entidad. Detalle: ' . $exception->getMessage()
            );
        }

        if ($facturas === []) {
            $this->updateVarios($connection, 'CANTIDADFURIPS', 0);
            fwrite($logHandle, "Sin facturas en Firebird para el rango/entidad solicitados.\n");
            throw new RuntimeException('No se encontraron facturas en Firebird para el rango y entidad seleccionados.');
        }

        try {
            $rows = $this->mysqlConnection->query($this->buildFuripsQuery($facturas));
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                'No se pudo leer la tabla FURIPS (MySQL). Verifique que la base gestion_documental este replicada y ' .
                'que las tablas necesarias existan. Detalle: ' . $exception->getMessage()
            );
        }

        $rowsByFactura = [];
        foreach ($rows as $mysqlRow) {
            $mysqlRow = array_change_key_case($mysqlRow, CASE_UPPER);
            $factura = trim((string) ($mysqlRow['NFACTURA_TNS'] ?? ''));
            if ($factura === '') {
                continue;
            }
            if (!isset($rowsByFactura[$factura])) {
                $rowsByFactura[$factura] = $mysqlRow;
            }
        }

        $total = count($facturas);
        $encontradasMysql = count($rowsByFactura);
        $faltantesMysql = $total - $encontradasMysql;

        $this->updateVarios($connection, 'CANTIDADFURIPS', $total);
        fwrite(
            $logHandle,
            sprintf(
                "Facturas Firebird: %d; con datos MySQL: %d; faltantes MySQL (se generan en blanco): %d",
                $total,
                $encontradasMysql,
                max(0, $faltantesMysql)
            ) . PHP_EOL
        );

        $file1 = $this->tempoDir . DIRECTORY_SEPARATOR . 'FURIPS1' . $suffix . '.txt';
        $file2 = $this->tempoDir . DIRECTORY_SEPARATOR . 'FURIPS2' . $suffix . '.txt';
        $handle1 = fopen($file1, 'w');
        $handle2 = fopen($file2, 'w');

        foreach ($facturas as $offset => $factura) {
            $row = $rowsByFactura[$factura] ?? [];
            $row['NFACTURA_TNS'] = $factura;
            if (($row['CODIGO_ASEGURADORA'] ?? '') === '') {
                $row['CODIGO_ASEGURADORA'] = $entityCode;
            }

            $row['_CLINICAL'] = $this->fetchClinicalData(
                $factura,
                $row['CEDULA'] ?? ''
            );

            $count = $offset + 1;
            $this->updateVarios($connection, 'CANTIDADSUBIDA', $count);
            fwrite($logHandle, sprintf("Procesando registro %d/%d (%s)", $count, $total, $factura) . PHP_EOL);

            $line1 = $this->buildLineOne($row, $connection);
            $line2 = $this->buildLineTwo($row);

            $this->assertColumnCount($line1, 102, 'FURIPS1', $factura);
            $this->assertColumnCount($line2, 9, 'FURIPS2', $factura);

            fwrite($handle1, $line1 . "\r\n");
            fwrite($handle2, $line2 . "\r\n");
        }

        fclose($handle1);
        fclose($handle2);

        return [$file1, $file2];
    }


    private function buildLineOne(array $row, FirebirdConnection $connection): string
    {
        $clinical = $row['_CLINICAL'] ?? [];
        $fromGlosas = $this->retrieveGlosas($connection, $row['NFACTURA_TNS'] ?? '');
        $estado = $this->normalizeEstado($row['ESTADO_ASEGURAMIENTO'] ?? '');
        $tipoServicio = $estado === '3' ? '' : ($row['TIPO_SERVICIO'] ?? '');
        $marca = $estado === '3' ? '' : ($row['MARCA'] ?? '');
        $soat = in_array($estado, ['1', '4', '6'], true) ? $this->formatSoat($row) : ['', ''];
        $numPoliza = in_array($estado, ['1', '4', '6'], true) ? ($row['NUMERO_POLIZA'] ?? '') : '';

        $victima = [
            'ap1' => $clinical['APELL1'] ?? '',
            'ap2' => $clinical['APELL2'] ?? '',
            'nom1' => $clinical['NOMBRE1'] ?? '',
            'nom2' => $clinical['NOMBRE2'] ?? '',
            'tipodoc' => $this->normalizeDocType($clinical['TIPODOC'] ?? ($row['TIPODOC_PROPIETARIO'] ?? '')),
            'doc' => $clinical['CEDULA'] ?? ($row['CEDULA'] ?? ''),
            'fecha_nac' => $clinical['FECHANAC'] ?? $this->formatBirthDate($row['FECHANAC'] ?? ''),
            'sexo' => $clinical['SEXO'] ?? ($row['SEXO'] ?? ''),
            'dir' => $clinical['DIRECCION'] ?? ($row['DIRECCION_PROPIETARIO'] ?? ''),
            'tel' => $clinical['TELEFONO'] ?? ($row['TELEFONO_PROPIETARIO'] ?? ''),
            'dep' => $clinical['DEP'] ?? ($row['DEPARTAMENTO_PROPIETARIO'] ?? ($row['COD_DEPTO'] ?? '')),
            'mun' => $clinical['MUN'] ?? ($row['MUNICIPIO_PROPIETARIO'] ?? substr($row['COD_MUNICIPIO'] ?? '', 2, 3)),
        ];

        $prop = [
            'ap1' => $row['APELLIDO1_PROPIETARIO'] ?? '',
            'ap2' => $row['APELLIDO2_PROPIETARIO'] ?? '',
            'nom1' => $row['NOMBRE1_PROPIETARIO'] ?? '',
            'nom2' => $row['NOMBRE2_PROPIETARIO'] ?? '',
            'tipodoc' => $this->normalizeDocType($row['TIPODOC_PROPIETARIO'] ?? ''),
            'doc' => $row['N_DOCUMENTO_PROPIETARIO'] ?? '',
            'dir' => $row['DIRECCION_PROPIETARIO'] ?? '',
            'tel' => $row['TELEFONO_PROPIETARIO'] ?? '',
            'dep' => $row['DEPARTAMENTO_PROPIETARIO'] ?? ($row['COD_DEPTO'] ?? ''),
            'mun' => $row['MUNICIPIO_PROPIETARIO'] ?? substr($row['COD_MUNICIPIO'] ?? '', 2, 3),
        ];
        if (strtoupper($row['VICTIMA_PROPIETARIO'] ?? '') === 'SI') {
            $prop = $victima;
        }

        $cond = [
            'ap1' => $row['APELLIDO1_CONDUCTOR'] ?? '',
            'ap2' => $row['APELLIDO2_CONDUCTOR'] ?? '',
            'nom1' => $row['NOMBRE1_CONDUCTOR'] ?? '',
            'nom2' => $row['NOMBRE2_CONDUCTOR'] ?? '',
            'tipodoc' => $this->normalizeDocType($row['TIPODOC_CONDUCTOR'] ?? ''),
            'doc' => $row['N_DOCUMENTO_CONDUCTOR'] ?? '',
            'dir' => $row['DIRECCION_CONDUCTOR'] ?? '',
            'tel' => $row['TELEFONO_CONDUCTOR'] ?? '',
            'dep' => $row['DEPARTAMENTO_CONDUCTOR'] ?? ($row['COD_DEPTO'] ?? ''),
            'mun' => $row['MUNICIPIO_CONDUCTOR'] ?? substr($row['COD_MUNICIPIO'] ?? '', 2, 3),
        ];
        if (strtoupper($row['VICTIMA_CONDUCTOR'] ?? '') === 'SI') {
            $cond = $victima;
        }

        $depAccidente = $row['COD_DEPTO'] ?? '';
        $munAccidente = substr($row['COD_MUNICIPIO'] ?? '', 2, 3);

        $codDiag = $clinical['COD_DIAG'] ?? ($row['COD_DIAGNOSTICO'] ?? '');
        $diagSec = $clinical['COD_DIAG_SEC'] ?? $codDiag;

        $fechaAccidente = $this->formatDate($row['FECHA_ACCIDENTE'] ?? '');
        $horaAccidente = substr($row['HORA_ACCIDENTE'] ?? '', 0, 5);
        $fechaIngreso = $clinical['FECHA_ING'] ?? $this->formatDate($row['FECHASER'] ?? '');
        $horaIngreso = $clinical['HORA_ING'] ?? substr($row['HORASER'] ?? '', 0, 5);
        $fechaEgreso = $clinical['FECHA_EGR'] ?? $fechaIngreso;
        $horaEgreso = $clinical['HORA_EGR'] ?? $this->calculateHour($horaIngreso);

        $ap1Med = $this->extractWord($clinical['APELLIDOS_MEDICO'] ?? '', 0);
        $ap2Med = $this->extractWord($clinical['APELLIDOS_MEDICO'] ?? '', 1);
        $nom1Med = $this->extractWord($clinical['NOMBRE_MEDICO'] ?? '', 0);
        $nom2Med = $this->extractWord($clinical['NOMBRE_MEDICO'] ?? '', 1);
        $docMed = $clinical['DOC_MEDICO'] ?? '';
        $regMed = $clinical['REG_MEDICO'] ?? '';
        $tipodocMed = $this->normalizeDocType($clinical['TIPODOC_MEDICO'] ?? 'CC');

        $totalFacturado = $clinical['TOTAL'] ?? ($row['TOTAL_FACTURADO'] ?? $row['TOTAL'] ?? '0');

        $fields = [
            $fromGlosas['numero'],                          // 1 num_glosa
            $fromGlosas['respuesta'],                       // 2 resp_glosa
            $row['NFACTURA_TNS'] ?? '',                     // 3 nfactura
            substr($row['NFACTURA_TNS'] ?? '', 2, 6),       // 4 consecutivo
            '540010227201',                                 // 5 nit
            $victima['ap1'],                                // 6 ap1_victima
            $victima['ap2'],                                // 7 ap2_victima
            $victima['nom1'],                               // 8 nom1_victima
            $victima['nom2'],                               // 9 nom2_victima
            $victima['tipodoc'],                            // 10 tipodoc_victima
            $victima['doc'],                                // 11 doc_victima
            $victima['fecha_nac'],                          // 12 fecha_nac
            '',                                             // 13 vacio
            $victima['sexo'],                               // 14 sexo
            str_replace(',', '', $victima['dir']),          // 15 dir_prop
            $victima['dep'],                                // 16 dep_prop
            $victima['mun'],                                // 17 mun_prop
            $victima['tel'],                                // 18 tel_prop
            $this->normalizeCondicion($row['CONDICION_ACCIDENTADO'] ?? ''), // 19 condicion
            '01',                                           // 20 naturaleza
            '',                                             // 21 otra_nat
            str_replace(',', '', $row['DIRECCION_OCURRENCIA'] ?? ''), // 22 dir_ocurr
            $fechaAccidente,                                // 23 fecha_acc
            $horaAccidente,                                 // 24 hora_acc
            $depAccidente,                                  // 25 dep_acc
            $munAccidente,                                  // 26 mun_acc
            $row['ZONA'] ?? '',                             // 27 zona
            $estado,                                        // 28 estado_aseg
            $marca,                                         // 29 marca
            $row['PLACA'] ?? '',                            // 30 placa
            $tipoServicio,                                  // 31 tipo_serv
            $estado === '3' ? '' : ($row['CODIGO_ASEGURADORA'] ?? ''), // 32 cod_aseg
            $numPoliza,                                     // 33 num_poliza
            $soat[0],                                       // 34 vig_desde
            $soat[1],                                       // 35 vig_hasta
            $row['CODIFICACION_SIRAS'] ?? '',               // 36 cod_siras
            ($row['COBRO_EXCEDENTE'] ?? '') === 'SI' ? '1' : '0', // 37 cobro_exc
            $codDiag,                                       // 38 diag
            '', '', '', '', '',                             // 39-43 vacios
            $cond['tipodoc'],                               // 44 tipodoc_cond
            $cond['doc'],                                   // 45 doc_cond
            $cond['ap1'],                                   // 46 ap1_cond
            $cond['ap2'],                                   // 47 ap2_cond
            $cond['nom1'],                                  // 48 nom1_cond
            $cond['nom2'],                                  // 49 nom2_cond
            str_replace(',', '', $cond['dir']),             // 50 dir_cond
            $cond['tel'],                                   // 51 tel_cond
            $cond['dep'],                                   // 52 dep_cond
            $cond['mun'],                                   // 53 mun_cond
            $prop['ap1'],                                   // 54 ap1_prop
            $prop['ap2'],                                   // 55 ap2_prop
            $prop['nom1'],                                  // 56 nom1_prop
            $prop['nom2'],                                  // 57 nom2_prop
            $prop['tipodoc'],                               // 58 tipodoc_prop
            $prop['doc'],                                   // 59 doc_prop
            str_replace(',', '', $prop['dir']),             // 60 dir_prop
            $prop['dep'],                                   // 61 dep_prop
            $prop['mun'],                                   // 62 mun_prop
            $prop['tel'],                                   // 63 tel_prop
            '', '', '', '', '', '', '', '', '', '', '',     // 64-74 vacios
            $row['PLACA_AMB'] ?? '',                        // 75 placa_amb
            $this->truncate($row['DESDE'] ?? '', 40),       // 76 desde
            $this->truncate($row['HASTA'] ?? '', 40),       // 77 hasta
            '1',                                            // 78 ambulancia_med
            $row['ZONA_TRASLADOS'] ?? 'U',                  // 79 zona_traslados
            $fechaIngreso,                                  // 80 fecha_ingreso
            $horaIngreso,                                   // 81 hora_ingreso
            $fechaEgreso,                                   // 82 fecha_egreso
            $horaEgreso,                                    // 83 hora_egreso
            $codDiag,                                       // 84 diag_ppal
            '', '',                                         // 85-86 vacios
            $diagSec,                                       // 87 diag_sec
            '', '',                                         // 88-89 vacios
            $ap1Med,                                        // 90 ap1_med
            $ap2Med,                                        // 91 ap2_med
            $nom1Med,                                       // 92 nom1_med
            $nom2Med,                                       // 93 nom2_med
            $tipodocMed,                                    // 94 tipodoc_med
            $docMed,                                        // 95 doc_med
            $regMed,                                        // 96 reg_med
            $totalFacturado,                                // 97 total_facturado
            $totalFacturado,                                // 98 total_recobro
            '0',                                            // 99 copago
            $totalFacturado,                                // 100 deducible
            '1',                                            // 101 firma
            $row['DESCRIPCION_ACCIDENTE'] ?? '',            // 102 descripcion_accidente
        ];

        $fields = array_map([$this, 'sanitizeField'], $fields);
        $fields = $this->padToLength($fields, 102);
        return implode(',', $fields);
    }

    private function buildLineTwo(array $row): string
    {
        $clinical = $row['_CLINICAL'] ?? [];
        [$codigoServicio, $descripcionServicio] = $this->splitService($clinical['SERVICIO'] ?? ($row['TIPO_SERVICIO'] ?? ''));
        $cantidad = $clinical['TOTTEP'] ?? 1;
        if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
            $cantidad = 1;
        }
        $total = $clinical['TOTAL'] ?? ($row['TOTAL_FACTURADO'] ?? $row['TOTAL'] ?? '0');
        $valorUnitario = $clinical['VALOR_DET'] ?? $total;
        if (!is_numeric($valorUnitario) || $valorUnitario === '') {
            $valorUnitario = $total;
        }

        $fields = [
            $row['NFACTURA_TNS'] ?? '',
            substr($row['NFACTURA_TNS'] ?? '', 2, 6),
            '2',
            $codigoServicio,
            $descripcionServicio,
            $cantidad,
            $valorUnitario,
            $total,
            $total,
        ];

        $fields = array_map([$this, 'sanitizeField'], $fields);
        $fields = $this->padToLength($fields, 9);
        return implode(',', $fields);
    }

    private function fetchClinicalData(string $factura, string $cedula): array
    {
        $factura = trim($factura);
        $cedula = trim($cedula);
        if ($factura === '' && $cedula === '') {
            return [];
        }

        $configs = [
            $this->config['firebird'] ?? null,
            $this->config['firebird_previous'] ?? null,
        ];

        foreach ($configs as $config) {
            if ($config === null) {
                continue;
            }
            try {
                $connection = new FirebirdConnection($config);
                if ($this->sqlLogger !== null) {
                    $connection->setLogger($this->sqlLogger);
                }
                $clinical = $this->queryClinicalData($connection, $factura, $cedula);
                if ($clinical !== null) {
                    return $clinical;
                }
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return [];
    }

    private function queryClinicalData(FirebirdConnection $connection, string $factura, string $cedula): ?array
    {
        if ($factura === '') {
            return null;
        }
        $facturaSql = str_replace("'", "''", $factura);
        $sql = <<<SQL
select
    f.codprefijo||f.numero as numero_tns,
    u.fechanac,
    u.tipodoc,
    u.sexo,
    u.telefono,
    u.direccion,
    u.apell1,
    u.apell2,
    u.nombre1,
    u.nombre2,
    m.codigo as cod_mpio,
    f.hora as horaser,
    f.fechaing,
    f.fechaegreso,
    p.nombre as nombre_medico,
    p.apellidos as apellidos_medico,
    p.cedula as doc_medico,
    p.regprof,
    s.codigo||'-'||s.descripcion as servicioprestado,
    cast(f.total as int) as total,
    SUM(df.cantidad) AS tottep,
    (f.total/SUM(df.cantidad)) as valor_det
from factser f
inner join usuaxcon us on us.usuaxconid=f.usuaxconid
inner join contrato c on c.contaid=us.contaid
inner join entidad e on e.entid=c.entid
inner join defactser df on df.factserid=f.factserid
inner join servicio s on s.servicioid=df.servicioid
inner join usuahosp u on u.usuahosid = us.usuahosid
inner join municipio m on m.munid=u.munid
inner join profesional p on p.profid=df.profrem
inner join departamento de on de.depaid=m.depaid
where f.codprefijo||f.numero = '{$facturaSql}'
group by f.codprefijo, f.numero, u.fechanac, u.tipodoc, u.sexo, u.telefono, u.direccion, u.apell1, u.apell2, u.nombre1, u.nombre2, m.codigo, f.hora, f.fechaing, f.fechaegreso, p.nombre, p.apellidos, p.cedula, p.regprof, s.codigo, s.descripcion, f.total
SQL;
        $results = $connection->query($sql);
        if (empty($results)) {
            return null;
        }

        $row = $results[0];
        $diag = $this->findDiagnostico($connection, $facturaSql);

        $codMpio = $row['COD_MPIO'] ?? '';
        $dep = substr($codMpio, 0, 2);
        $mun = substr($codMpio, -3);

        $horaIngreso = substr($row['HORASER'] ?? '', 0, 5);
        $fechaIngreso = $this->formatDate($row['FECHAING'] ?? '');
        $fechaEgreso = $this->formatDate($row['FECHAEGRESO'] ?? '');
        $horaEgreso = $this->calculateHour($horaIngreso);

        return [
            'APELL1' => $row['APELL1'] ?? '',
            'APELL2' => $row['APELL2'] ?? '',
            'NOMBRE1' => $row['NOMBRE1'] ?? '',
            'NOMBRE2' => $row['NOMBRE2'] ?? '',
            'TIPODOC' => $row['TIPODOC'] ?? '',
            'CEDULA' => $cedula,
            'FECHANAC' => $this->formatDate($row['FECHANAC'] ?? ''),
            'SEXO' => $row['SEXO'] ?? '',
            'TELEFONO' => $row['TELEFONO'] ?? '',
            'DIRECCION' => $row['DIRECCION'] ?? '',
            'DEP' => $dep,
            'MUN' => $mun,
            'HORA_ING' => $horaIngreso,
            'FECHA_ING' => $fechaIngreso,
            'HORA_EGR' => $horaEgreso,
            'FECHA_EGR' => $fechaEgreso !== '' ? $fechaEgreso : $fechaIngreso,
            'APELLIDOS_MEDICO' => $row['APELLIDOS_MEDICO'] ?? '',
            'NOMBRE_MEDICO' => $row['NOMBRE_MEDICO'] ?? '',
            'DOC_MEDICO' => $row['DOC_MEDICO'] ?? '',
            'REG_MEDICO' => $row['REGPROF'] ?? '',
            'SERVICIO' => $row['SERVICIOPRESTADO'] ?? '',
            'TOTAL' => $row['TOTAL'] ?? '0',
            'TOTTEP' => $row['TOTTEP'] ?? ($row['TOTTep'] ?? '1'),
            'VALOR_DET' => $row['VALOR_DET'] ?? ($row['VALOR_det'] ?? ''),
            'COD_DIAG' => $diag,
        ];
    }

    private function findDiagnostico(FirebirdConnection $connection, string $factura): string
    {
        $factura = str_replace("'", "''", $factura);
        $queries = [
            "select d.codigo from diagnostico d inner join ripproc r on r.diagp=d.diagid inner join defactser df on df.autorizacion=r.codcomp||r.codprefijo||r.numfact inner join factser f on f.factserid=df.factserid where f.codprefijo||f.numero='{$factura}'",
            "select d.codigo from diagnostico d inner join ripconsul r on r.diagp=d.diagid inner join defactser df on df.autorizacion=r.codcomp||r.codprefijo||r.numfact inner join factser f on f.factserid=df.factserid where f.codprefijo||f.numero='{$factura}'",
        ];

        foreach ($queries as $sql) {
            $rows = $connection->query($sql);
            if (!empty($rows) && !empty($rows[0]['CODIGO'])) {
                return $rows[0]['CODIGO'];
            }
        }

        return '';
    }

    private function updateVarios(FirebirdConnection $connection, string $variable, int $value): void
    {
        $sql = sprintf("update varios set contenido='%d' where variab='%s'", $value, $variable);
        $connection->execute($sql);
    }

    private function assertColumnCount(string $line, int $expected, string $label, string $factura): void
    {
        $count = count(str_getcsv($line));
        if ($count !== $expected) {
            throw new RuntimeException(sprintf('%s debe tener %d columnas (factura %s, obtuvo %d).', $label, $expected, $factura, $count));
        }
    }

    private function normalizeDocType(?string $value): string
    {
        $doc = substr(trim((string) ($value ?? '')), 0, 2);
        return $doc === 'PPT' ? 'PT' : $doc;
    }

    private function composeVictima(array $row): string
    {
        $parts = array_filter([
            $row['APELLIDO1_PROPIETARIO'] ?? '',
            $row['APELLIDO2_PROPIETARIO'] ?? '',
            $row['NOMBRE1_PROPIETARIO'] ?? '',
            $row['NOMBRE2_PROPIETARIO'] ?? '',
        ], static function ($value) {
            return $value !== '';
        });

        return implode(',', $parts);
    }

    private function normalizeCondicion(?string $value): string
    {
        if ($value === null) {
            return '1';
        }
        $upper = strtoupper($value);
        switch ($upper) {
            case 'CONDUCTOR':
                return '1';
            case 'PEATON':
                return '2';
            case 'OCUPANTE':
                return '3';
            case 'CICLISTA':
                return '4';
            default:
                return '1';
        }
    }

    private function normalizeEstado(?string $value): string
    {
        if ($value === null) {
            return '1';
        }
        $upper = strtoupper($value);
        switch ($upper) {
            case 'ASEGURADO':
                return '1';
            case 'NO ASEGURADO':
                return '2';
            case 'VEHICULO FANTASMA':
            case 'VEHICULO EN FUGA':
                return '3';
            case 'POLIZA FALSA':
                return '4';
            case 'ASEGURADO D.2497':
                return '6';
            case 'NO ASEGURADO - PROPIETARIO INDETERMINADO':
                return '7';
            case 'NO ASEGURADO - SIN PLACA':
                return '8';
            default:
                return '1';
        }
    }

    private function formatDate(?string $value): string
    {
        if (empty($value)) return '';
        $parts = explode('-', substr($value, 0, 10));
        if (count($parts) === 3) {
            return sprintf('%02d/%02d/%02d', $parts[2], $parts[1], $parts[0]);
        }
        return '';
    }

    private function formatBirthDate(?string $value): string
    {
        return $this->formatDate($value);
    }

    private function calculateHour(?string $hora): string
    {
        if (empty($hora)) {
            return '00:00';
        }
        $parts = explode(':', substr($hora, 0, 5));
        $hours = intval($parts[0] ?? 0);
        $minutes = intval($parts[1] ?? 0);
        if ($minutes > 29) {
            $minutes -= 30;
            $hours++;
            if ($hours > 23) {
                $hours = 0;
            }
        } else {
            $minutes += 30;
        }
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private function splitService($value): array
    {
        $parts = explode('-', (string) $value, 2);
        return [
            trim($parts[0] ?? ''),
            trim($parts[1] ?? ''),
        ];
    }

    private function formatSoat(array $row): array
    {
        $inicio = $this->formatDate($row['VIGENCIA_POLIZA_DESDE'] ?? '');
        $fin = $this->formatDate($row['VIGENCIA_POLIZA_HASTA'] ?? '');
        return [$inicio, $fin];
    }

    private function formatDates(array $row): array
    {
        return $this->formatSoat($row);
    }

    private function retrieveGlosas(FirebirdConnection $connection, ?string $factura): array
    {
        if (empty($factura)) {
            return ['numero' => '', 'respuesta' => ''];
        }
        $results = $connection->query("select numero from glosas where fv = 'FV{$factura}'");
        if (!empty($results[0]['NUMERO'])) {
            $parts = explode('|', $results[0]['NUMERO']);
            return ['numero' => $parts[0] ?? '', 'respuesta' => $parts[1] ?? ''];
        }
        return ['numero' => '', 'respuesta' => ''];
    }

    private function truncate(string $value, int $length): string
    {
        if ($length <= 0) {
            return $value;
        }
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function extractFirstName(string $fullname): string
    {
        $parts = preg_split('/\s+/', trim($fullname));
        return $parts[0] ?? '';
    }

    private function extractWord(string $text, int $index): string
    {
        if ($index < 0) {
            return '';
        }
        $parts = array_values(array_filter(preg_split('/\s+/', trim($text)) ?: []));
        return $parts[$index] ?? '';
    }

    private function sanitizeField($value): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        $text = str_replace([',', "\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $text = $this->removeAccents($text);
        return strtoupper($text);
    }

    private function removeAccents(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Si llega texto en latin1/ansi, lo normaliza a UTF-8 antes de limpiar.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = utf8_encode($value);
        }

        $map = [
            "\u{00C1}" => 'A', "\u{00C9}" => 'E', "\u{00CD}" => 'I', "\u{00D3}" => 'O', "\u{00DA}" => 'U',
            "\u{00E1}" => 'A', "\u{00E9}" => 'E', "\u{00ED}" => 'I', "\u{00F3}" => 'O', "\u{00FA}" => 'U',
            "\u{00D1}" => 'N', "\u{00F1}" => 'N', "\u{00DC}" => 'U', "\u{00FC}" => 'U',
            // Patrones mojibake frecuentes en datos legacy.
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U',
            'Ñ' => 'N', 'ñ' => 'N', 'Ü' => 'U', 'ü' => 'U',
            '�' => 'N', 'Ͽ�' => 'N', "\u{FFFD}" => 'N',
        ];

        $value = strtr($value, $map);

        // Fallback para transliterar cualquier acento residual a ASCII.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false && $ascii !== '') {
            $value = $ascii;
        }

        return $value;
    }

    /**
     * Rellena con campos vacíos hasta alcanzar la longitud requerida.
     */
    private function padToLength(array $fields, int $length): array
    {
        if (count($fields) >= $length) {
            return array_slice($fields, 0, $length);
        }
        return array_pad($fields, $length, '');
    }

    private function buildFuripsQuery(array $facturas): string
    {
        $facturas = $this->normalizeFacturas($facturas);
        if ($facturas === []) {
            return 'select 1 where 1=0';
        }

        $invoicesSql = implode(', ', array_map(static function (string $value): string {
            return "'" . str_replace("'", "''", $value) . "'";
        }, $facturas));

        return <<<SQL
select f.id, f.cedula, f.condicion_accidentado, f.direccion_ocurrencia, f.fecha_accidente, f.hora_accidente, f.departamento, f.municipio, f.zona, f.estado_aseguramiento, mm.descripcion as marca, f.placa, f.tipo_servicio, a.codigo_tns as codigo_aseguradora, p.numero_poliza, f.vigencia_poliza_desde, f.vigencia_poliza_hasta, pf.codificacion_siras, f.cobro_excedente, f.cod_diagnostico, td.codigo as tipodoc_propietario, f.n_documento_propietario, f.apellido1_propietario, f.apellido2_propietario, f.nombre1_propietario, f.nombre2_propietario, f.nombre1_conductor, f.direccion_propietario, f.telefono_propietario, f.departamento_propietario, f.municipio_propietario, f.apellido1_conductor, f.apellido2_conductor, f.nombre1_conductor, f.nombre2_conductor, td2.codigo as tipodoc_conductor, f.victima_propietario, f.victima_conductor, f.n_documento_conductor, f.direccion_conductor, f.departamento_conductor, f.municipio_conductor, f.telefono_conductor, a2.placa as placa_amb, f.direccion_ocurrencia, f.desde, f.hasta, f.ambulancia_medicalizada, f.descripcion_accidente, m.codigo as cod_municipio, d.codigo as cod_depto, f.zona_traslados, pf.nfactura_tns, f.nombre1_propietario, pf.creado, a.descripcion, a.codigo_tns, f.hora_accidente, f.zona,
       '' as fechanac, '' as sexo, pf.inicio as fechaser, '' as horaser, pf.fin as fecha_egreso, '' as hora_egreso, '' as diagnostico_secundario,
       '' as nombres_medico, '' as apellidos_medico, '' as doc_medico, '' as num_registro_medico,
       0 as total_facturado, 0 as total_recobro, 0 as total
from furips f
left join polizas p on p.id = f.id_poliza
left join polizas_facturas pf on pf.id_furips = f.id
left join aseguradoras a on a.id = p.id_aseguradora
left join tipo_documentos td on td.id = f.tipo_documento_propietario
left join tipo_documentos td2 on td2.id = f.tipo_documento_conductor
left join ambulancias a2 on a2.id = f.idambulancia
left join marca_motos mm on mm.id = f.marca
left join departamentos d on d.id = f.departamento
left join municipios m on m.id = f.municipio
where pf.nfactura_tns in ($invoicesSql)
order by pf.nfactura_tns
SQL;
    }

    /**
     * @return array<int, string>
     */
    private function fetchFacturasFromFirebird(
        FirebirdConnection $connection,
        string $start,
        string $end,
        string $entityCode
    ): array {
        $safeStart = str_replace("'", "''", trim($start));
        $safeEnd = str_replace("'", "''", trim($end));
        $safeEntityCode = str_replace("'", "''", trim($entityCode));

        $sql = <<<SQL
select distinct f.codprefijo||f.numero as nfactura_tns
from factser f
inner join usuaxcon us on us.usuaxconid = f.usuaxconid
inner join contrato c on c.contaid = us.contaid
inner join entidad e on e.entid = c.entid
where f.fecha between '$safeStart' and '$safeEnd'
  and f.codcomp = 'FV'
  and e.codigo = '$safeEntityCode'
  and f.fecasent is not null
  and f.fecanulada is null
order by 1
SQL;

        $rows = $connection->query($sql);
        $facturas = [];
        foreach ($rows as $row) {
            $factura = trim((string) ($row['NFACTURA_TNS'] ?? $row['nfactura_tns'] ?? ''));
            if ($factura === '') {
                continue;
            }
            $facturas[] = $factura;
        }

        return $this->normalizeFacturas($facturas);
    }

    /**
     * @param array<int, string> $facturas
     * @return array<int, string>
     */
    private function normalizeFacturas(array $facturas): array
    {
        $normalized = [];
        foreach ($facturas as $factura) {
            $value = strtoupper(trim((string) $factura));
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private function buildSuffix(): string
    {
        $now = new DateTimeImmutable();
        return '540010227201' . $now->format('d') . $now->format('m') . $now->format('Y');
    }

    private function resolveEntityName(string $entityCode): string
    {
        $entityCode = trim($entityCode);
        if ($entityCode === '') {
            return '';
        }

        $safeEntityCode = str_replace("'", "''", $entityCode);
        $sql = "select descripcion from aseguradoras where codigo_tns = '{$safeEntityCode}' limit 1";

        try {
            $rows = $this->mysqlConnection->query($sql);
        } catch (\Throwable $exception) {
            return $entityCode;
        }

        if (empty($rows)) {
            return $entityCode;
        }

        $name = trim((string) ($rows[0]['descripcion'] ?? $rows[0]['DESCRIPCION'] ?? ''));
        return $name !== '' ? $name : $entityCode;
    }

    private function normalizeDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            throw new InvalidArgumentException('Fechas deben venir en formato YYYY-MM-DD.');
        }

        return $date->format('Y-m-d');
    }

    private function ensureDirectory(string $path): void
    {
        if ($path === '' || is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException("No se pudo crear el directorio $path");
        }
    }

    /**
     * Elimina archivos FURIPS* y globalsafe.txt de la carpeta de trabajo con más de $days días.
     */
    private function purgeOldTempoFiles(int $days): void
    {
        if ($days <= 0 || !is_dir($this->tempoDir)) {
            return;
        }
        $files = glob($this->tempoDir . DIRECTORY_SEPARATOR . '{FURIPS*,globalsafe.txt}', GLOB_BRACE) ?: [];
        $threshold = time() - ($days * 86400);
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }

    private function collectOutputs(string $suffix, string $jobId): array
    {
        $pattern = $this->tempoDir . DIRECTORY_SEPARATOR . 'FURIPS*' . $suffix . '*.txt';
        $files = glob($pattern) ?: [];
        sort($files);

        $exportBase = $this->exportDir . DIRECTORY_SEPARATOR . $jobId;
        $this->ensureDirectory($exportBase);

        $collected = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $target = $exportBase . DIRECTORY_SEPARATOR . basename($file);
            copy($file, $target);
            $collected[] = [
                'name' => basename($file),
                'source' => $file,
                'exported' => $target,
            ];
        }

        if ($collected === []) {
            $collected[] = [
                'name' => "ningun-furips-{$suffix}.txt",
                'source' => null,
                'exported' => null,
            ];
        }

        return $collected;
    }

    private function saveJobState(string $jobId, array $payload): void
    {
        $jobFile = $this->jobDir . DIRECTORY_SEPARATOR . $jobId . '.json';
        $current = [];
        if (is_file($jobFile)) {
            $current = json_decode(file_get_contents($jobFile), true) ?: [];
        }
        $state = array_merge($current, $payload);
        file_put_contents($jobFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
