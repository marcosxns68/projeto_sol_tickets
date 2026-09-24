/*
 * Web Push para o PWA instalado. A assinatura acontece somente após clique:
 * permissões do navegador e vínculo do dispositivo com a conta autenticada.
 * O servidor entrega o push diretamente ao Service Worker, sem polling.
 */
(() => {
  const buttons = document.querySelectorAll('[data-enable-browser-alerts]');
  if (!buttons.length) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const base = '/pwa-push';
  const supported = () => window.isSecureContext && 'serviceWorker' in navigator
    && 'PushManager' in window && 'Notification' in window && !!csrf;

  function status(button, message) {
    const element = button.closest('.department-follow-actions')
      ?.querySelector('[data-browser-alert-status]');
    if (element) element.textContent = message;
  }

  function decodeKey(base64url) {
    const padding = '='.repeat((4 - base64url.length % 4) % 4);
    const binary = atob((base64url + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(binary, character => character.charCodeAt(0));
  }

  async function api(path, options = {}) {
    const response = await fetch(base + path, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        ...(options.body ? {'Content-Type': 'application/json'} : {}),
      },
    });
    if (!response.ok) throw new Error(response.status === 419
      ? 'Sua sessão expirou. Entre novamente no Tickets e tente ativar o push.'
      : 'Não foi possível comunicar com o servidor. Verifique sua conexão.');
    return response.json();
  }

  async function activate(button) {
    if (!supported()) {
      status(button, 'Push não disponível neste navegador. Instale o PWA atualizado e abra pelo ícone do celular.');
      return;
    }
    button.disabled = true;
    try {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        status(button, 'O celular bloqueou as notificações. Autorize-as nas configurações do aplicativo e tente novamente.');
        return;
      }

      const [{publicKey}, registration] = await Promise.all([
        api('/configuracao'), navigator.serviceWorker.ready,
      ]);
      const desiredKey = decodeKey(publicKey);
      let subscription = await registration.pushManager.getSubscription();
      if (subscription) {
        const current = subscription.options.applicationServerKey;
        const bytes = current ? new Uint8Array(current) : null;
        if (!bytes || bytes.length !== desiredKey.length
          || bytes.some((value, i) => value !== desiredKey[i])) {
          await subscription.unsubscribe();
          subscription = null;
        }
      }
      if (!subscription) {
        subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: desiredKey,
        });
      }
      await api('/dispositivos', {
        method: 'POST',
        body: JSON.stringify({
          subscription: subscription.toJSON(),
          device_label: 'PWA ' + (navigator.platform || 'Celular').slice(0, 90),
        }),
      });
      status(button, 'Este dispositivo está conectado ao Push. Marque Push nesta caixa e salve as preferências.');
    } catch (error) {
      status(button, error?.message || 'Falha ao ativar o push. Verifique a permissão e tente novamente.');
    } finally {
      button.disabled = false;
    }
  }

  buttons.forEach(button => button.addEventListener('click', () => activate(button)));
  document.querySelectorAll('[data-disable-pwa-push]').forEach(button => {
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        if (!supported()) throw new Error('Push não disponível neste dispositivo.');
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
          await api('/dispositivos', {
            method: 'DELETE',
            body: JSON.stringify({endpoint: subscription.endpoint}),
          });
          await subscription.unsubscribe();
        }
        status(button, 'Push deste dispositivo desativado. E-mail e WhatsApp continuam conforme suas preferências.');
      } catch (error) {
        status(button, error?.message || 'Não foi possível desativar as notificações.');
      } finally {
        button.disabled = false;
      }
    });
  });
})();
