import net from 'node:net';
import path from 'node:path';

/** Raíz del proyecto: funciona igual desde `src/` (tsx) y desde `dist/` (compilado). */
export const ROOT = path.resolve(import.meta.dirname, '..');

export interface Config {
  env: 'development' | 'production' | 'test';
  host: string;
  port: number;
  databaseUrl: string;
  /**
   * Certificado (PEM) de la autoridad que firmó el certificado TLS de la base de datos. Con él la conexión se cifra Y se
   * verifica al servidor. Hace falta con Supabase, cuya CA no viene en el almacén de Node. Es un certificado público.
   */
  databaseSslCa: string | undefined;
  poolMax: number;
  /** Marca las cookies como `Secure`; actívalo siempre detrás de HTTPS. */
  cookieSecure: boolean;
  /**
   * Qué proxies se creen para leer la IP real del visitante (X-Forwarded-For). `false`: ninguno. Un número: cuántos
   * saltos de proxy hay delante (se cuentan desde la app hacia afuera). Una lista de IP/CIDR: solo esos proxies.
   * `true` confía en todos y NO es seguro detrás de un proxy que añade la IP en lugar de reemplazarla.
   */
  trustProxy: boolean | number | string[];
  runMigrations: boolean;
  /** Carga el contenido inicial si la base de datos está vacía. */
  seedOnEmpty: boolean;
  /** Zona horaria que define cuándo empieza un "día" en el contador de visitas. */
  statsTimezone: string;
  /** Intentos de login permitidos por IP cada 15 minutos antes de responder 429. */
  loginMaxAttempts: number;
  adminUsername: string | undefined;
  adminPassword: string | undefined;
}

const truthy = (v: string | undefined, fallback: boolean): boolean =>
  v === undefined || v === '' ? fallback : ['1', 'true', 'yes', 'on'].includes(v.toLowerCase());

/**
 * TRUST_PROXY decide en quién confiar para saber la IP del visitante, de la que dependen el límite de intentos de
 * login y el contador de visitas únicas. Aceptar `true` a ciegas es peligroso: con `true` se toma la IP MÁS A LA
 * IZQUIERDA de X-Forwarded-For, y un visitante puede enviar esa cabecera con la IP que quiera para evadir el límite.
 * Con un número (p. ej. 1) solo se confía en ese número de proxies y se ignora lo que el cliente escriba antes.
 *
 *   false | (vacío) | 0   no hay proxy delante
 *   true | yes | on       confía en todos (solo si sabes que tu proxy REEMPLAZA la cabecera)
 *   1, 2, 3…              número de proxies de confianza delante de la app
 *   10.0.0.0/8,172.16.0.1 lista de IP o CIDR de los proxies de confianza
 */
/** ¿Es una IP (v4 o v6) válida, con o sin prefijo CIDR dentro de rango? */
function isIpOrCidr(item: string): boolean {
  const [address = '', prefix, ...extra] = item.split('/');
  const version = net.isIP(address);
  if (version === 0 || extra.length > 0) return false;
  if (prefix === undefined) return true;
  return /^\d{1,3}$/.test(prefix) && Number(prefix) <= (version === 4 ? 32 : 128);
}

export function parseTrustProxy(raw: string | undefined): boolean | number | string[] {
  const value = (raw ?? '').trim();
  if (value === '') return false;
  const lower = value.toLowerCase();
  if (['true', 'yes', 'on'].includes(lower)) return true;
  if (['false', 'no', 'off', '0'].includes(lower)) return false;
  if (/^\d+$/.test(value)) return Number(value);
  const list = value.split(',').map((item) => item.trim()).filter(Boolean);
  if (list.length > 0 && list.every(isIpOrCidr)) return list;
  throw new Error(
    `TRUST_PROXY no es válido: "${raw}". Usa true, false, un número de proxies (p. ej. 1) o una lista de IP/CIDR separadas por comas.`,
  );
}

/**
 * Convierte TRUST_PROXY a lo que Fastify acepta. Un número de saltos se expresa como función (`hop` es 0 para la
 * conexión directa, 1 para el proxy anterior, y así): se confía en los primeros N saltos y no en lo que el cliente
 * haya escrito antes en X-Forwarded-For. Los tipos de Fastify no declaran el número, aunque lo entienda.
 */
export function toFastifyTrustProxy(value: boolean | number | string[]): boolean | string[] | ((address: string, hop: number) => boolean) {
  return typeof value === 'number' ? (_address, hop) => hop < value : value;
}

/** DATABASE_SSL_CA: el PEM completo. Acepta también una sola línea con `\n` literales (como se pega en muchos paneles). */
export function parseSslCa(raw: string | undefined): string | undefined {
  const value = (raw ?? '').trim();
  if (value === '') return undefined;
  const pem = value.replace(/\\n/g, '\n');
  if (!/-----BEGIN CERTIFICATE-----[\s\S]+-----END CERTIFICATE-----/.test(pem)) {
    throw new Error('DATABASE_SSL_CA debe contener el certificado completo en formato PEM (-----BEGIN CERTIFICATE----- … -----END CERTIFICATE-----).');
  }
  return pem;
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const nodeEnv = (env.NODE_ENV ?? 'development') as Config['env'];
  const databaseUrl = env.DATABASE_URL;
  if (!databaseUrl) {
    throw new Error('Falta DATABASE_URL. Copia .env.example a .env y ajusta la conexión a PostgreSQL.');
  }
  const statsTimezone = env.STATS_TIMEZONE || 'America/Hermosillo';
  try {
    new Intl.DateTimeFormat('en-CA', { timeZone: statsTimezone });
  } catch {
    throw new Error(`STATS_TIMEZONE no es una zona horaria válida: "${statsTimezone}" (ejemplo: America/Hermosillo).`);
  }
  return {
    env: nodeEnv,
    // En desarrollo solo local: así el panel no queda expuesto a la red. En producción el contenedor
    // necesita escuchar en todas las interfaces (el puerto lo publica Docker o el proxy).
    host: env.HOST ?? (nodeEnv === 'production' ? '0.0.0.0' : '127.0.0.1'),
    port: Number(env.PORT ?? 3000),
    databaseUrl,
    databaseSslCa: parseSslCa(env.DATABASE_SSL_CA),
    poolMax: Number(env.DB_POOL_MAX ?? 10),
    cookieSecure: truthy(env.COOKIE_SECURE, nodeEnv === 'production'),
    trustProxy: parseTrustProxy(env.TRUST_PROXY),
    runMigrations: truthy(env.RUN_MIGRATIONS, true),
    seedOnEmpty: truthy(env.SEED_ON_EMPTY, false),
    statsTimezone,
    loginMaxAttempts: Number(env.LOGIN_MAX_ATTEMPTS ?? 10),
    adminUsername: env.ADMIN_USERNAME || undefined,
    adminPassword: env.ADMIN_PASSWORD || undefined,
  };
}
