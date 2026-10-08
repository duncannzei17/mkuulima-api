#!/bin/sh
set -eu

# Certbot runs deploy hooks only when a certificate was renewed successfully.
# Validate all vhosts before reloading the shared Nginx process.
nginx -t
systemctl reload nginx
