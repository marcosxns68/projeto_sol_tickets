(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchJson = async (url) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            const error = new Error(`HTTP ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return response.json();
    };

    const replaceOptions = (select, folders, selectedId = null, rootLabel = 'Raiz do departamento') => {
        select.replaceChildren();
        const root = document.createElement('option');
        root.value = '';
        root.textContent = rootLabel;
        select.appendChild(root);

        folders.forEach((folder) => {
            const option = document.createElement('option');
            option.value = String(folder.id);
            option.textContent = folder.path;
            if (selectedId !== null && String(folder.id) === String(selectedId)) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    };

    const buildFolderField = () => {
        const label = document.createElement('label');
        label.dataset.ticketFolderField = '';
        label.append(document.createTextNode('Pasta / subpasta'));

        const select = document.createElement('select');
        select.name = 'folder_id';
        select.dataset.ticketFolderSelect = '';
        label.appendChild(select);

        const help = document.createElement('small');
        help.className = 'muted';
        help.dataset.ticketFolderHelp = '';
        help.textContent = 'Raiz do departamento';
        label.appendChild(help);

        return { label, select, help };
    };

    const loadDepartment = async (departmentId) => {
        if (!departmentId) {
            return { department_id: null, folders: [], folder_edit: false };
        }
        return fetchJson(`/departamentos/${encodeURIComponent(departmentId)}/pastas`);
    };

    const setupContextualCreate = async () => {
        const form = document.getElementById('ticketCreateForm');
        if (!form) return;

        const departmentSelect = form.querySelector('select[name="department_id"]');
        if (!departmentSelect) return;

        const params = new URLSearchParams(window.location.search);
        const requestedDepartment = params.get('department');
        const requestedFolder = params.get('folder');

        if (requestedDepartment && Array.from(departmentSelect.options).some(option => option.value === requestedDepartment)) {
            departmentSelect.value = requestedDepartment;
        }

        // O fluxo normal continua pedindo apenas o departamento e cria na raiz.
        if (!requestedFolder) return;

        const departmentLabel = departmentSelect.closest('label');
        if (!departmentLabel) return;

        const field = buildFolderField();
        departmentLabel.insertAdjacentElement('afterend', field.label);

        const refresh = async (selectedFolder = null) => {
            const departmentId = departmentSelect.value;
            if (!departmentId) {
                replaceOptions(field.select, [], null, 'Triagem / raiz');
                field.select.disabled = true;
                field.help.textContent = 'A Triagem não possui pastas.';
                return;
            }

            try {
                const data = await loadDepartment(departmentId);
                replaceOptions(field.select, data.folders || [], selectedFolder);
                field.select.disabled = !data.folder_edit;
                field.help.textContent = data.folder_edit
                    ? 'O ticket será criado nesta localização.'
                    : 'Você pode enviar para este departamento, mas não organizar tickets nas pastas dele.';
            } catch (error) {
                replaceOptions(field.select, []);
                field.select.disabled = true;
                field.help.textContent = error.status === 403
                    ? 'Sem acesso à estrutura interna deste departamento.'
                    : 'Não foi possível carregar as pastas agora.';
            }
        };

        await refresh(requestedFolder);
        departmentSelect.addEventListener('change', () => refresh(null));

        const sourceRadios = form.querySelectorAll('input[name="source_mode"]');
        const syncSourceMode = () => {
            const integration = form.querySelector('input[name="source_mode"][value="integration"]:checked');
            field.label.hidden = Boolean(integration);
            if (integration) {
                field.select.disabled = true;
            } else {
                refresh(field.select.value || null);
            }
        };
        sourceRadios.forEach(radio => radio.addEventListener('change', syncSourceMode));
        syncSourceMode();
    };

    const appendLocationToServiceDetails = (serviceDetails, context) => {
        const list = serviceDetails?.querySelector('dl');
        if (!list || !context.folder_access || list.querySelector('[data-ticket-folder-location]')) return;

        const dt = document.createElement('dt');
        dt.textContent = 'Pasta / subpasta';
        dt.dataset.ticketFolderLocation = '';
        const dd = document.createElement('dd');
        dd.dataset.ticketFolderLocation = '';

        const current = (context.folders || []).find(folder => String(folder.id) === String(context.folder_id));
        dd.textContent = current?.path || 'Raiz do departamento';
        list.append(dt, dd);
    };

    const setupTicketRouting = async () => {
        const match = window.location.pathname.match(/^\/tickets\/(\d+)/);
        if (!match) return;

        const ticketId = match[1];
        const serviceDetails = document.querySelector('[data-ticket-tool="service"] .ticket-disclosure-content');
        if (!serviceDetails) return;

        let context;
        try {
            context = await fetchJson(`/tickets/${encodeURIComponent(ticketId)}/pastas`);
        } catch (_) {
            return;
        }

        appendLocationToServiceDetails(serviceDetails, context);
        if (!context.folder_access) return;

        const routingForm = serviceDetails.querySelector('[data-ticket-routing]');
        if (!routingForm) {
            if (!context.folder_edit || !context.department_id) return;

            const form = document.createElement('form');
            form.method = 'post';
            form.action = `/tickets/${encodeURIComponent(ticketId)}/atendimento`;
            form.className = 'mini-form ticket-folder-only-form';
            form.dataset.ticketFolderOnly = '';

            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = csrf;
            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'PATCH';
            const department = document.createElement('input');
            department.type = 'hidden';
            department.name = 'department_id';
            department.value = String(context.department_id);

            const field = buildFolderField();
            replaceOptions(field.select, context.folders || [], context.folder_id);
            field.help.textContent = 'Organização interna deste departamento.';

            const button = document.createElement('button');
            button.className = 'secondary-button full';
            button.type = 'submit';
            button.textContent = 'Salvar pasta';

            form.append(token, method, department, field.label, button);
            serviceDetails.appendChild(form);
            return;
        }

        const departmentControl = routingForm.querySelector('[name="department_id"]');
        if (!departmentControl) return;
        const departmentLabel = departmentControl.closest('label');
        if (!departmentLabel) return;

        const field = buildFolderField();
        departmentLabel.insertAdjacentElement('afterend', field.label);
        const originalDepartment = String(context.department_id || '');
        const originalFolder = context.folder_id;

        const refresh = async () => {
            const departmentId = departmentControl.value;
            const selectedFolder = String(departmentId) === originalDepartment ? originalFolder : null;

            if (!departmentId) {
                replaceOptions(field.select, [], null, 'Raiz do departamento');
                field.select.disabled = true;
                return;
            }

            try {
                const data = String(departmentId) === originalDepartment
                    ? { folders: context.folders || [], folder_edit: context.folder_edit }
                    : await loadDepartment(departmentId);

                replaceOptions(field.select, data.folders || [], selectedFolder);
                field.select.disabled = !data.folder_edit;
                field.help.textContent = data.folder_edit
                    ? 'Escolha a localização dentro do departamento.'
                    : 'Ao encaminhar para esta caixa, o ticket ficará na raiz.';
            } catch (error) {
                replaceOptions(field.select, []);
                field.select.disabled = true;
                field.help.textContent = error.status === 403
                    ? 'Você pode encaminhar para esta caixa, mas não visualizar suas pastas.'
                    : 'Não foi possível carregar as pastas agora.';
            }
        };

        await refresh();
        if (departmentControl.tagName === 'SELECT') {
            departmentControl.addEventListener('change', refresh);
        }

        const submit = routingForm.querySelector('button[type="submit"]');
        if (submit) submit.textContent = 'Salvar departamento, pasta e responsável';
    };

    document.addEventListener('DOMContentLoaded', () => {
        setupContextualCreate();
        setupTicketRouting();
    });
})();
