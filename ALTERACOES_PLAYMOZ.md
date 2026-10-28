# PlayMoz — iframe permitido, sandbox detectável bloqueado

- Incorporação normal sem atributo `sandbox`: permitida, incluindo `referrerpolicy="no-referrer"`.
- Quando o navegador permite consultar `window.frameElement`, a presença do atributo `sandbox` (qualquer configuração) activa o aviso e bloqueia visualmente o player.
- Um contexto de origem opaca (`window.origin === "null"`) também activa o aviso; poderá incluir outros contextos de origem opaca.
- **Limitação essencial:** em iframes de outras origens, especialmente com `sandbox="allow-same-origin allow-scripts"`, o navegador pode ocultar o elemento iframe e a origem continua normal. Nesses casos, não é possível identificar fiavelmente o sandbox pelo JavaScript do player. `no-referrer` também não revela esse atributo. Não existe garantia de bloquear todos os sandboxes e simultaneamente permitir todos os iframes normais sem cooperação da página incorporadora.
- O botão aponta para https://playmoz.xyz e abre nova janela quando o navegador o permitir; sandbox sem `allow-popups` pode impedir a acção.
- Mantidas as alterações anteriores do player directo; HTTP Range na origem continua necessário para saltos de reprodução.
- Não houve testes de reprodução em browsers externos nesta sessão.
