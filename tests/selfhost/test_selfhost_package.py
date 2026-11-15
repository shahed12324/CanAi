"""Static checks of the self-host package (Phase 28.5). Real Docker/VPS behaviour is NOT exercised here."""
import re
import shutil
import subprocess
from pathlib import Path

import pytest
import yaml

ROOT = Path(__file__).resolve().parents[2]
SH = ROOT / "selfhost"
COMPOSE = yaml.safe_load((SH / "docker-compose.yml").read_text())
SERVICES = COMPOSE["services"]
SCRIPTS = sorted(SH.glob("*.sh")) + sorted((SH / "local-worker").glob("*.sh"))
PUBLIC = {"nginx", "caddy"}


def env_of(name):
    e = SERVICES[name].get("environment") or {}
    return e if isinstance(e, dict) else dict(x.split("=", 1) for x in e)


def test_only_the_front_door_publishes_ports():
    for name, svc in SERVICES.items():
        if name not in PUBLIC:
            assert "ports" not in svc, f"{name} must not publish ports"
    assert SERVICES["nginx"]["ports"] == ["${WEB_BIND:-0.0.0.0:80}:80"]


def test_stateful_services_have_volumes_and_restart():
    for n in ("postgres", "redis", "ai-engine", "web", "horizon", "scheduler", "nginx"):
        assert SERVICES[n]["restart"] == "unless-stopped", n
    assert any("pgdata" in v for v in SERVICES["postgres"]["volumes"])
    assert any("redisdata" in v for v in SERVICES["redis"]["volumes"])
    for v in ("pgdata", "redisdata", "appstorage", "caddydata"):
        assert v in COMPOSE["volumes"]


def test_runtime_uses_the_restricted_app_role_and_only_migrate_uses_owner():
    for n in ("web", "horizon", "scheduler"):
        assert env_of(n)["DB_USERNAME"] == "jarvis_app", n
        assert "OWNER" not in env_of(n)["DB_PASSWORD"], n
        assert env_of(n)["DB_ADMIN_USERNAME"] == "jarvis_admin_login"
    assert env_of("migrate")["DB_USERNAME"] == "jarvis_owner"
    assert "DB_OWNER_PASSWORD" in env_of("migrate")["DB_PASSWORD"]
    assert SERVICES["migrate"]["profiles"] == ["setup"] and SERVICES["provision"]["profiles"] == ["setup"]


def test_every_secret_is_required_not_defaulted():
    text = (SH / "docker-compose.yml").read_text()
    for var in ("APP_KEY", "DB_PASSWORD", "DB_OWNER_PASSWORD", "DB_APP_PASSWORD", "DB_ADMIN_PASSWORD", "REDIS_PASSWORD", "AI_ENGINE_HMAC_SECRET", "AI_ENGINE_JWT_SECRET", "AI_VAULT_KEY_1"):
        assert "${" + var + ":?" in text, var
        assert not re.search(r"\$\{" + var + r":-", text), f"{var} must not have a default"


def test_services_that_need_the_database_wait_for_health():
    for n in ("web", "horizon", "scheduler", "migrate"):
        d = SERVICES[n]["depends_on"]
        assert d["postgres"]["condition"] == "service_healthy" and d["redis"]["condition"] == "service_healthy", n
    assert SERVICES["provision"]["depends_on"]["postgres"]["condition"] == "service_healthy"


def test_web_services_share_one_image_and_the_storage_volume():
    imgs = {SERVICES[n]["image"] for n in ("web", "horizon", "scheduler", "migrate")}
    assert len(imgs) == 1
    for n in ("web", "horizon", "scheduler"):
        assert "appstorage:/var/www/html/storage" in SERVICES[n]["volumes"]
    assert SERVICES["horizon"]["command"] == ["php", "artisan", "horizon"]
    assert SERVICES["scheduler"]["command"] == ["php", "artisan", "schedule:work"]


