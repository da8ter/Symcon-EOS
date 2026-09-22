#!/usr/bin/env bash
# Alter Name. Das Skript ist plattformneutral geworden: ./setup.sh
exec "$(dirname "$0")/setup.sh" "$@"
