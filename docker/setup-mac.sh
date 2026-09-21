#!/usr/bin/env bash
# Richtet Akkudoktor-EOS in Docker auf dem Mac ein (Docker Desktop, Intel oder Apple Silicon).
#
#   ./setup-mac.sh            Image bauen, Container starten, auf Health warten
#   ./setup-mac.sh update     Neu bauen (nach Änderung von EOS_GIT_REF in .env) und neu starten
#   ./setup-mac.sh config F   Konfiguration aus JSON-Datei F (Standard: eos-config-poc.json) in EOS laden und speichern
#   ./setup-mac.sh status     Health, Version, letzter Lauf
#   ./setup-mac.sh logs       Container-Log verfolgen
#   ./setup-mac.sh stop       Container stoppen (Daten bleiben im Volume)
#   ./setup-mac.sh reset      Container und Datenvolume löschen (fragt nach)

set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mFehler:\033[0m %s\n' "$*" >&2; exit 1; }

need_docker() {
  command -v docker >/dev/null 2>&1 || fail "Docker fehlt. Docker Desktop installieren: https://www.docker.com/products/docker-desktop/ oder 'brew install --cask docker'."
  docker info >/dev/null 2>&1 || fail "Docker-Daemon läuft nicht. Docker Desktop starten und warten, bis das Wal-Symbol ruhig ist."
  docker compose version >/dev/null 2>&1 || fail "'docker compose' (v2) fehlt. Docker Desktop aktualisieren."
}

ensure_env() {
  if [ ! -f .env ]; then
    cp .env.example .env
    if command -v openssl >/dev/null 2>&1; then
      key=$(openssl rand -hex 32)
      sed -i '' "s/^EOSDASH_SESSKEY=.*/EOSDASH_SESSKEY=${key}/" .env 2>/dev/null || sed -i "s/^EOSDASH_SESSKEY=.*/EOSDASH_SESSKEY=${key}/" .env
    fi
    say ".env aus .env.example erzeugt. Bei Bedarf Ports/Tag anpassen und Skript erneut starten."
  fi
  # shellcheck disable=SC1091
  set -a; . ./.env; set +a
  API="http://localhost:${EOS_SERVER__PORT:-8503}"
  DASH="http://localhost:${EOS_SERVER__EOSDASH_PORT:-8504}"
}

wait_health() {
  say "Warte auf EOS unter ${API}/v1/health (bis zu 3 Minuten, erster Start ist langsam) ..."
  for _ in $(seq 1 90); do
    if out=$(curl -fsS "${API}/v1/health" 2>/dev/null); then
      echo "$out" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(f"EOS {d.get(\"version\")} ist erreichbar. Letzter EMS-Lauf: {d.get(\"energy-management\",{}).get(\"last_run_datetime\")}")' 2>/dev/null || echo "$out"
      return 0
    fi
    sleep 2
  done
  echo; docker compose logs --tail=40 eos || true
  fail "EOS antwortet nicht. Log oben prüfen ('./setup-mac.sh logs')."
}

cmd_up() {
  need_docker; ensure_env
  say "Baue Image aus GitHub-Ref '${EOS_GIT_REF}' (erster Build 5-15 Minuten, lädt Python-Pakete) ..."
  DOCKER_BUILDKIT=1 docker compose build --pull
  say "Starte Container ..."
  docker compose up -d
  wait_health
  cat <<MSG

  API / Swagger : ${API}/docs
  EOSdash       : ${DASH}
  Health        : ${API}/v1/health
  Plan          : ${API}/v1/energy-management/plan   (404 bis zum ersten erfolgreichen Lauf)

  Nächster Schritt: Konfiguration in EOSdash setzen oder './setup-mac.sh config' für die PoC-Vorlage.
MSG
}

cmd_update() {
  need_docker; ensure_env
  say "Baue Image für '${EOS_GIT_REF}' neu (ohne Cache) ..."
  DOCKER_BUILDKIT=1 docker compose build --pull --no-cache
  docker compose up -d
  wait_health
}

cmd_config() {
  need_docker; ensure_env
  file="${1:-eos-config-poc.json}"
  [ -f "$file" ] || fail "Datei '$file' nicht gefunden."
  python3 -m json.tool "$file" >/dev/null || fail "'$file' ist kein gültiges JSON."
  # Schlüssel, die mit "_" beginnen (eigene Notizen), werden nicht an EOS gesendet.
  payload=$(python3 -c 'import json,sys; c=json.load(open(sys.argv[1])); print(json.dumps({k:v for k,v in c.items() if not k.startswith("_")}))' "$file")
  say "Sende '$file' an PUT ${API}/v1/config (Teil-Konfiguration wird gemerged) ..."
  code=$(curl -sS -o /tmp/eos-config-response.json -w '%{http_code}' -X PUT "${API}/v1/config" \
    -H 'Content-Type: application/json' --data-binary "$payload")
  if [ "$code" != "200" ]; then
    cat /tmp/eos-config-response.json; echo
    fail "EOS hat die Konfiguration abgelehnt (HTTP $code)."
  fi
  say "Speichere Konfiguration in EOS.config.json ..."
  curl -fsS -o /dev/null -X PUT "${API}/v1/config/file"
  say "Fertig. Kontrolle: ${API}/v1/config  |  Dashboard: ${DASH}"
  echo "Hinweis: latitude/longitude, PV-Anlage, Batterie und Provider in '$file' vor dem Laden an die eigene Anlage anpassen."
}

cmd_status() {
  need_docker; ensure_env
  docker compose ps
  curl -fsS "${API}/v1/health" && echo || echo "EOS nicht erreichbar."
}

cmd_logs()  { need_docker; docker compose logs -f eos; }
cmd_stop()  { need_docker; docker compose stop; }
cmd_reset() {
  need_docker
  read -r -p "Container UND Datenvolume (Konfiguration, Messwerte) löschen? [j/N] " a
  [ "${a:-n}" = "j" ] || exit 0
  docker compose down -v
}

case "${1:-up}" in
  up)      cmd_up ;;
  update)  cmd_update ;;
  config)  shift; cmd_config "$@" ;;
  status)  cmd_status ;;
  logs)    cmd_logs ;;
  stop)    cmd_stop ;;
  reset)   cmd_reset ;;
  *) sed -n '2,12p' "$0"; exit 1 ;;
esac
