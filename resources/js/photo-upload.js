document.querySelectorAll('[data-photo-upload]').forEach((form) => {
    if (!window.URL?.createObjectURL) return;
    const input = form.querySelector('[data-photo-file]');
    const preview = form.querySelector('[data-photo-preview]');
    const error = form.querySelector('[data-photo-error]');
    const camera = form.querySelector('[data-photo-camera]');
    const picker = form.querySelector('[data-photo-picker]');
    const clear = form.querySelector('[data-photo-clear]');
    const submit = form.querySelector('[data-photo-submit]');
    let objectUrl = null;
    let version = 0;
    let ready = false;
    let uploading = false;

    const reset = () => {
        version++;
        ready = false;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        preview.removeAttribute('src');
        preview.hidden = true;
        clear.hidden = true;
        submit.disabled = true;
        error.hidden = true;
        camera.textContent = 'Take photo';
    };
    camera.hidden = false;
    picker.hidden = false;
    submit.disabled = true;
    camera.addEventListener('click', () => {
        input.setAttribute('capture', 'environment');
        input.click();
    });
    picker.addEventListener('click', () => {
        input.removeAttribute('capture');
        input.click();
    });
    clear.addEventListener('click', () => {
        input.value = '';
        reset();
        input.focus();
    });
    input.addEventListener('change', () => {
        reset();
        const file = input.files?.[0];
        if (!file) return;
        const fail = (message) => {
            input.value = '';
            reset();
            error.textContent = message;
            error.hidden = false;
        };
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) {
            fail('Choose a JPEG, PNG or WebP photo up to 10 MB.');
            return;
        }
        objectUrl = URL.createObjectURL(file);
        const currentVersion = version;
        const image = new Image();
        image.onload = () => {
            if (currentVersion !== version) return;
            if (image.naturalWidth < 64 || image.naturalHeight < 64 || image.naturalWidth > 8192 || image.naturalHeight > 8192 || image.naturalWidth * image.naturalHeight > 32000000) {
                fail('Use a clear photo between 64 and 8192 pixels per side, up to 32 megapixels.');
                return;
            }
            preview.src = objectUrl;
            preview.hidden = false;
            clear.hidden = false;
            ready = true;
            camera.textContent = 'Retake photo';
            submit.disabled = false;
        };
        image.onerror = () => {
            if (currentVersion === version) fail('This photo could not be opened. Choose another JPEG, PNG or WebP photo.');
        };
        image.src = objectUrl;
    });
    form.addEventListener('submit', (event) => {
        if (!ready || uploading) {
            event.preventDefault();
            return;
        }
        uploading = true;
        submit.disabled = true;
        submit.textContent = 'Uploading…';
    });
    window.addEventListener('pageshow', () => {
        uploading = false;
        submit.textContent = 'Upload photo';
        submit.disabled = !ready;
    });
    window.addEventListener('pagehide', () => {
        input.value = '';
        reset();
    });
});
