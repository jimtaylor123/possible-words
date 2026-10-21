#!/bin/sh

set -eu

ACTION=${1:-}
REPO_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
SCOPE_ID=possiblewords-69a627f787b9
LABEL=com.opencode.job.$SCOPE_ID.pr-manager
SCHEDULER_DIR="$HOME/.config/opencode/scheduler"
JOB_FILE="$SCHEDULER_DIR/scopes/$SCOPE_ID/jobs/pr-manager.json"
PLIST_FILE="$HOME/Library/LaunchAgents/$LABEL.plist"
SUPERVISOR="$SCHEDULER_DIR/supervisor.pl"
DOMAIN="gui/$(id -u)"

require_supervisor() {
    if [ ! -f "$SUPERVISOR" ]; then
        echo "OpenCode's scheduler is not installed at $SUPERVISOR." >&2
        exit 1
    fi
}

unload() {
    launchctl bootout "$DOMAIN/$LABEL" 2>/dev/null || true
}

create_job() {
    command -v opencode >/dev/null 2>&1 || {
        echo "OpenCode is not available on PATH." >&2
        exit 1
    }

    mkdir -p "$(dirname "$JOB_FILE")" "$(dirname "$PLIST_FILE")"
    now=$(date -u '+%Y-%m-%dT%H:%M:%SZ')

    printf '%s\n' \
        "{\"createdAt\":\"$now\",\"invocation\":{\"args\":[\"run\",\"--command\",\"pr-manager\",\"--\",\"\"],\"command\":\"$(command -v opencode)\"},\"name\":\"pr-manager\",\"run\":{\"command\":\"pr-manager\"},\"schedule\":\"*/10 * * * *\",\"scopeId\":\"$SCOPE_ID\",\"slug\":\"pr-manager\",\"timeoutSeconds\":600,\"updatedAt\":\"$now\",\"workdir\":\"$REPO_DIR\"}" \
        > "$JOB_FILE"

    cat > "$PLIST_FILE" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>$LABEL</string>
  <key>WorkingDirectory</key>
  <string>$REPO_DIR</string>
  <key>EnvironmentVariables</key>
  <dict>
    <key>PATH</key>
    <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
  </dict>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/bin/perl</string>
    <string>$SUPERVISOR</string>
    <string>$JOB_FILE</string>
  </array>
  <key>StartCalendarInterval</key>
  <array>
    <dict><key>Minute</key><integer>0</integer></dict>
    <dict><key>Minute</key><integer>10</integer></dict>
    <dict><key>Minute</key><integer>20</integer></dict>
    <dict><key>Minute</key><integer>30</integer></dict>
    <dict><key>Minute</key><integer>40</integer></dict>
    <dict><key>Minute</key><integer>50</integer></dict>
  </array>
  <key>StandardOutPath</key>
  <string>$HOME/.config/opencode/logs/scheduler/$SCOPE_ID/pr-manager.log</string>
  <key>StandardErrorPath</key>
  <string>$HOME/.config/opencode/logs/scheduler/$SCOPE_ID/pr-manager.log</string>
  <key>RunAtLoad</key>
  <false/>
</dict>
</plist>
EOF
}

case "$ACTION" in
    start)
        require_supervisor
        unload
        create_job
        launchctl bootstrap "$DOMAIN" "$PLIST_FILE"
        echo "pr-manager is scheduled every 10 minutes."
        ;;
    pause)
        unload
        echo "pr-manager is paused."
        ;;
    restart)
        require_supervisor
        unload
        if [ ! -f "$JOB_FILE" ] || [ ! -f "$PLIST_FILE" ]; then
            create_job
        fi
        launchctl bootstrap "$DOMAIN" "$PLIST_FILE"
        echo "pr-manager is scheduled every 10 minutes."
        ;;
    stop)
        unload
        rm -f "$JOB_FILE" "$PLIST_FILE"
        echo "pr-manager has been deleted."
        ;;
    *)
        echo "Usage: $0 {start|pause|restart|stop}" >&2
        exit 2
        ;;
esac
