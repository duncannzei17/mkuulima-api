#!/usr/bin/env python3
"""Run the checked PostgreSQL backup helper using the restricted FarmOS env."""

import os
from pathlib import Path
import subprocess


ROOT = Path('/var/www/mkuulima/backend')
BACKUP_DIR = Path('/var/backups/farmos')


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run as root so backup files remain private.')
    values = {}
    for line in (ROOT / '.env').read_text().splitlines():
        if '=' in line and not line.lstrip().startswith('#'):
            key, value = line.split('=', 1)
            values[key] = value.strip().strip('"\'')
    password = values.get('DB_PASSWORD')
    if not password:
        raise SystemExit('DB_PASSWORD is missing.')
    BACKUP_DIR.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(BACKUP_DIR, 0o700)
    env = os.environ.copy()
    env.update({
        'PGHOST': values.get('DB_HOST', '127.0.0.1'),
        'PGPORT': values.get('DB_PORT', '5432'),
        'PGDATABASE': values.get('DB_DATABASE', 'farmos_master'),
        'PGUSER': values.get('DB_USERNAME', 'farmos'),
        'PGPASSWORD': password,
        'BACKUP_DIR': str(BACKUP_DIR),
        'BACKUP_RETENTION_DAYS': '14',
    })
    subprocess.run([str(ROOT / 'deploy/backup-postgres.sh')], env=env,
                   cwd=ROOT, check=True)


if __name__ == '__main__':
    main()
