// Progressive enhancement for <input type="file" data-file-input>:
// shows a filename + size preview with an "x" to clear the selection.
(function () {
    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function renderPreview(input) {
        let preview = input.nextElementSibling;
        if (!preview || !preview.classList.contains('file-preview')) {
            preview = document.createElement('div');
            preview.className = 'file-preview';
            input.insertAdjacentElement('afterend', preview);
        }
        preview.innerHTML = '';
        const files = Array.from(input.files || []);
        if (files.length === 0) {
            preview.style.display = 'none';
            return;
        }
        preview.style.display = '';
        files.forEach((file) => {
            const row = document.createElement('div');
            row.className = 'file-preview-row';
            const name = document.createElement('span');
            name.textContent = file.name + ' (' + formatBytes(file.size) + ')';
            row.append(name);
            preview.append(row);
        });
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'button button-small button-ghost';
        clear.textContent = '×';
        clear.setAttribute('aria-label', 'Clear selection');
        clear.addEventListener('click', () => {
            input.value = '';
            renderPreview(input);
        });
        preview.append(clear);
    }

    document.querySelectorAll('input[type="file"][data-file-input]').forEach((input) => {
        input.addEventListener('change', () => renderPreview(input));
        renderPreview(input);
    });
})();
