import './checklist-editor';
import './photo-upload';
import './vision-model-form';

document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
