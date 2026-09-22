#!/usr/bin/env bash
# Richtet Akkudoktor-EOS in Docker ein: macOS (Docker Desktop), Linux (Docker Engine) und NAS mit Docker.
#
#   ./setup.sh            Image holen (fertiges Image, sonst Build aus dem Git-Tag), Container starten, auf Health warten
#   ./setup.sh update     Neu holen bzw. neu bauen (nach Änderung von EOS_VERSION/EOS_GIT_REF in .env) und neu starten
#   ./setup.sh config F   Konfiguration aus JSON-Datei F (Standard: eos-config-poc.json) in EOS laden und speichern
#   ./setup.sh status     Health, Version, letzter Lauf
#   ./setup.sh logs       Container-Log verfolgen
#   ./setup.sh stop       Container stoppen (Daten bleiben im Volume)
#   ./setup.sh reset      Container und Datenvolume löschen (fragt nach)
#
# Bevorzugt wird das veröffentlichte Image akkudoktor/eos:<EOS_VERSION> von Docker Hub. Gibt es das nicht
# (Release-Kandidaten), baut Compose das Image aus https://github.com/Akkudoktor-EOS/EOS.git#<EOS_GIT_REF>
# mit dem Dockerfile des EOS-Projekts. Am EOS-Code wird nichts verändert.

