#!/usr/bin/env bash

# Trocamos para o domínio .casa que você enviou
BASE="${XTREAM_BASE:-http://mgeb.casa}"
USER="${XTREAM_USER:-Feljo}"
PASS="${XTREAM_PASS:-22109f3c5de3aa6c}"

OUT="a.json"
TMP="${OUT}.tmp"

echo "Baixando catalogo de ${BASE} (user: ${USER}) ..."

# Adicionamos User-Agent de navegador real (Chrome) para enganar a Cloudflare
HTTP_CODE=$(curl -sL --compressed -4 \
  --retry 3 --retry-delay 5 --retry-connrefused \
  --connect-timeout 30 --max-time 900 \
  -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36" \
  -H "Accept: application/json, text/plain, */*" \
  -H "Accept-Language: pt-BR,pt;q=0.9,en;q=0.8" \
  -w "%{http_code}" \
  "${BASE}/player_api.php?username=${USER}&password=${PASS}&action=get_vod_streams" \
  -o "$TMP" || echo "000")

echo "Codigo HTTP retornado: ${HTTP_CODE}"

if [ "$HTTP_CODE" != "200" ]; then
  echo "ERRO: Falha no download (HTTP ${HTTP_CODE})."
  head -c 300 "$TMP" || true
  rm -f "$TMP"
  exit 1
fi

if [ ! -s "$TMP" ]; then
  echo "ERRO: Arquivo vazio."
  rm -f "$TMP"
  exit 1
fi

if ! jq empty "$TMP" >/dev/null 2>&1; then
  echo "ERRO: JSON invalido."
  rm -f "$TMP"
  exit 1
fi

TOTAL=$(jq 'length' "$TMP")
if [ "$TOTAL" -lt 1 ]; then
  echo "ERRO: Catalogo sem filmes."
  rm -f "$TMP"
  exit 1
fi

mv "$TMP" "$OUT"
echo "SUCESSO: ${OUT} atualizado ($(du -h "$OUT" | cut -f1), ${TOTAL} filmes)"