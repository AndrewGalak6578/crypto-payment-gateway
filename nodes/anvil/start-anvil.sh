#!/bin/sh
set -e

mkdir -p /data

if [ -f /data/anvil-state.json ]; then
  exec anvil \
    --host 0.0.0.0 \
    --port 8545 \
    --chain-id 31337 \
    --accounts 100 \
    --load-state /data/anvil-state.json \
    --dump-state /data/anvil-state.json
else
  exec anvil \
    --host 0.0.0.0 \
    --port 8545 \
    --chain-id 31337 \
    --accounts 100 \
    --dump-state /data/anvil-state.json
fi
