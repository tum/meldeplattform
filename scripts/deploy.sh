#!/usr/bin/env bash
#
# Production deploy for the LRZ shared hosting (webdev02:~/webserver/htdocs).
#
# Mirrors the manual routine:
#   git pull  ->  composer install  ->  artisan migrate  ->  caches
# but non-interactively (migrate --force) and with the app in maintenance
# mode while vendor/ and the DB schema are in flux.
#
# Usage:
#   ./scripts/deploy.sh [options]
#
# Options:
#   --branch=NAME     branch to deploy (default: main)
#   --no-dev          drop require-dev packages from vendor/ (README's
#                     recommendation; off by default so the deploy does not
#                     silently change what is installed on the server)
#   --skip-pull       do not touch git, deploy the checked-out tree as is
#   --skip-composer   do not run composer install
#   --skip-migrate    do not run migrations
#   --no-down         keep the site online during the deploy
#   --allow-dirty     proceed even with local modifications in the work tree
#   --dry-run         print every command instead of running it
#   -h, --help        this text
#
# Environment overrides:
#   APP_DIR, PHP_BIN (default php8.4), COMPOSER_PHAR, BRANCH
#
# The whole flow lives in main(), which is only called on the last line: the
# deploy pulls the very repository this file sits in, and bash reads scripts
# lazily — this way the file is fully parsed before it can change underneath
# the running process.
#

# This script needs bash (arrays, local, BASH_SOURCE). Invoked as
# `sh scripts/deploy.sh` the host's dash dies on `set -o pipefail`, so hand
# ourselves over to bash before anything else runs.
if [ -z "${BASH_VERSION:-}" ]; then
    if command -v bash >/dev/null 2>&1; then
        exec bash "$0" "$@"
    fi
    echo "This script requires bash, which was not found in PATH." >&2
    exit 1
fi

set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-php8.4}"
COMPOSER_PHAR="${COMPOSER_PHAR:-$APP_DIR/../bin/composer.phar}"
BRANCH="${BRANCH:-main}"

NO_DEV=0
SKIP_PULL=0
SKIP_COMPOSER=0
SKIP_MIGRATE=0
MAINTENANCE=1
ALLOW_DIRTY=0
DRY_RUN=0
DOWN_ACTIVE=0
COMPOSER_CMD=()
LOG_FILE="$APP_DIR/storage/logs/deploy.log"

if [ -t 1 ]; then
    C_INFO=$'\033[34m'; C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_OFF=$'\033[0m'
else
    C_INFO=''; C_OK=''; C_WARN=''; C_ERR=''; C_OFF=''
fi

# ---------------------------------------------------------------- logging ---

_log() { # level colour message…
    local level="$1" colour="$2"; shift 2
    local line
    line="$(date '+%Y-%m-%d %H:%M:%S') [$level] $*"
    printf '%s%s%s\n' "$colour" "$line" "$C_OFF"
    [ -w "$(dirname "$LOG_FILE")" ] && printf '%s\n' "$line" >>"$LOG_FILE" || true
}
log()  { _log INFO "$C_INFO" "$@"; }
ok()   { _log  OK  "$C_OK"   "$@"; }
warn() { _log WARN "$C_WARN" "$@"; }
die()  { _log FAIL "$C_ERR"  "$@"; exit 1; }

run() { # echo the command, then run it unless --dry-run
    log "\$ $*"
    [ "$DRY_RUN" -eq 1 ] && return 0
    "$@"
}

usage() {
    awk 'NR>1 { if (!/^#/) exit; sub(/^# ?/, ""); print }' "${BASH_SOURCE[0]}"
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            --branch=*)      BRANCH="${arg#*=}" ;;
            --no-dev)        NO_DEV=1 ;;
            --skip-pull)     SKIP_PULL=1 ;;
            --skip-composer) SKIP_COMPOSER=1 ;;
            --skip-migrate)  SKIP_MIGRATE=1 ;;
            --no-down)       MAINTENANCE=0 ;;
            --allow-dirty)   ALLOW_DIRTY=1 ;;
            --dry-run)       DRY_RUN=1 ;;
            -h|--help)       usage; exit 0 ;;
            *)               echo "Unknown option: $arg (try --help)" >&2; exit 2 ;;
        esac
    done
}

# --------------------------------------------------------------- teardown ---

cleanup() {
    local code=$?
    if [ "$DOWN_ACTIVE" -eq 1 ]; then
        log "Leaving maintenance mode"
        if ! "$PHP_BIN" artisan up; then
            warn "'artisan up' failed — the site stays in maintenance mode."
            warn "Fix the deploy and re-run it, or remove $APP_DIR/storage/framework/down manually."
        fi
    fi
    [ "$code" -ne 0 ] && _log FAIL "$C_ERR" "Deploy aborted with exit code $code"
    exit "$code"
}

# -------------------------------------------------------------- preflight ---

