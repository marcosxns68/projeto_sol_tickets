@if(config('turnstile.site_key'))
    <div class="cf-turnstile" data-sitekey="{{ config('turnstile.site_key') }}" data-theme="auto"></div>
    @error('turnstile')<small class="error">{{ $message }}</small>@enderror
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@elseif(config('turnstile.secret_key'))
    <small class="error">Verificação de segurança temporariamente indisponível.</small>
@endif
