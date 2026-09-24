import crypto from 'node:crypto';
import { promisify } from 'node:util';
import type { Pool } from './db.js';

const scrypt = promisify(crypto.scrypt) as (
  password: string, salt: Buffer, keylen: number, options: crypto.ScryptOptions,
) => Promise<Buffer>;

// Parámetros de scrypt: N=2^15 (~32 MB de memoria), r=8, p=3. Es uno de los perfiles equivalentes que
// recomienda OWASP (junto con N=2^17/p=1 y N=2^16/p=2); elegí este por su menor uso de memoria en un
// contenedor pequeño. Los parámetros se guardan junto al hash, así que subirlos más adelante no
// invalida las contraseñas ya guardadas.
const SCRYPT = { N: 2 ** 15, r: 8, p: 3, maxmem: 128 * 1024 * 1024 } as const;
const KEYLEN = 64;

export const MIN_PASSWORD_LENGTH = 12;
export const SESSION_TTL_MS = 12 * 60 * 60 * 1000;

/** Formato guardado: scrypt$N$r$p$sal(base64)$hash(base64). Los parámetros viajan con el hash. */
export async function hashPassword(password: string): Promise<string> {
  const salt = crypto.randomBytes(16);
  const hash = await scrypt(password, salt, KEYLEN, SCRYPT);
  return ['scrypt', SCRYPT.N, SCRYPT.r, SCRYPT.p, salt.toString('base64'), hash.toString('base64')].join('$');
}

export async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const parts = stored.split('$');
  if (parts.length !== 6 || parts[0] !== 'scrypt') return false;
  const [, n, r, p, saltB64, hashB64] = parts as [string, string, string, string, string, string];
  const expected = Buffer.from(hashB64, 'base64');
  const actual = await scrypt(password, Buffer.from(saltB64, 'base64'), expected.length, {
    N: Number(n), r: Number(r), p: Number(p), maxmem: SCRYPT.maxmem,
  });
  return actual.length === expected.length && crypto.timingSafeEqual(actual, expected);
}

// Hash de relleno: al fallar por usuario inexistente se gasta el mismo tiempo que con uno real.
let dummyHash: Promise<string> | null = null;
export function verifyAgainstDummy(password: string): Promise<boolean> {
  dummyHash ??= hashPassword(crypto.randomBytes(16).toString('hex'));
  return dummyHash.then((h) => verifyPassword(password, h));
}

export function safeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  return ab.length === bb.length && crypto.timingSafeEqual(ab, bb);
}

const sha256 = (value: string): string => crypto.createHash('sha256').update(value).digest('hex');
export const randomToken = (bytes = 32): string => crypto.randomBytes(bytes).toString('base64url');

export interface AdminSession {
  userId: number;
  username: string;
  csrf: string;
}

/** Crea una sesión y devuelve el token en claro (solo viaja en la cookie; en la BD queda su hash). */
export async function createSession(pool: Pool, userId: number): Promise<{ token: string; csrf: string }> {
  const token = randomToken();
  const csrf = randomToken(24);
  await pool.query('delete from sessions where expires_at < now()');
  await pool.query(
    'insert into sessions (token_hash, user_id, csrf, expires_at) values ($1, $2, $3, $4)',
    [sha256(token), userId, csrf, new Date(Date.now() + SESSION_TTL_MS)],
  );
  return { token, csrf };
}

export async function getSession(pool: Pool, token: string | undefined): Promise<AdminSession | null> {
  if (!token) return null;
  const { rows } = await pool.query<{ user_id: number; username: string; csrf: string }>(
    `select s.user_id, u.username, s.csrf
       from sessions s join admin_users u on u.id = s.user_id
      where s.token_hash = $1 and s.expires_at > now()`,
    [sha256(token)],
  );
  const row = rows[0];
  return row ? { userId: row.user_id, username: row.username, csrf: row.csrf } : null;
}

export async function destroySession(pool: Pool, token: string | undefined): Promise<void> {
  if (token) await pool.query('delete from sessions where token_hash = $1', [sha256(token)]);
}

/** Cierra todas las sesiones del usuario salvo la indicada (p. ej. tras cambiar la contraseña). */
export async function destroyOtherSessions(pool: Pool, userId: number, keepToken: string | undefined): Promise<void> {
  await pool.query('delete from sessions where user_id = $1 and token_hash <> $2', [
    userId, keepToken ? sha256(keepToken) : '',
  ]);
}

/** Crea el usuario administrador inicial si todavía no existe ninguno. */
export async function ensureAdmin(pool: Pool, username: string, password: string): Promise<boolean> {
  const { rows } = await pool.query<{ n: string }>('select count(*)::text as n from admin_users');
  if (rows[0]?.n !== '0') return false;
  if (password.length < MIN_PASSWORD_LENGTH) {
    throw new Error(`ADMIN_PASSWORD debe tener al menos ${MIN_PASSWORD_LENGTH} caracteres.`);
  }
  await pool.query('insert into admin_users (username, password_hash) values ($1, $2)', [
    username, await hashPassword(password),
  ]);
  return true;
}
