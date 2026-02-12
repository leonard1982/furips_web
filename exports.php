<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$exportsDir = $config['tempo']['dir']; // storage/furips
$cacheBust = date('YmdHis');

$query = trim($_GET['q'] ?? '');
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$items = [];
if (is_dir($exportsDir)) {
    $files = glob($exportsDir . DIRECTORY_SEPARATOR . 'FURIPS*.txt') ?: [];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $mtime = filemtime($file);
        $items[] = [
            'jobId' => '',
            'name' => basename($file),
            'mtime' => $mtime,
            'path' => $file,
            'download_url' => 'download_raw.php?file=' . rawurlencode(basename($file)),
        ];
    }
}

// Filters
$filtered = array_filter($items, static function (array $item) use ($query, $dateFrom, $dateTo): bool {
    $pass = true;
    if ($query !== '') {
        $needle = mb_strtolower($query);
        $hay = mb_strtolower($item['name'] . ' ' . $item['jobId']);
        $pass = $pass && strpos($hay, $needle) !== false;
    }
    if ($dateFrom !== '') {
        $fromTs = strtotime($dateFrom . ' 00:00:00');
        $pass = $pass && ($item['mtime'] >= $fromTs);
    }
    if ($dateTo !== '') {
        $toTs = strtotime($dateTo . ' 23:59:59');
        $pass = $pass && ($item['mtime'] <= $toTs);
    }
    return $pass;
});

usort($filtered, static function (array $a, array $b) {
    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
});
$total = count($filtered);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$paged = array_slice($filtered, $offset, $perPage);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Descargas FURIPS</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= $cacheBust ?>">
</head>
<body class="list-page">
    <main class="page">
        <section class="panel panel--wide">
            <header>
                <p class="eyebrow">FURIPS2.5 - Descargas</p>
                <h1>Archivos generados</h1>
                <p class="tagline">Busca por nombre, ID de trabajo o filtra por fecha de generación.</p>
            </header>

            <form class="form form-inline" method="get" action="exports.php">
                <div class="field">
                    <label for="q">Buscar</label>
                    <input type="text" id="q" name="q" value="<?= h($query) ?>" placeholder="Nombre o ID de trabajo">
                </div>
                <div class="field">
                    <label for="from">Desde</label>
                    <input type="date" id="from" name="from" value="<?= h($dateFrom) ?>">
                </div>
                <div class="field">
                    <label for="to">Hasta</label>
                    <input type="date" id="to" name="to" value="<?= h($dateTo) ?>">
                </div>
                <div class="actions">
                    <button type="submit" class="primary">Filtrar</button>
                    <a class="ghost button-link" href="exports.php">Limpiar</a>
                </div>
            </form>

            <div class="result result--secondary">
                <?php if ($total === 0): ?>
                    <p class="muted">No hay archivos que coincidan con el filtro.</p>
                <?php else: ?>
                    <div class="table">
                        <div class="table__head">
                            <span>Archivo</span>
                            <span>Trabajo</span>
                            <span>Fecha</span>
                            <span></span>
                        </div>
                        <?php foreach ($paged as $item): ?>
                            <div class="table__row">
                                <span><?= h($item['name']) ?></span>
                                <span class="muted"><?= h($item['jobId']) ?></span>
                                <span><?= date('Y-m-d H:i', $item['mtime']) ?></span>
                                <span><a href="<?= h($item['download_url']) ?>" class="primary button-link" target="_blank" rel="noreferrer">Descargar</a></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                            <?php
                                $params = ['page' => $i];
                                if ($query !== '') $params['q'] = $query;
                                if ($dateFrom !== '') $params['from'] = $dateFrom;
                                if ($dateTo !== '') $params['to'] = $dateTo;
                                $link = 'exports.php?' . http_build_query($params);
                            ?>
                            <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="<?= h($link) ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
