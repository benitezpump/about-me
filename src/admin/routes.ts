import type { FastifyInstance, FastifyReply, FastifyRequest } from 'fastify';
import type { Pool } from '../db.js';
import type { Config } from '../config.js';
import {
  MIN_PASSWORD_LENGTH, SESSION_TTL_MS, createSession, destroyOtherSessions, destroySession, getSession,
  hashPassword, randomToken, safeEqual, verifyAgainstDummy, verifyPassword, type AdminSession,
} from '../auth.js';
import { invalidateContent } from '../content.js';
import { parseContentText, serializeContent } from '../content-data.js';
import { NoContentError, exportContent, replaceContent } from '../content-io.js';
import type { ViewCounter } from '../visits.js';
import {
  NotFoundError, PG_CHECK_VIOLATION, PG_UNIQUE_VIOLATION, countRows, deleteRecord, getChildRows, getRecord, isInUseError,
  listRows, optionsForResource, parseChildren, parseFields, pgErrorCode, saveRecord, toEcho,
} from './crud.js';
import { resourceBySlug, resources, type Field, type Resource } from './resources.js';

type Raw = Record<string, unknown>;
type Render = (template: string, data: Record<string, unknown>) => string;

declare module 'fastify' {
  interface FastifyRequest {
    admin: AdminSession | null;
    sid: string | undefined;
  }
}

const FLASH: Record<string, string> = {
  created: 'Se creó correctamente.',
  saved: 'Los cambios se guardaron.',
  deleted: 'Se eliminó correctamente.',
  password: 'La contraseña se cambió. Se cerraron tus otras sesiones.',
  imported: 'Se importó el contenido. El sitio ya lo muestra.',
};

const ERRORS: Record<string, string> = {
  'in-use':
    'No se puede eliminar porque se está usando o tiene elementos asociados (proyectos, materias, talleres, certificaciones o tecnologías). Quítalo de donde aparece o elimina primero lo asociado.',
  'no-content': 'Todavía no hay perfil, así que no hay contenido que exportar. Llena el Perfil o importa un archivo.',
};

const ID = /^\d+$/;

export interface AdminDeps { pool: Pool; config: Config; render: Render; counter: ViewCounter }

