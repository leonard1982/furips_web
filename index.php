<?php

declare(strict_types=1);
$config = require __DIR__ . '/config.php';
$tempoDir = $config['tempo']['dir'];
$exportDir = $config['storage']['exports'];
$cacheBust = date('YmdHis');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Generador de FURIPS</title>
    <link rel="stylesheet" href="assets/vendor/select2.min.css?v=<?= $cacheBust ?>" />
    <link rel="stylesheet" href="assets/style.css?v=<?= $cacheBust ?>" />
</head>

<body>
    <main class="page" data-tempo-dir="<?= htmlspecialchars($tempoDir, ENT_QUOTES) ?>" data-export-dir="<?= htmlspecialchars($exportDir, ENT_QUOTES) ?>">
        <section class="panel">
            <header>
                <p class="eyebrow">FURIPS2.5</p>
                <h1>Reporte Furips</h1>
            </header>

            <form id="furips-form" class="form">
                <div id="busyOverlay" class="busy-overlay" aria-hidden="true">
                    <div class="busy-overlay__card" role="status" aria-live="polite">
                        <div class="busy-overlay__spinner" aria-hidden="true"></div>
                        <p class="busy-overlay__title">Espere por favor</p>
                        <p class="busy-overlay__message" id="busyMessage">Estamos generando los archivos.</p>
                        <div class="busy-overlay__progress">
                            <div class="busy-overlay__progress-bar" id="busyProgressBar"></div>
                        </div>
                        <p class="busy-overlay__label" id="busyProgressLabel">Iniciando...</p>
                    </div>
                </div>

                <div class="columns columns--form">
                    <div class="column">
                        <div class="field">
                            <label for="startDate">Fecha inicial</label>
                            <input id="startDate" name="startDate" type="date" required />
                        </div>
                        <div class="field">
                            <label for="endDate">Fecha final</label>
                            <input id="endDate" name="endDate" type="date" required />
                        </div>
                        <div class="field">
                            <label for="entity">Entidad</label>
                            <select id="entity" name="entity" required>
                                <option value="">Cargando entidades...</option>
                            </select>
                        </div>
                    </div>
                    <div class="column column--narrow">
                        <div id="result" class="result"></div>
                    </div>
                </div>

                <div class="actions actions--spread">
                    <div class="actions__group">
                        <button type="reset" class="ghost" id="resetButton">Limpiar</button>
                        <a class="button-link ghost" href="exports.php" target="_blank" rel="noreferrer">Ver lista completa</a>
                        <button type="submit" class="primary" id="submitButton">Generar FURIPS</button>
                    </div>
                </div>

                <div id="progress" class="progress" aria-live="polite">
                    <div class="progress__body">
                        <div class="progress__bar" id="progressBar"></div>
                    </div>
                    <p id="progressLabel">Esperando...</p>
                </div>
            </form>

            <footer class="site-footer">
                <a
                    href="https://www.solucionesnavarro.com"
                    target="_blank"
                    rel="noreferrer"
                >www.solucionesnavarro.com</a>
                <span> - </span>
                <a
                    href="https://wa.me/573114485310?text=Hola%20quisiera%20mandar%20hacer%20un%20desarrollo..."
                    target="_blank"
                    rel="noreferrer"
                >3114485310</a>
            </footer>
        </section>
    </main>

    <script src="assets/vendor/jquery-3.7.0.min.js?v=<?= $cacheBust ?>"></script>
    <script src="assets/vendor/select2.min.js?v=<?= $cacheBust ?>"></script>
    <script src="assets/app.js?v=<?= $cacheBust ?>" defer></script>
</body>

</html>
