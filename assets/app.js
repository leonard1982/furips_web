document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('furips-form');
    const entitySelect = document.getElementById('entity');
    const progressBar = document.getElementById('progressBar');
    const progressLabel = document.getElementById('progressLabel');
    const result = document.getElementById('result');
    const submitButton = document.getElementById('submitButton');
    const mainNode = document.querySelector('main[data-tempo-dir]');
    const tempDir = mainNode?.dataset?.tempoDir || 'C:\\tempo';
    const exportDir = mainNode?.dataset?.exportDir || tempDir;
    const openFolder = document.getElementById('openFolder');

    let progressTimer = null;
    const storageKeys = {
        start: 'furips:startDate',
        end: 'furips:endDate',
        entity: 'furips:entity',
    };

    const setProgress = (value, message) => {
        progressBar.style.width = `${Math.min(Math.max(value, 0), 100)}%`;
        progressLabel.textContent = message;
    };

    const animateProgress = (target = 90) => {
        clearInterval(progressTimer);
        progressTimer = setInterval(() => {
            const current = Number(progressBar.style.width.replace('%', '')) || 0;
            if (current >= target) {
                clearInterval(progressTimer);
                return;
            }
            setProgress(current + 6, progressLabel.textContent);
        }, 500);
    };

    const startProgress = (message) => {
        setProgress(10, message);
        animateProgress();
    };

    const finishProgress = (message, success = true) => {
        clearInterval(progressTimer);
        setProgress(100, message);
        setTimeout(() => {
            setProgress(0, success ? 'Listo' : 'Listo con errores');
        }, 900);
    };

    const disableForm = (disabled) => {
        Array.from(form.elements).forEach((element) => {
            element.disabled = disabled;
        });
        submitButton.disabled = disabled;
    };

    const initSelect2 = () => {
        if (window.jQuery?.fn?.select2) {
            window.jQuery('#entity').select2({
                placeholder: 'Seleccione una entidad',
                width: '100%',
                allowClear: true,
            });
        }
    };

    const renderResult = (data) => {
        const outputs = (data.outputs || []).map((file) => {
            const link = file.download_url
                ? `<a href="${file.download_url}" class="primary button-link" target="_blank" rel="noreferrer">Descargar</a>`
                : '<span class="muted">No disponible</span>';
            return `<div class="output"><span>${file.name}</span>${link}</div>`;
        }).join('');

        result.innerHTML = `
            <p class="highlight">FURIPS generados correctamente.</p>
            <div class="output-list">${outputs}</div>
        `;
    };

    const handleError = (message) => {
        finishProgress(message, false);
        result.innerHTML = `<p class="error">${message}</p>`;
    };

    const fetchEntities = async () => {
        try {
            const response = await fetch('api/entities.php');
            const payloadText = await response.text();
            let payload;
            try {
                payload = JSON.parse(payloadText);
            } catch (parseError) {
                throw new Error(`Respuesta inválida al cargar entidades: ${payloadText || 'vacía'}`);
            }

            if (!payload.success) {
                throw new Error(payload.message || 'Error al cargar entidades.');
            }

            entitySelect.innerHTML = '<option value="">Seleccione una entidad</option>';
            payload.entities.forEach((entity) => {
                const name = entity.nombre || entity.NOMBRE || '';
                const code = entity.codigo || entity.CODIGO || '';
                const option = document.createElement('option');
                option.value = code;
                option.textContent = `${code} - ${name}`;
                entitySelect.appendChild(option);
            });
            initSelect2();
            restoreEntity();
        } catch (error) {
            entitySelect.innerHTML = `<option value="">${error.message}</option>`;
        }
    };

    const setDefaultDates = () => {
        const today = new Date();
        const iso = (date) => date.toISOString().split('T')[0];
        const previous = new Date(today);
        previous.setDate(today.getDate() - 7);
        form.startDate.value = iso(previous);
        form.endDate.value = iso(today);
    };

    const restoreDates = () => {
        const storedStart = localStorage.getItem(storageKeys.start);
        const storedEnd = localStorage.getItem(storageKeys.end);
        if (storedStart) form.startDate.value = storedStart;
        if (storedEnd) form.endDate.value = storedEnd;
    };

    const restoreEntity = () => {
        const storedEntity = localStorage.getItem(storageKeys.entity);
        if (!storedEntity) return;
        if (window.jQuery?.fn?.select2) {
            window.jQuery('#entity').val(storedEntity).trigger('change');
        } else {
            const option = entitySelect.querySelector(`option[value="${storedEntity}"]`);
            if (option) entitySelect.value = storedEntity;
        }
    };

    const persistForm = () => {
        localStorage.setItem(storageKeys.start, form.startDate.value);
        localStorage.setItem(storageKeys.end, form.endDate.value);
        localStorage.setItem(storageKeys.entity, form.entity.value);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        result.textContent = '';
        disableForm(true);
        startProgress('Preparando plan y generando Furips...');

        const payload = {
            startDate: form.startDate.value,
            endDate: form.endDate.value,
            entity: form.entity.value,
        };

        try {
            const response = await fetch('api/generate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const payloadText = await response.text();

            let data;
            try {
                data = JSON.parse(payloadText);
            } catch (parseError) {
                throw new Error(`Respuesta inválida del servidor: ${payloadText || 'vacía'}`);
            }

            if (!data.success) {
                throw new Error(data.message || 'Ocurrió un error durante la generación.');
            }

            finishProgress('Furips generados. Descarga los archivos.', true);
            persistForm();
            renderResult(data);
        } catch (error) {
            handleError(error.message);
        } finally {
            disableForm(false);
        }
    });

    form.addEventListener('reset', () => {
        setDefaultDates();
        restoreDates();
        restoreEntity();
        result.textContent = '';
        if (window.jQuery?.fn?.select2) {
            window.jQuery('#entity').val(null).trigger('change');
        }
    });

    if (openFolder) {
        openFolder.href = 'exports.php';
        openFolder.target = '_blank';
        openFolder.rel = 'noreferrer';
    }

    setDefaultDates();
    restoreDates();
    fetchEntities();
    setProgress(0, 'Listo para generar.');
});