def test_engine_can_reach_host_ollama_but_is_internal_only():
    assert "host.docker.internal:host-gateway" in SERVICES["ai-engine"]["extra_hosts"]
    assert env_of("web")["AI_ENGINE_URL"] == "http://ai-engine:8000"


def test_https_is_an_optional_profile():
    assert SERVICES["caddy"]["profiles"] == ["https"]
    assert "reverse_proxy nginx:80" in (SH / "docker" / "Caddyfile").read_text()


def test_installer_generates_every_variable_compose_requires():
    inst = (SH / "install.sh").read_text()
    for var in re.findall(r"\$\{([A-Z_0-9]+):\?", (SH / "docker-compose.yml").read_text()):
        assert re.search(rf"^{var}=", inst, re.M), f"install.sh does not generate {var}"
    assert "umask 077" in inst
    assert "openssl rand" in inst
    assert re.search(r"if \[ ! -f \.env \]", inst), ".env must be created only once"


def test_installer_order_provision_before_migrate_before_up():
    inst = (SH / "install.sh").read_text()
    a, b, c = inst.index("run --rm provision"), inst.index("run --rm migrate"), inst.index('"${DC[@]}" up -d\n')
    assert a < b < c


def test_installer_validates_user_input():
    inst = (SH / "install.sh").read_text()
    assert "invalid --domain" in inst and "invalid --email" in inst and "licence key looks wrong" in inst


@pytest.mark.parametrize("script", SCRIPTS, ids=lambda p: p.name)
def test_scripts_are_strict_executable_and_parse(script):
    txt = script.read_text()
    assert txt.startswith("#!/usr/bin/env bash")
    assert re.search(r"^set -(Eeuo pipefail|u)$", txt, re.M), "strict mode missing"
    assert script.stat().st_mode & 0o111, "not executable"
    subprocess.run(["bash", "-n", str(script)], check=True)
    assert not re.search(r"curl[^|\n]*\|\s*(sudo )?(ba)?sh", txt), "pipe-to-shell"


def test_shellcheck_if_available():
    if not shutil.which("shellcheck"):
        pytest.skip("shellcheck not installed")
    r = subprocess.run(["shellcheck", "-S", "warning", *map(str, SCRIPTS)], capture_output=True, text=True)
    assert r.returncode == 0, r.stdout


def test_backup_keeps_secrets_private_and_prunes():
    b = (SH / "backup.sh").read_text()
    assert "umask 077" in b and "tail -n +15" in b and 'cp .env "$DEST/env-$STAMP"' in b
    assert "empty dump" in b


def test_restore_requires_confirmation_and_reasserts_roles():
    r = (SH / "restore.sh").read_text()
    assert "Type RESTORE" in r and "--yes" in r
    assert re.search(r'\[ "\$a" = RESTORE \] \|\| \{ echo aborted; exit 1; \}', r), "answer must be enforced"
    assert r.index("pg_restore") < r.index("run --rm provision")


def test_update_backs_up_first_and_never_overwrites_env():
    u = (SH / "update.sh").read_text()
    assert u.index("./backup.sh") < u.index("rsync") < u.index("run --rm migrate")
    assert "--exclude '.env'" in u


def test_web_image_runs_unprivileged_and_sees_env():
    d = (SH / "docker" / "web.Dockerfile").read_text()
    assert re.search(r"^USER www-data$", d, re.M)
    assert "clear_env = no" in d
    assert "--no-dev" in d
    assert "target: nginx" in (SH / "docker-compose.yml").read_text()


def test_dockerignore_keeps_secrets_out_of_the_build_context():
    di = (ROOT / ".dockerignore").read_text().split()
    assert ".env" in di and "**/.env" in di and "apps/web" in di


def test_local_worker_ollama_is_bound_to_localhost_only():
    w = yaml.safe_load((SH / "local-worker" / "docker-compose.yml").read_text())
    assert w["services"]["ollama"]["ports"] == ["127.0.0.1:11434:11434"]
    assert "invalid model name" in (SH / "local-worker" / "setup.sh").read_text()