set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mHinweis:\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mFehler:\033[0m %s\n' "$*" >&2; exit 1; }

# sed -i unterscheidet sich zwischen BSD (macOS) und GNU (Linux).
sedi() { if sed --version >/dev/null 2>&1; then sed -i "$@"; else sed -i '' "$@"; fi; }
has_python() { command -v python3 >/dev/null 2>&1; }

need_docker() {
  command -v docker >/dev/null 2>&1 || fail "Docker fehlt. macOS: Docker Desktop (https://www.docker.com/products/docker-desktop/) oder 'brew install --cask docker'. Linux: https://docs.docker.com/engine/install/"
  docker info >/dev/null 2>&1 || fail "Docker-Daemon läuft nicht oder keine Rechte. macOS: Docker Desktop starten. Linux: 'sudo systemctl start docker' und Nutzer in die Gruppe docker aufnehmen."
  docker compose version >/dev/null 2>&1 || fail "'docker compose' (v2) fehlt. Docker Desktop aktualisieren bzw. das Paket docker-compose-plugin installieren."
}

ensure_env() {
  if [ ! -f .env ]; then
    cp .env.example .env
    if command -v openssl >/dev/null 2>&1; then
      key=$(openssl rand -hex 32)
      sedi "s/^EOSDASH_SESSKEY=.*/EOSDASH_SESSKEY=${key}/" .env
    else
      warn "openssl fehlt, EOSDASH_SESSKEY in .env bitte von Hand auf einen zufälligen Wert setzen."
    fi
    say ".env aus .env.example erzeugt. Bei Bedarf Ports oder Version anpassen und das Skript erneut starten."
  fi
  # shellcheck disable=SC1091
  set -a; . ./.env; set +a
  API="http://localhost:${EOS_SERVER__PORT:-8503}"
  DASH="http://localhost:${EOS_SERVER__EOSDASH_PORT:-8504}"
}

# Setzt EOS_IMAGE in .env: fertiges Image von Docker Hub, sonst lokaler Build.
choose_image() {
  local published="akkudoktor/eos:${EOS_VERSION}" local_tag="akkudoktor/eos:${EOS_VERSION}-local"
  if [ "${1:-}" = "pull" ] || ! docker image inspect "$published" >/dev/null 2>&1; then
    say "Suche fertiges Image ${published} auf Docker Hub ..."
    if docker pull "$published" >/dev/null 2>&1; then
      say "Fertiges Image gefunden, kein Build nötig."
    else
      warn "Kein veröffentlichtes Image für ${EOS_VERSION} (bei Release-Kandidaten normal). Es wird aus dem Git-Tag ${EOS_GIT_REF} gebaut."
      published=""
    fi
  fi
  EOS_IMAGE="${published:-$local_tag}"
  if grep -q '^EOS_IMAGE=' .env; then sedi "s#^EOS_IMAGE=.*#EOS_IMAGE=${EOS_IMAGE}#" .env; else printf 'EOS_IMAGE=%s\n' "$EOS_IMAGE" >> .env; fi
  export EOS_IMAGE
}

wait_health() {
  say "Warte auf EOS unter ${API}/v1/health (bis zu 3 Minuten, erster Start ist langsam) ..."
  for _ in $(seq 1 90); do
    if out=$(curl -fsS "${API}/v1/health" 2>/dev/null); then
      if has_python; then
        echo "$out" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(f"EOS {d.get(\"version\")} ist erreichbar. Letzter EMS-Lauf: {d.get(\"energy-management\",{}).get(\"last_run_datetime\")}")' 2>/dev/null || echo "$out"
      else
        echo "$out"
      fi
      return 0
    fi
    sleep 2
  done
  echo; docker compose logs --tail=40 eos || true
  fail "EOS antwortet nicht. Log oben prüfen ('./setup.sh logs')."
}

start_container() {
  if [ "$EOS_IMAGE" = "akkudoktor/eos:${EOS_VERSION}-local" ]; then
    if [ "${1:-}" = "rebuild" ] || ! docker image inspect "$EOS_IMAGE" >/dev/null 2>&1; then
      say "Baue Image aus Git-Ref '${EOS_GIT_REF}' (erster Build 5-15 Minuten, lädt Python-Pakete) ..."
      if [ "${1:-}" = "rebuild" ]; then DOCKER_BUILDKIT=1 docker compose build --pull --no-cache; else DOCKER_BUILDKIT=1 docker compose build --pull; fi
    fi
  fi
  say "Starte Container ..."
  docker compose up -d --no-build
}

print_urls() {
  cat <<MSG

  API / Swagger : ${API}/docs
  EOSdash       : ${DASH}
  Health        : ${API}/v1/health
  Plan          : ${API}/v1/energy-management/plan   (404 bis zum ersten erfolgreichen Lauf)

  Von Symcon aus: Host = IP dieses Rechners, Port ${EOS_SERVER__PORT:-8503}.
  Läuft Symcon selbst als Container auf demselben Rechner: macOS/Windows 'host.docker.internal',
  Linux die IP des Rechners (oder dem Symcon-Container 'extra_hosts: host.docker.internal:host-gateway' geben).

  Nächster Schritt: in Symcon die Instanz „EOS Server“ anlegen, dort Standort, Tarif und Provider setzen
  (README, Abschnitt „Von null zum ersten Plan“). './setup.sh config' lädt alternativ die PoC-Vorlage.
MSG
}

cmd_up()     { need_docker; ensure_env; choose_image; start_container; wait_health; print_urls; }
cmd_update() { need_docker; ensure_env; choose_image pull; start_container rebuild; wait_health; }

cmd_config() {
  need_docker; ensure_env
  file="${1:-eos-config-poc.json}"
  [ -f "$file" ] || fail "Datei '$file' nicht gefunden."
  if has_python; then
    python3 -m json.tool "$file" >/dev/null || fail "'$file' ist kein gültiges JSON."
    # Schlüssel, die mit "_" beginnen (eigene Notizen), werden nicht an EOS gesendet.
    payload=$(python3 -c 'import json,sys; c=json.load(open(sys.argv[1])); print(json.dumps({k:v for k,v in c.items() if not k.startswith("_")}))' "$file")
  else
    warn "python3 fehlt: Datei wird ungeprüft und ohne Filterung der _-Schlüssel gesendet."
    payload=$(cat "$file")
  fi
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
  *) sed -n '2,10p' "$0"; exit 1 ;;
esac
