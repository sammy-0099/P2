# PlayMoz — bloqueio apenas do sandbox detectado

- Iframes sem atributo sandbox são permitidos pela verificação de sandbox, inclusive com referrerpolicy=no-referrer.
- Iframes com atributo sandbox observável no elemento pai são bloqueados, sem depender das permissões da lista allow-*.
- Contextos de origem opaca (origin null, que podem resultar de sandbox sem allow-same-origin) também são bloqueados.
- Removido o falso positivo que marcava a indisponibilidade de sessionStorage como sandbox.
- Mantidas as verificações de domínios autorizados existentes em A/config.php; o controlo de sandbox não é um substituto da autorização.
- Se o iframe estiver em domínio diferente com sandbox allow-same-origin allow-scripts, o navegador pode ocultar o atributo e não há detecção fiável apenas com JS.
- A abertura do botão PlayMoz pode ser restringida pelo próprio sandbox (ex.: ausência de allow-popups).
- Os links directos do player e comandos de avanço/retrocesso anteriores foram mantidos.


### Actualização de 28/10 — detecção estilo RedeFlix
- Verifica `window.frameElement.hasAttribute("sandbox")` quando acessível, origem opaca (`null`) e rejeição de `document.domain` cuja mensagem mencione sandbox/forbidden.
- Iframes sem sandbox continuam permitidos; `no-referrer` não causa bloqueio por si.
- Substitui `redeflixapi.store` por `redeflixapi.bar` nos resolvers.
- Limitação dos navegadores: em iframes cross-origin com `allow-same-origin allow-scripts`, o sandbox pode ser indetectável; `document.domain` é obsoleto e não é prova universal.
