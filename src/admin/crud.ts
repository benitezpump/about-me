import { withTx } from '../db.js';
import type { Pool } from '../db.js';
import { splitParagraphs } from '../format.js';
import type { ChildResource, Field, Option, Resource } from './resources.js';

type Raw = Record<string, unknown>;
type Errors = Record<string, string>;

const MAX_LEN: Record<string, number> = { text: 300, textarea: 5000, paragraphs: 5000, url: 2000 };
const isBlank = (v: unknown): boolean => v === undefined || v === null || String(v).trim() === '';
const str = (v: unknown): string => (typeof v === 'string' ? v : '').replace(/\r\n/g, '\n').trim();

// --- Opciones de los <select> --------------------------------------------------------------------

export async function loadOptions(pool: Pool, field: Field): Promise<Option[]> {
  if (field.options) return field.options;
  const from = field.optionsFrom;
  if (!from) return [];
  const { rows } = await pool.query<{ value: string | number; label: string }>(
    `select ${from.value} as value, ${from.label} as label from ${from.table}${from.where ? ` where ${from.where}` : ''} order by ${from.orderBy}`,
  );
  return rows.map((r) => ({ value: String(r.value), label: r.label }));
}

export async function optionsForResource(pool: Pool, fields: Field[]): Promise<Map<string, Option[]>> {
  const map = new Map<string, Option[]>();
  for (const f of fields) if (f.type === 'select') map.set(f.name, await loadOptions(pool, f));
  return map;
}

// --- Validación y conversión del formulario -------------------------------------------------------

