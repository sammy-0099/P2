#!/usr/bin/env bash
set -e

BASE="${XTREAM_BASE:-http://mgeb.casa}"
USER="${XTREAM_USER:-Feljo}"
PASS="${XTREAM_PASS:-22109f3c5de3aa6c}"

OUT="s.json"
TMP_LIST="s.json.tmp"

echo "Baixando lista de series de ${BASE}..."

# Baixa apenas a lista de séries (sem os episódios)
curl -sL --compressed -4 \
  --retry 3 --retry-delay 5 \
  --connect-timeout 30 --max-time 300 \
  -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36" \
  "${BASE}/player_api.php?username=${USER}&password=${PASS}&action=get_series" \
  -o "$TMP_LIST"

# Valida o JSON
if ! jq empty "$TMP_LIST" >/dev/null 2>&1; then
  echo "ERRO: JSON de series invalido."
  rm -f "$TMP_LIST"
  exit 1
fi

TOTAL_SERIES=$(jq 'length' "$TMP_LIST")
if [ "$TOTAL_SERIES" -lt 1 ]; then
  echo "ERRO: Nenhuma serie encontrada."
  rm -f "$TMP_LIST"
  exit 1
fi

mv "$TMP_LIST" "$OUT"
echo "SUCESSO: ${OUT} atualizado ($(du -h "$OUT" | cut -f1), ${TOTAL_SERIES} series)"