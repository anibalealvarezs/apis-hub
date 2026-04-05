#!/bin/bash
# Helper script to find available ports on the host.
# Usage: sh bin/check-ports.sh <start_port> <count>

START_PORT=${1:-8080}
COUNT=${2:-1}
FOUND=0

NEXT_PORT=$START_PORT
while [ $FOUND -lt $COUNT ]; do
    if ! ss -tuln | grep -q ":$NEXT_PORT "; then
        echo $NEXT_PORT
        FOUND=$((FOUND + 1))
    fi
    NEXT_PORT=$((NEXT_PORT + 1))
done
