# PlayMoz Embed /A — protegido

A pasta `/A` aplica as regras de incorporação.

- `/movie/...` e `/tv/...` na raiz continuam com o comportamento original.
- `/A/movie/...` e `/A/tv/...` só carregam em iframe de origem autorizada.
- Acesso direto, domínio não autorizado ou sandbox restritivo mostra uma tela de bloqueio e o botão **Acessar**.
- O botão abre `https://golplay.site`.
- A pasta `/A` não contém o pop-up de anúncio a cada 2 minutos.

Domínios permitidos são definidos em `A/config.php`, em `PM_AUTHORIZED_EMBED_ORIGINS`.