export function isValidDate(s: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
  const [y, m, d] = s.split('-').map(Number) as [number, number, number];
  const date = new Date(Date.UTC(y, m - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}

/** Solo http(s), mailto y tel: nunca `javascript:` ni `data:` (evita XSS por enlaces). */
export function isSafeUrl(s: string): boolean {
  if (/\s/.test(s)) return false; // la base también rechaza espacios (restricción *_url_check)
  if (/^(mailto|tel):\S+$/i.test(s)) return true;
  try {
    const u = new URL(s);
    return u.protocol === 'https:' || u.protocol === 'http:';
  } catch {
    return false;
  }
}

export interface Parsed {
  values: Record<string, unknown>;
  errors: Errors;
}

export function parseFields(fields: Field[], input: Raw, options: Map<string, Option[]>): Parsed {
  const values: Record<string, unknown> = {};
  const errors: Errors = {};

  for (const f of fields) {
    const raw = input[f.name];
    const fail = (msg: string) => { errors[f.name] = msg; };

    if (f.type === 'checkbox') {
      values[f.name] = raw === '1' || raw === 'on' || raw === 'true' || raw === true;
      continue;
    }

    const text = str(raw);
    const blank = text === '';

    if (blank) {
      if (f.required) { fail('Este campo es obligatorio.'); continue; }
      if (f.type === 'paragraphs') values[f.name] = [];
      else if (f.type === 'number') values[f.name] = f.default ?? null;
      else if (f.type === 'date' || f.type === 'select' || f.nullable) values[f.name] = null;
      else values[f.name] = '';
      continue;
    }

    const limit = f.max ?? MAX_LEN[f.type];
    if (limit && text.length > limit && f.type !== 'number' && f.type !== 'date') {
      fail(`Máximo ${limit} caracteres (tiene ${text.length}).`);
      continue;
    }

    switch (f.type) {
      case 'text':
      case 'textarea':
        values[f.name] = text;
        break;
      case 'paragraphs':
        values[f.name] = splitParagraphs(text);
        break;
      case 'url':
        if (!isSafeUrl(text)) fail('Escribe un enlace que empiece con https://, http://, mailto: o tel:.');
        else values[f.name] = text;
        break;
      case 'number': {
        const n = Number(text);
        if (!Number.isInteger(n)) fail('Escribe un número entero.');
        else if (f.min !== undefined && n < f.min) fail(`Debe ser al menos ${f.min}.`);
        else if (f.max !== undefined && n > f.max) fail(`Debe ser como máximo ${f.max}.`);
        else values[f.name] = n;
        break;
      }
      case 'date':
        if (!isValidDate(text)) fail('Escribe una fecha válida.');
        else values[f.name] = text;
        break;
      case 'select': {
        const allowed = options.get(f.name) ?? [];
        if (!allowed.some((o) => o.value === text)) fail('Elige una opción de la lista.');
        else values[f.name] = f.optionsFrom ? Number(text) : text;
        break;
      }
    }
  }

  const { start_date: start, end_date: end } = values as { start_date?: string; end_date?: string | null };
  if (start && end && end < start && !errors.end_date) errors.end_date = 'El fin no puede ser anterior al inicio.';

  return { values, errors };
}

/** Cómo se muestra un valor de la base de datos dentro de un campo de formulario. */
export function toEcho(field: Field, value: unknown): string | boolean {
  if (field.type === 'checkbox') return Boolean(value);
  if (value === null || value === undefined) return field.default !== undefined ? String(field.default) : '';
  if (field.type === 'paragraphs') return (value as string[]).join('\n\n');
  return String(value);
}

// --- Filas hijas (p. ej. puntos de detalle de un proyecto) -----------------------------------------

/** qs entrega `children[key][0][campo]` como arreglo u objeto indexado; se normaliza y se ordena por índice. */
export function childRowsFromBody(body: Raw, key: string): Raw[] {
  const group = (body.children as Raw | undefined)?.[key];
  if (!group || typeof group !== 'object') return [];
  const entries = Array.isArray(group)
    ? group.map((v, i) => [String(i), v] as const)
    : Object.entries(group as Raw).sort(([a], [b]) => Number(a) - Number(b));
  return entries.map(([, v]) => v).filter((v): v is Raw => !!v && typeof v === 'object');
}

export interface ParsedChildren {
  rows: Map<string, Raw[]>;
  errors: Errors;
}

export function parseChildren(
  res: Resource, body: Raw, options: Map<string, Option[]> = new Map(),
): ParsedChildren & { values: Map<string, Record<string, unknown>[]> } {
  const rows = new Map<string, Raw[]>();
  const values = new Map<string, Record<string, unknown>[]>();
  const errors: Errors = {};

  for (const child of res.children ?? []) {
    const rawRows = childRowsFromBody(body, child.key);
    rows.set(child.key, rawRows);
    const parsedRows: Record<string, unknown>[] = [];
    const seen = new Set<string>();
    rawRows.forEach((raw, i) => {
      // Una fila totalmente vacía (se agregó y no se llenó) se ignora.
      if (child.fields.every((f) => f.type === 'checkbox' || isBlank(raw[f.name]))) return;
      const parsed = parseFields(child.fields, raw, options);
      if (Object.keys(parsed.errors).length) {
        const first = Object.values(parsed.errors)[0];
        errors[`children.${child.key}`] = `${child.label}, fila ${i + 1}: ${first}`;
      } else if (child.unique && seen.has(String(parsed.values[child.unique]))) {
        const key = String(parsed.values[child.unique]);
        const label = [...options.values()].flat().find((o) => o.value === key)?.label ?? key;
        errors[`children.${child.key}`] = `${child.label}: «${label}» está repetida. Quita una de las filas.`;
      } else {
        if (child.unique) seen.add(String(parsed.values[child.unique]));
        parsedRows.push(parsed.values);
      }
    });
    values.set(child.key, parsedRows);
  }
  return { rows, values, errors };
}

// --- Acceso a datos ------------------------------------------------------------------------------

export async function listRows(pool: Pool, res: Resource): Promise<Record<string, unknown>[]> {
  return (await pool.query(res.listSql)).rows;
}

export async function getRecord(pool: Pool, res: Resource, id: number): Promise<Record<string, unknown> | null> {
  const { rows } = await pool.query(`select * from ${res.table} where id = $1`, [id]);
  return rows[0] ?? null;
}

export async function getChildRows(pool: Pool, child: ChildResource, parentId: number): Promise<Record<string, unknown>[]> {
  const cols = child.fields.map((f) => f.name).join(', ');
  const { rows } = await pool.query(
    `select ${cols} from ${child.table} where ${child.fk} = $1 order by position, id`, [parentId],
  );
  return rows;
}

export async function countRows(pool: Pool, res: Resource): Promise<number> {
  const { rows } = await pool.query<{ n: number }>(`select count(*)::int as n from ${res.table}`);
  return rows[0]?.n ?? 0;
}

/** Inserta o actualiza el registro y reemplaza sus filas hijas, todo en una transacción. */
export async function saveRecord(
  pool: Pool,
  res: Resource,
  id: number | null,
  values: Record<string, unknown>,
  children: Map<string, Record<string, unknown>[]>,
): Promise<number> {
  const cols = res.fields.map((f) => f.name);
  const params = cols.map((c) => values[c] ?? null);

  return withTx(pool, async (c) => {
    let recordId: number;
    if (res.singleton) {
      const sets = cols.map((col, i) => `${col} = $${i + 1}`).join(', ');
      const placeholders = cols.map((_, i) => `$${i + 1}`).join(', ');
      await c.query(
        `insert into ${res.table} (id, ${cols.join(', ')}) values (1, ${placeholders})
         on conflict (id) do update set ${sets}`,
        params,
      );
      recordId = 1;
    } else if (id === null) {
      const placeholders = cols.map((_, i) => `$${i + 1}`).join(', ');
      const r = await c.query<{ id: number }>(
        `insert into ${res.table} (${cols.join(', ')}) values (${placeholders}) returning id`, params,
      );
      recordId = r.rows[0]!.id;
    } else {
      const sets = cols.map((col, i) => `${col} = $${i + 1}`).join(', ');
      const r = await c.query(`update ${res.table} set ${sets} where id = $${cols.length + 1}`, [...params, id]);
      if (!r.rowCount) throw new NotFoundError();
      recordId = id;
    }

    for (const child of res.children ?? []) {
      const rows = children.get(child.key) ?? [];
      const childCols = child.fields.map((f) => f.name);
      await c.query(`delete from ${child.table} where ${child.fk} = $1`, [recordId]);
      for (const [i, row] of rows.entries()) {
        const ph = childCols.map((_, k) => `$${k + 3}`).join(', ');
        await c.query(
          `insert into ${child.table} (${child.fk}, position, ${childCols.join(', ')}) values ($1, $2, ${ph})`,
          [recordId, i, ...childCols.map((col) => row[col] ?? null)],
        );
      }
    }
    return recordId;
  });
}

export class NotFoundError extends Error {}

/** Borra dentro de una transacción para que un rechazo por llave foránea deje la conexión limpia. */
export async function deleteRecord(pool: Pool, res: Resource, id: number): Promise<boolean> {
  return withTx(pool, async (c) => {
    const r = await c.query(`delete from ${res.table} where id = $1`, [id]);
    return (r.rowCount ?? 0) > 0;
  });
}

/** Códigos de error de PostgreSQL que el panel sabe explicar al usuario. */
// Para `on delete restrict`, PostgreSQL real responde 23503 (foreign_key_violation) pero PGlite responde 23001
// (restrict_violation). Por eso `isInUseError` reconoce ambos: verificado contra PostgreSQL 17.10 y contra PGlite.
export const PG_FOREIGN_KEY_VIOLATION = '23503';
export const PG_RESTRICT_VIOLATION = '23001';
export const PG_CHECK_VIOLATION = '23514';
export const PG_UNIQUE_VIOLATION = '23505';

/** ¿La base rechazó la operación porque otros registros dependen de este? */
export function isInUseError(err: unknown): boolean {
  const code = pgErrorCode(err);
  return code === PG_FOREIGN_KEY_VIOLATION || code === PG_RESTRICT_VIOLATION;
}

export function pgErrorCode(err: unknown): string | undefined {
  return typeof err === 'object' && err !== null && 'code' in err ? String((err as { code: unknown }).code) : undefined;
}