preflight() {
    [ -f artisan ] || die "No artisan in $APP_DIR — is APP_DIR pointing at the app root?"
    [ -f .env ]    || die "No .env in $APP_DIR — the app is not configured."
    command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP binary '$PHP_BIN' not found (override with PHP_BIN=…)."

    if [ -f "$COMPOSER_PHAR" ]; then
        COMPOSER_CMD=("$PHP_BIN" "$COMPOSER_PHAR")
    elif command -v composer >/dev/null 2>&1; then
        COMPOSER_CMD=(composer)
        warn "composer.phar not found at $COMPOSER_PHAR — falling back to 'composer' from PATH."
    else
        die "Neither $COMPOSER_PHAR nor a 'composer' in PATH — cannot install dependencies."
    fi

    log "App:      $APP_DIR"
    log "PHP:      $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo '?'))"
    log "Composer: ${COMPOSER_CMD[*]}"

    # The queue must stay 'sync': this host has no persistent worker, so
    # 'database'/'redis' would silently strand notification jobs.
    local queue
    queue="$(grep -E '^[[:space:]]*QUEUE_CONNECTION=' .env | tail -n1 | cut -d= -f2- | tr -d '"'\''[:space:]' || true)"
    if [ -n "$queue" ] && [ "$queue" != "sync" ]; then
        warn "QUEUE_CONNECTION=$queue in .env — no queue worker runs here, jobs will pile up unsent."
        warn "Expected 'sync'. The deploy continues, but please fix this."
    fi

    [ "$SKIP_PULL" -eq 1 ] && return 0

    git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "$APP_DIR is not a git work tree."

    local current_branch
    current_branch="$(git rev-parse --abbrev-ref HEAD)"
    [ "$current_branch" = "$BRANCH" ] || die "On branch '$current_branch', expected '$BRANCH' (override with --branch=)."

    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        git status --short --untracked-files=no | sed 's/^/    /'
        if [ "$ALLOW_DIRTY" -eq 1 ]; then
            warn "Work tree has local modifications — continuing because of --allow-dirty."
        else
            die "Work tree is dirty. Commit/stash on the server or re-run with --allow-dirty."
        fi
    fi
}

# ----------------------------------------------------------------- deploy ---

main() {
    parse_args "$@"
    trap cleanup EXIT
    cd "$APP_DIR"
    preflight

    local before after composer_flags audit_out
    before="$(git rev-parse --short HEAD 2>/dev/null || echo '?')"

    if [ "$MAINTENANCE" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
        log "Entering maintenance mode"
        if "$PHP_BIN" artisan down --retry=60; then
            DOWN_ACTIVE=1
        else
            warn "'artisan down' failed — deploying with the site online."
        fi
    fi

    if [ "$SKIP_PULL" -eq 0 ]; then
        log "Fetching origin/$BRANCH"
        run git fetch --prune origin "$BRANCH"
        run git pull --ff-only origin "$BRANCH"
    fi

    if [ "$SKIP_COMPOSER" -eq 0 ]; then
        composer_flags=(install --no-interaction --no-progress --prefer-dist --optimize-autoloader)
        [ "$NO_DEV" -eq 1 ] && composer_flags+=(--no-dev)

        log "Installing dependencies from composer.lock"
        # Advisories are gated in CI (composer audit --locked); a fresh one must
        # not block a production deploy here. It is reported at the end instead.
        export COMPOSER_NO_AUDIT=1 COMPOSER_MEMORY_LIMIT=-1
        run "${COMPOSER_CMD[@]}" "${composer_flags[@]}"
    fi

    if [ "$SKIP_MIGRATE" -eq 0 ]; then
        log "Running migrations"
        run "$PHP_BIN" artisan migrate --force
    fi

    log "Linking public storage"
    run "$PHP_BIN" artisan storage:link || warn "storage:link failed (the symlink probably exists already)."

    log "Rebuilding caches"
    run "$PHP_BIN" artisan config:cache
    run "$PHP_BIN" artisan route:cache
    run "$PHP_BIN" artisan view:cache

    after="$(git rev-parse --short HEAD 2>/dev/null || echo '?')"
    if [ "$before" = "$after" ]; then
        ok "Deploy finished — no new commits ($after)."
    else
        ok "Deploy finished — $before → $after:"
        git --no-pager log --oneline "$before..$after" 2>/dev/null | sed 's/^/    /' || true
    fi

    # Informational only, never fails the deploy.
    if [ "$DRY_RUN" -eq 0 ]; then
        if audit_out="$("${COMPOSER_CMD[@]}" audit --locked --no-interaction --format=summary 2>&1)"; then
            log "composer audit: no known advisories in composer.lock"
        else
            warn "composer audit found advisories:"
            printf '%s\n' "$audit_out" | sed 's/^/    /'
        fi
    fi
}

main "$@"
