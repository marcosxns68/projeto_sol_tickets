(() => {
    const endpoint = '/usuarios/buscar';

    function normalizePicker(root) {
        const search = root.querySelector('[data-user-search]');
        const results = root.querySelector('[data-user-results]');
        const selected = root.querySelector('[data-user-selected]');
        if (!search || !results || !selected) return;

        const field = root.dataset.field;
        const multiple = root.dataset.multiple === 'true';
        const chosen = new Map();
        let controller = null;
        let timer = null;

        function renderSelected() {
            selected.replaceChildren();
            if (!chosen.size) {
                const empty = document.createElement('span');
                empty.className = 'muted user-picker-empty';
                empty.textContent = multiple ? 'Nenhuma pessoa adicionada.' : 'Nenhuma pessoa selecionada.';
                selected.append(empty);
                return;
            }

            chosen.forEach(user => {
                const token = document.createElement('span');
                token.className = 'user-picker-token';

                const copy = document.createElement('span');
                copy.textContent = user.email ? `${user.name} · ${user.email}` : user.name;
                token.append(copy);

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = multiple ? `${field}[]` : field;
                input.value = String(user.id);
                token.append(input);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'user-picker-remove';
                remove.setAttribute('aria-label', `Remover ${user.name}`);
                remove.textContent = '×';
                remove.addEventListener('click', () => {
                    chosen.delete(String(user.id));
                    renderSelected();
                });
                token.append(remove);
                selected.append(token);
            });
        }

        function choose(user) {
            if (!multiple) chosen.clear();
            chosen.set(String(user.id), user);
            search.value = '';
            results.replaceChildren();
            renderSelected();
        }

        function renderResults(users) {
            results.replaceChildren();
            users.forEach(user => {
                if (!user || !user.id || !user.name || chosen.has(String(user.id))) return;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'user-picker-result';
                const name = document.createElement('strong');
                name.textContent = String(user.name);
                const email = document.createElement('small');
                email.textContent = user.email ? String(user.email) : 'Sem e-mail';
                button.append(name, email);
                button.addEventListener('click', () => choose(user));
                results.append(button);
            });
        }

        async function runSearch() {
            const term = search.value.trim();
            if (term.length < 2) {
                results.replaceChildren();
                return;
            }

            controller?.abort();
            controller = new AbortController();
            try {
                const response = await fetch(`${endpoint}?q=${encodeURIComponent(term)}`, {
                    headers: {'Accept': 'application/json'},
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Busca indisponível.');
                const payload = await response.json();
                renderResults(Array.isArray(payload.data) ? payload.data : []);
            } catch (error) {
                if (error?.name !== 'AbortError') results.replaceChildren();
            }
        }

        search.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(runSearch, 220);
        });
        search.addEventListener('keydown', event => {
            if (event.key === 'Escape') results.replaceChildren();
        });

        root.querySelectorAll('[data-preselected-user]').forEach(node => {
            const id = node.dataset.id;
            const name = node.dataset.name;
            if (id && name) chosen.set(String(id), {id, name, email: node.dataset.email || null});
            node.remove();
        });
        renderSelected();
    }

    document.querySelectorAll('[data-user-picker]').forEach(normalizePicker);
})();