export async function adminRoutes(app: FastifyInstance, { pool, config, render, counter }: AdminDeps): Promise<void> {
  app.decorateRequest('admin', null);
  app.decorateRequest('sid', undefined);

  const page = (reply: FastifyReply, template: string, data: Record<string, unknown>, status = 200) =>
    reply
      .code(status)
      .header('Cache-Control', 'no-store')
      .type('text/html; charset=utf-8')
      .send(render(template, data));

  const cookieBase = { httpOnly: true, sameSite: 'lax' as const, secure: config.cookieSecure };

  // ----------------------------------------------------------------------------------- Acceso ----

  const loginPage = (reply: FastifyReply, error: string | null, status = 200, username = '') => {
    const token = randomToken(24);
    reply.setCookie('lcsrf', token, { ...cookieBase, sameSite: 'strict', path: '/admin/login', maxAge: 900 });
    return page(reply, 'admin/login.njk', { csrf: token, error, username }, status);
  };

  app.get('/login', async (req, reply) => {
    if (await getSession(pool, req.cookies.sid)) return reply.redirect('/admin');
    return loginPage(reply, null);
  });

  app.post(
    '/login',
    { config: { rateLimit: { max: config.loginMaxAttempts, timeWindow: '15 minutes' } } },
    async (req, reply) => {
      const body = (req.body ?? {}) as Raw;
      const username = String(body.username ?? '').trim();
      const password = String(body.password ?? '');

      // Doble cookie: el token del formulario debe coincidir con el de la cookie estricta.
      const cookieToken = req.cookies.lcsrf ?? '';
      if (!cookieToken || !safeEqual(String(body._csrf ?? ''), cookieToken)) {
        return loginPage(reply, 'La sesión del formulario expiró. Inténtalo de nuevo.', 400, username);
      }

      const { rows } = await pool.query<{ id: number; password_hash: string }>(
        'select id, password_hash from admin_users where username = $1', [username],
      );
      const user = rows[0];
      const ok = user ? await verifyPassword(password, user.password_hash) : (await verifyAgainstDummy(password), false);
      if (!user || !ok) return loginPage(reply, 'Usuario o contraseña incorrectos.', 401, username);

      const { token } = await createSession(pool, user.id);
      reply.setCookie('sid', token, { ...cookieBase, path: '/admin', maxAge: SESSION_TTL_MS / 1000 });
      reply.clearCookie('lcsrf', { path: '/admin/login' });
      // Marca este navegador para que tus propias visitas al sitio no cuenten. No identifica a nadie.
      reply.setCookie('notrack', '1', { ...cookieBase, path: '/', maxAge: 365 * 24 * 60 * 60 });
      return reply.redirect('/admin');
    },
  );

  // -------------------------------------------------------------------- Rutas protegidas ----

  await app.register(async (secure) => {
    secure.addHook('preHandler', async (req: FastifyRequest, reply: FastifyReply) => {
      const session = await getSession(pool, req.cookies.sid);
      if (!session) return reply.redirect('/admin/login');
      req.admin = session;
      req.sid = req.cookies.sid;
      if (req.method === 'POST') {
        const sent = String(((req.body ?? {}) as Raw)._csrf ?? '');
        if (!safeEqual(sent, session.csrf)) {
          return page(reply, 'admin/message.njk', {
            ...base(req), title: 'Solicitud no válida',
            message: 'El formulario expiró o no es válido. Vuelve atrás, recarga la página e inténtalo de nuevo.',
          }, 403);
        }
      }
    });

    const nav = resources.map((r) => ({ slug: r.slug, label: r.label }));
    const base = (req: FastifyRequest, extra: Record<string, unknown> = {}) => ({
      admin: req.admin, csrf: req.admin?.csrf, nav, ...extra,
    });
    const flashOf = (req: FastifyRequest): string | null => FLASH[String((req.query as Raw).ok ?? '')] ?? null;
    const errorOf = (req: FastifyRequest): string | null => ERRORS[String((req.query as Raw).err ?? '')] ?? null;
    const notFound = (req: FastifyRequest, reply: FastifyReply) =>
      page(reply, 'admin/message.njk', { ...base(req), title: 'No encontrado', message: 'Esa página o ese registro no existe.' }, 404);

    secure.post('/logout', async (req, reply) => {
      await destroySession(pool, req.sid);
      reply.clearCookie('sid', { path: '/admin' });
      return reply.redirect('/admin/login');
    });

    secure.get('/', async (req, reply) => {
      const counts = await Promise.all(
        resources.filter((r) => !r.singleton).map(async (r) => ({ ...r, total: await countRows(pool, r) })),
      );
      return page(reply, 'admin/dashboard.njk', {
        ...base(req), current: 'dashboard', cards: counts, profile: resourceBySlug.get('profile'),
        stats: await counter.stats(),
      });
    });

    // ------------------------------------------------------------------------- Cuenta ----

    const accountPage = (req: FastifyRequest, reply: FastifyReply, errors: Raw = {}, status = 200) =>
      page(reply, 'admin/account.njk', { ...base(req), current: 'account', errors, minLength: MIN_PASSWORD_LENGTH, flash: flashOf(req) }, status);

    secure.get('/account', async (req, reply) => accountPage(req, reply));

    secure.post('/account', async (req, reply) => {
      const body = (req.body ?? {}) as Raw;
      const current = String(body.current ?? '');
      const next = String(body.next ?? '');
      const confirm = String(body.confirm ?? '');
      const errors: Raw = {};

      const { rows } = await pool.query<{ password_hash: string }>(
        'select password_hash from admin_users where id = $1', [req.admin!.userId],
      );
      if (!rows[0] || !(await verifyPassword(current, rows[0].password_hash))) errors.current = 'La contraseña actual no es correcta.';
      if (next.length < MIN_PASSWORD_LENGTH) errors.next = `Usa al menos ${MIN_PASSWORD_LENGTH} caracteres.`;
      else if (next === current) errors.next = 'La nueva contraseña debe ser distinta de la actual.';
      if (next !== confirm) errors.confirm = 'No coincide con la nueva contraseña.';
      if (Object.keys(errors).length) return accountPage(req, reply, errors, 422);

      await pool.query('update admin_users set password_hash = $2 where id = $1', [req.admin!.userId, await hashPassword(next)]);
      await destroyOtherSessions(pool, req.admin!.userId, req.sid);
      return reply.redirect('/admin/account?ok=password');
    });

    // ------------------------------------------------------------------ Importar y exportar ----

    const dataPage = (req: FastifyRequest, reply: FastifyReply, extra: Record<string, unknown> = {}, status = 200) =>
      page(reply, 'admin/data.njk', {
        ...base(req), current: 'data', flash: flashOf(req), flashError: errorOf(req), issues: [], text: '', ...extra,
      }, status);

    secure.get('/data', async (req, reply) => dataPage(req, reply));

    secure.get('/data/export', async (req, reply) => {
      try {
        const text = serializeContent(await exportContent(pool));
        return reply
          .header('Cache-Control', 'no-store')
          .header('Content-Disposition', `attachment; filename="about-me-${new Date().toISOString().slice(0, 10)}.json"`)
          .type('application/json; charset=utf-8')
          .send(text);
      } catch (err) {
        if (err instanceof NoContentError) return reply.redirect('/admin/data?err=no-content');
        throw err;
      }
    });

    // Reemplaza TODO el contenido. Se valida por completo antes de tocar la base y se aplica en una transacción:
    // si algo falla, no cambia nada.
    secure.post('/data/import', async (req, reply) => {
      const body = (req.body ?? {}) as Raw;
      const text = String(body.data ?? '');
      if (!['1', 'on', 'true'].includes(String(body.confirm ?? ''))) {
        return dataPage(req, reply, { text, issues: ['Marca la casilla para confirmar que quieres reemplazar el contenido actual.'] }, 422);
      }
      if (text.trim() === '') {
        return dataPage(req, reply, { text, issues: ['Pega el contenido o elige un archivo .json.'] }, 422);
      }

      const parsed = parseContentText(text);
      if (!parsed.ok) return dataPage(req, reply, { text, issues: parsed.issues }, 422);

      try {
        await replaceContent(pool, parsed.data);
      } catch (err) {
        const constraint = (err as { constraint?: string }).constraint;
        req.log.error({ err }, 'No se pudo importar el contenido');
        return dataPage(req, reply, {
          text,
          issues: [`La base de datos rechazó el contenido${constraint ? ` (regla ${constraint})` : ''}. No se cambió nada.`],
        }, 422);
      }
      invalidateContent();
      return reply.redirect('/admin/data?ok=imported');
    });

    // -------------------------------------------------------------- Formularios genéricos ----

    const echoRaw = (fields: Field[], raw: Raw): Record<string, string | boolean> =>
      Object.fromEntries(fields.map((f) => [
        f.name, f.type === 'checkbox' ? ['1', 'on', 'true'].includes(String(raw[f.name])) : String(raw[f.name] ?? ''),
      ]));

    interface FormState {
      id: number | null;
      values: Record<string, string | boolean>;
      childRows: Map<string, Record<string, string | boolean>[]>;
      errors: Record<string, string>;
    }

    async function renderForm(req: FastifyRequest, reply: FastifyReply, res: Resource, state: FormState, status = 200) {
      const options = await optionsForResource(pool, res.fields);
      const fields = res.fields.map((f) => ({
        ...f, id: `f-${f.name}`, value: state.values[f.name], error: state.errors[f.name], options: options.get(f.name),
      }));
      const children = await Promise.all((res.children ?? []).map(async (c) => ({
        ...c, rows: state.childRows.get(c.key) ?? [], error: state.errors[`children.${c.key}`],
        options: Object.fromEntries(await optionsForResource(pool, c.fields)),
      })));
      return page(reply, 'admin/form.njk', {
        ...base(req), current: res.slug, res, fields, children, id: state.id, isNew: state.id === null && !res.singleton,
        formError: state.errors._form ?? null, flash: flashOf(req), flashError: errorOf(req),
      }, status);
    }

    async function newState(res: Resource): Promise<FormState> {
      return {
        id: null,
        values: Object.fromEntries(res.fields.map((f) => [f.name, toEcho(f, undefined)])),
        childRows: new Map(),
        errors: {},
      };
    }

    async function recordState(res: Resource, id: number): Promise<FormState | null> {
      const record = await getRecord(pool, res, id);
      if (!record) return null;
      const childRows = new Map<string, Record<string, string | boolean>[]>();
      for (const child of res.children ?? []) {
        const rows = await getChildRows(pool, child, id);
        childRows.set(child.key, rows.map((row) =>
          Object.fromEntries(child.fields.map((f) => [f.name, toEcho(f, row[f.name])]))));
      }
      return {
        id,
        values: Object.fromEntries(res.fields.map((f) => [f.name, toEcho(f, record[f.name])])),
        childRows,
        errors: {},
      };
    }

    async function save(req: FastifyRequest, reply: FastifyReply, res: Resource, id: number | null) {
      const body = (req.body ?? {}) as Raw;
      const options = await optionsForResource(pool, res.fields);
      const parsed = parseFields(res.fields, body, options);
      const kids = parseChildren(res, body, await optionsForResource(pool, (res.children ?? []).flatMap((c) => c.fields)));
      const errors: Record<string, string> = { ...parsed.errors, ...kids.errors };
      if (!Object.keys(parsed.errors).length) Object.assign(errors, res.validate?.(parsed.values) ?? {});

      const fail = (status: number) => renderForm(req, reply, res, {
        id,
        values: echoRaw(res.fields, body),
        childRows: new Map((res.children ?? []).map((c) => [
          c.key, (kids.rows.get(c.key) ?? []).map((r) => echoRaw(c.fields, r)),
        ])),
        errors,
      }, status);

      if (Object.keys(errors).length) return fail(422);

      try {
        const savedId = await saveRecord(pool, res, id, parsed.values, kids.values);
        invalidateContent();
        const ok = id === null && !res.singleton ? 'created' : 'saved';
        return reply.redirect(res.singleton ? `/admin/${res.slug}?ok=${ok}` : `/admin/${res.slug}/${savedId}?ok=${ok}`);
      } catch (err) {
        if (err instanceof NotFoundError) return notFound(req, reply);
        const code = pgErrorCode(err);
        if (isInUseError(err)) {
          errors._form = 'Ese cambio dejaría elementos asociados sin una experiencia válida. Revisa el tipo o la experiencia elegida.';
          return fail(422);
        }
        if (code === PG_UNIQUE_VIOLATION) {
          const constraint = (err as { constraint?: string }).constraint ?? '';
          if (constraint === 'technologies_name_key') errors.name = 'Ya existe una tecnología con ese nombre (sin distinguir mayúsculas).';
          else errors._form = 'Ya existe un registro con esos datos.';
          return fail(422);
        }
        if (code === PG_CHECK_VIOLATION) {
          errors._form = 'Algún dato no cumple las reglas de la base de datos (fechas, textos o enlaces). Revísalos.';
          return fail(422);
        }
        req.log.error({ err }, 'No se pudo guardar el registro');
        errors._form = 'No se pudo guardar. Revisa los datos e inténtalo de nuevo.';
        return fail(500);
      }
    }

    // El perfil es una sola fila: rutas propias, sin lista.
    const profile = resourceBySlug.get('profile')!;
    secure.get('/profile', async (req, reply) =>
      renderForm(req, reply, profile, (await recordState(profile, 1)) ?? (await newState(profile))));
    secure.post('/profile', async (req, reply) => save(req, reply, profile, 1));

    const lookup = (req: FastifyRequest): Resource | null => {
      const res = resourceBySlug.get((req.params as Raw).slug as string);
      return res && !res.singleton ? res : null;
    };

    secure.get('/:slug', async (req, reply) => {
      const res = lookup(req);
      if (!res) return notFound(req, reply);
      const rows = await listRows(pool, res);
      return page(reply, 'admin/list.njk', { ...base(req), current: res.slug, res, rows, flash: flashOf(req) });
    });

    secure.get('/:slug/new', async (req, reply) => {
      const res = lookup(req);
      if (!res) return notFound(req, reply);
      return renderForm(req, reply, res, await newState(res));
    });

    secure.post('/:slug', async (req, reply) => {
      const res = lookup(req);
      return res ? save(req, reply, res, null) : notFound(req, reply);
    });

    secure.get('/:slug/:id', async (req, reply) => {
      const res = lookup(req);
      const id = (req.params as Raw).id as string;
      if (!res || !ID.test(id)) return notFound(req, reply);
      const state = await recordState(res, Number(id));
      return state ? renderForm(req, reply, res, state) : notFound(req, reply);
    });

    secure.post('/:slug/:id', async (req, reply) => {
      const res = lookup(req);
      const id = (req.params as Raw).id as string;
      return res && ID.test(id) ? save(req, reply, res, Number(id)) : notFound(req, reply);
    });

    secure.post('/:slug/:id/delete', async (req, reply) => {
      const res = lookup(req);
      const id = (req.params as Raw).id as string;
      if (!res || !ID.test(id)) return notFound(req, reply);
      let removed: boolean;
      try {
        removed = await deleteRecord(pool, res, Number(id));
      } catch (err) {
        // La base impide borrar algo que aún tiene proyectos, materias, talleres o certificaciones asociados.
        if (isInUseError(err)) return reply.redirect(`/admin/${res.slug}/${id}?err=in-use`);
        throw err;
      }
      if (!removed) return notFound(req, reply);
      invalidateContent();
      return reply.redirect(`/admin/${res.slug}?ok=deleted`);
    });
  });
}
