document.querySelectorAll('[data-checklist-editor]').forEach((editor) => {
    const container = editor.querySelector('[data-checklist-items]');
    const template = editor.querySelector('[data-item-template]');
    const addButton = editor.querySelector('[data-add-item]');
    const announcement = editor.querySelector('[data-item-announcement]');

    const renumber = () => {
        const rows = [...container.querySelectorAll('[data-checklist-row]')];
        rows.forEach((row, index) => {
            row.querySelector('[data-item-number]').textContent = index + 1;
            row.querySelectorAll('[data-field]').forEach((field) => {
                field.name = `items[${index}][${field.dataset.field}]`;
            });
            row.querySelector('[data-item-action="up"]').disabled = index === 0;
            row.querySelector('[data-item-action="down"]').disabled = index === rows.length - 1;
            row.querySelector('[data-item-action="remove"]').disabled = rows.length === 1;
        });
        addButton.disabled = rows.length >= 30;
    };

    addButton.addEventListener('click', () => {
        if (container.children.length >= 30) return;
        container.append(template.content.cloneNode(true));
        renumber();
        container.lastElementChild.querySelector('[data-field="title"]').focus();
        announcement.textContent = 'Checklist item added.';
    });

    container.addEventListener('click', (event) => {
        const button = event.target.closest('[data-item-action]');
        if (!button || button.disabled) return;
        const row = button.closest('[data-checklist-row]');
        const action = button.dataset.itemAction;
        if (action === 'remove') {
            const next = row.nextElementSibling || row.previousElementSibling;
            row.remove();
            renumber();
            next.querySelector('[data-field="title"]').focus();
            announcement.textContent = 'Checklist item removed.';
            return;
        }
        if (action === 'up' && row.previousElementSibling) {
            container.insertBefore(row, row.previousElementSibling);
        } else if (action === 'down' && row.nextElementSibling) {
            container.insertBefore(row.nextElementSibling, row);
        }
        renumber();
        row.querySelector('[data-field="title"]').focus();
        announcement.textContent = 'Checklist item moved.';
    });

    editor.addEventListener('submit', renumber);
    renumber();
});
