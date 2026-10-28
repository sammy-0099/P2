# Alterações PlayMoz

- Episódios (raiz e pasta A) reproduzem directamente a URL de origem MP4/HLS/DASH, sem proxy PHP. Filmes já utilizavam URL directa; configuração mantida.
- Detecção no navegador de `iframe sandbox` quando observável e de domínios incorporadores identificados pelo Referer fora da lista permitida. Mostra aviso com botão para https://playmoz.xyz.
- Domínios permitidos para incorporação: o próprio host, playmoz.xyz, subdomínios de playmoz.xyz e apps.golplay.site. Editar a lista no script incorporado nos index.php se necessário.
- A protecção não é infalível: navegadores podem ocultar o Referer e `frameElement`; para controlo forte, exigir autorização e tokens assinados no servidor.
- URLs MP4 directas precisam de CORS e suporte a pedidos HTTP Range (206 Partial Content) do servidor de origem para que a barra e os botões ±10 s funcionem correctamente. Caso contrário, a fonte deverá ser corrigida na origem.
- As rotas legadas `?stream=` continuam disponíveis para compatibilidade, mas não são utilizadas no player dos episódios.
- Testar num servidor PHP com acesso às fontes e navegadores reais; não foi executado teste de streaming remoto.

## Modo rigoroso de incorporação (actualização)
- As sete páginas `index.php` protegidas bloqueiam **qualquer iframe**, mesmo se o iframe usar `referrerpolicy="no-referrer"` e `sandbox` com quaisquer permissões.
- Isso **não identifica literalmente os atributos**: browsers impedem inspecção fiável dos atributos de iframes cross-origin; o bloqueio é pela presença de enquadramento.
- Consequência: também bloqueia incorporações legítimas dessas páginas. As rotas A/config.php têm um gate próprio e não foram desactivadas (evita quebrar indiscriminadamente a API de embed); sandbox invisível não pode ser identificado de modo infalível nessas rotas.
- O botão aponta para https://playmoz.xyz com target=_top; iframes sandbox sem permissão de navegação de topo podem impedir o clique. A forma robusta de impedir incorporação é CSP frame-ancestors, mas nesse caso o diálogo do iframe também não aparece.
