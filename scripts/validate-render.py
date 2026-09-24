#!/usr/bin/env python3
"""Valida render.yaml antes de que Render lo lea.

1. Contra el ESQUEMA OFICIAL de Render (https://render.com/schema/render.yaml.json): nombres de campos, valores de
   plan y de región, forma de las variables de entorno (`key`, no `name`)… Un Blueprint inválido falla recién al
   crearlo en el panel; aquí se detecta antes.
2. Coherencia con este proyecto (cosas que el esquema no puede saber y que, si fallan, duelen en producción).

Uso:  python scripts/validate-render.py [ruta-al-yaml]      (necesita: pip install pyyaml jsonschema)
      RENDER_SCHEMA=archivo.json python scripts/validate-render.py    (usa un esquema local en vez de descargarlo)
"""
import json
import os
import sys
import urllib.request

import yaml
from jsonschema import Draft202012Validator

SCHEMA_URL = "https://render.com/schema/render.yaml.json"
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")  # acentos correctos también en la consola de Windows

path = sys.argv[1] if len(sys.argv) > 1 else "render.yaml"
errors = []


def fail(message):
    errors.append(message)


with open(path, encoding="utf-8") as f:
    doc = yaml.safe_load(f)

if os.environ.get("RENDER_SCHEMA"):
    with open(os.environ["RENDER_SCHEMA"], encoding="utf-8") as f:
        schema = json.load(f)
else:
    with urllib.request.urlopen(SCHEMA_URL, timeout=30) as response:
        schema = json.load(response)

# ---- 1) Esquema oficial ------------------------------------------------------------------------------------------
def deepest(error):
    """Los esquemas con anyOf/oneOf reportan un error genérico en la raíz; el útil está en `context`, más adentro."""
    best = error
    stack = list(error.context)
    while stack:
        candidate = stack.pop()
        if len(candidate.absolute_path) > len(best.absolute_path):
            best = candidate
        stack.extend(candidate.context)
    return best


schema_errors = []
for error in sorted(Draft202012Validator(schema).iter_errors(doc), key=lambda e: list(e.absolute_path)):
    error = deepest(error)
    where = "/".join(str(p) for p in error.absolute_path) or "(raíz)"
    line = f"esquema: {where}: {error.message[:220]}"
    if line not in schema_errors:
        schema_errors.append(line)

# "Unevaluated properties … en la raíz" es solo la consecuencia de otro error más adentro: si hay uno, se omite.
specific = [e for e in schema_errors if "(raíz): Unevaluated properties" not in e]
for line in specific or schema_errors:
    fail(line)

# ---- 2) Coherencia con el proyecto ---------------------------------------------------------------------------------
databases = {d["name"]: d for d in doc.get("databases", [])}
services = doc.get("services", [])
web = next((s for s in services if s.get("type") == "web"), None)

if not web:
    fail("no hay ningún servicio web")
else:
    env = {e.get("key"): e for e in web.get("envVars", []) if "key" in e}

    if web.get("healthCheckPath") != "/healthz":
        fail("healthCheckPath debe ser /healthz (la ruta que comprueba la base de datos)")

    if web.get("runtime") != "docker":
        fail("el servicio debe usar runtime: docker (el proyecto se construye con su Dockerfile)")

    # La conexión a la base de datos: o es un SECRETO que se pide en el panel (base externa, p. ej. Supabase; la URL
    # lleva la contraseña y no puede estar en el repositorio), o sale de una base declarada aquí, en la misma región
    # (red privada de Render).
    url = env.get("DATABASE_URL")
    if url is None:
        fail("falta DATABASE_URL")
    elif "fromDatabase" in url:
        ref = url["fromDatabase"]
        if ref.get("name") not in databases:
            fail(f"DATABASE_URL apunta a la base '{ref.get('name')}', que no está declarada en databases")
        else:
            db = databases[ref["name"]]
            if db.get("region", "oregon") != web.get("region", "oregon"):
                fail("la base y el servicio deben estar en la misma región para usar la red privada")
    elif "value" in url or url.get("sync") is not False:
        fail("DATABASE_URL lleva la contraseña de la base: debe ser `sync: false` (se pide en el panel) o venir de "
             "fromDatabase; nunca un valor escrito en el archivo")

    # Secretos: nunca en el archivo. APP_KEY cifra las cookies y sesiones: si estuviera aquí, cualquiera con acceso al
    # repositorio podría falsificar la sesión del administrador.
    for secret in ("APP_KEY", "ADMIN_USERNAME", "ADMIN_PASSWORD"):
        e = env.get(secret)
        if e is None:
            fail(f"falta {secret}")
        elif "value" in e or e.get("sync") is not False:
            fail(f"{secret} debe ser `sync: false`: se pide en el panel y no se escribe en el repositorio")

    ca = env.get("DATABASE_SSL_CA")
    if ca is not None and "value" in ca and "BEGIN CERTIFICATE" not in str(ca["value"]):
        fail("DATABASE_SSL_CA debe ser el certificado completo en formato PEM (o `sync: false` para pegarlo en el panel)")

    # Seguridad de la IP del visitante y de las cookies.
    trust = str(env.get("TRUST_PROXY", {}).get("value", ""))
    if trust.lower() in ("true", "yes", "on"):
        fail("TRUST_PROXY=true confía en todos los saltos: un visitante puede falsificar su IP y evadir el límite de "
             "intentos de login. Usa un número de proxies (p. ej. \"1\")")
    if not trust:
        fail("falta TRUST_PROXY: detrás de Render todas las peticiones parecerían venir de la misma IP")
    if str(env.get("SESSION_SECURE_COOKIE", {}).get("value", "")).lower() != "true":
        fail("SESSION_SECURE_COOKIE debe ser \"true\" (Render sirve por HTTPS: cookies Secure y HSTS)")
    if str(env.get("APP_ENV", {}).get("value", "")) != "production":
        fail("APP_ENV debe ser production")
    if str(env.get("APP_DEBUG", {}).get("value", "false")).lower() in ("true", "1", "yes", "on"):
        fail("APP_DEBUG no debe ser true en producción: las páginas de error mostrarían variables de entorno y consultas")

# La base gratuita de Render expira a los 30 días y luego se borra con sus datos, sin copias de seguridad.
for name, db in databases.items():
    if db.get("plan") == "free":
        fail(f"la base '{name}' usa el plan free: EXPIRA a los 30 días y se borra con todos sus datos. Usa un plan de pago "
             "(o una base externa); si es solo una prueba desechable, cambia esta comprobación a propósito")

# ---- Resultado ------------------------------------------------------------------------------------------------------
if errors:
    print(f"{path}: {len(errors)} problema(s)")
    for message in errors:
        print(f"  - {message}")
    sys.exit(1)
print(f"{path}: válido (esquema oficial de Render y comprobaciones del proyecto)")
