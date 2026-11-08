document.querySelectorAll('[data-vision-model-form]').forEach((form) => {
    const type = form.querySelector('[data-analysis-type]');
    const sections = form.querySelectorAll('[data-for]');
    const defaults = form.querySelectorAll('[data-classification-default]');

    const apply = () => {
        sections.forEach((section) => {
            const visible = section.dataset.for === type.value;
            section.classList.toggle('hidden', !visible);
            section.querySelectorAll('input').forEach((input) => {
                input.disabled = !visible;
            });
        });
        defaults.forEach((input) => {
            const fallback = input.dataset.classificationDefault;
            if (type.value === 'object_detection' && input.value === fallback) input.value = '';
            else if (type.value === 'classification' && input.value === '') input.value = fallback;
        });
    };
    type.addEventListener('change', apply);
});
