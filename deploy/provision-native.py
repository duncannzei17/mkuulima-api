#!/usr/bin/env python3
"""Provision isolated FarmOS secrets, PostgreSQL and Redis on the target host."""

import base64
import grp
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import time


ROOT = Path('/var/www/mkuulima/backend')
ENV_FILE = ROOT / '.env'
REDIS_CONFIG = Path('/etc/redis/farmos.conf')
REDIS_DATA = Path('/var/lib/redis-farmos')
REDIS_UNIT = Path('/etc/systemd/system/farmos-redis.service')
DOMAIN = 'mkuulima.online'


def run(command, *, input_text=None, env=None):
    return subprocess.run(command, input=input_text, text=True, check=True,
                          capture_output=True, env=env)


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run as root on the FarmOS host.')
    if any(path.exists() for path in (ENV_FILE, REDIS_CONFIG, REDIS_UNIT)):
        raise SystemExit('FarmOS secrets or Redis service already exist; refusing to overwrite.')
    for kind, name in (('pg_roles', 'rolname'), ('pg_database', 'datname')):
        value = 'farmos' if kind == 'pg_roles' else 'farmos_master'
        result = run(['runuser', '-u', 'postgres', '--', 'psql', '-Atqc',
                      f"SELECT 1 FROM {kind} WHERE {name} = '{value}'"])
        if result.stdout.strip():
            raise SystemExit(f'PostgreSQL {value} already exists; refusing to overwrite.')
    if not ROOT.is_dir():
        raise SystemExit('Backend checkout is missing.')

    app_key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
    db_password = secrets.token_urlsafe(36)
    redis_password = secrets.token_urlsafe(36)
    template = (ROOT / 'deploy/native.env.example').read_text()
    replacements = {
        '__FARMOS_DOMAIN__': DOMAIN,
        'APP_KEY=': f'APP_KEY={app_key}',
        'DB_PASSWORD=': f'DB_PASSWORD={db_password}',
        'TENANT_DB_PASSWORD=': f'TENANT_DB_PASSWORD={db_password}',
        'REDIS_PASSWORD=': f'REDIS_PASSWORD={redis_password}',
        'MAIL_MAILER=smtp': 'MAIL_MAILER=log',
    }
    for old, new in replacements.items():
        if old not in template:
            raise SystemExit(f'Missing expected environment template field: {old}')
        template = template.replace(old, new) if old == '__FARMOS_DOMAIN__' else template.replace(old, new, 1)

    redis_template = (ROOT / 'deploy/farmos-redis.conf.template').read_text()
    redis_template = redis_template.replace('__FARMOS_REDIS_PASSWORD__', redis_password)
    REDIS_DATA.mkdir(mode=0o700)
    os.chown(REDIS_DATA, 0, grp.getgrnam('redis').gr_gid)
    os.chmod(REDIS_DATA, 0o770)

    sql = f"CREATE ROLE farmos LOGIN PASSWORD '{db_password}';\nCREATE DATABASE farmos_master OWNER farmos;\n"
    run(['runuser', '-u', 'postgres', '--', 'psql', '-v', 'ON_ERROR_STOP=1',
         '-d', 'postgres'], input_text=sql)

    fd = os.open(ENV_FILE, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    with os.fdopen(fd, 'w') as handle:
        handle.write(template)
    os.chown(ENV_FILE, 0, grp.getgrnam('www-data').gr_gid)
    os.chmod(ENV_FILE, 0o640)

    fd = os.open(REDIS_CONFIG, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    with os.fdopen(fd, 'w') as handle:
        handle.write(redis_template)
    os.chown(REDIS_CONFIG, 0, grp.getgrnam('redis').gr_gid)
    os.chmod(REDIS_CONFIG, 0o640)
    shutil.copyfile(ROOT / 'deploy/farmos-redis.service', REDIS_UNIT)
    os.chmod(REDIS_UNIT, 0o644)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'farmos-redis.service'])

    db_env = os.environ.copy()
    db_env['PGPASSWORD'] = db_password
    run(['psql', '-h', '127.0.0.1', '-U', 'farmos', '-d', 'farmos_master',
         '-Atqc', 'SELECT 1'], env=db_env)
    redis_env = os.environ.copy()
    redis_env['REDISCLI_AUTH'] = redis_password
    for attempt in range(20):
        try:
            result = run(['redis-cli', '-h', '127.0.0.1', '-p', '6380', 'ping'],
                         env=redis_env)
            if result.stdout.strip() == 'PONG':
                break
        except subprocess.CalledProcessError:
            pass
        time.sleep(0.25)
    else:
        raise SystemExit('FarmOS Redis did not become ready after startup.')
    print('FarmOS PostgreSQL, isolated Redis and restricted .env provisioned.')
    print('SMTP remains set to log until mail credentials are configured.')


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as exc:
        print(f'Provisioning command failed: {exc.cmd[0]} (exit {exc.returncode}).',
              file=sys.stderr)
        raise SystemExit(exc.returncode)
