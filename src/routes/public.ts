import type { FastifyInstance } from 'fastify';
import type { Pool } from '../db.js';
import { getContent } from '../content.js';
import { initials } from '../format.js';
import { formatViews, isBot, type ViewCounter } from '../visits.js';

type Render = (template: string, data: Record<string, unknown>) => string;

const escapeXml = (s: string): string =>
  s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]!);

export async function publicRoutes(
  app: FastifyInstance,
  { pool, render, counter }: { pool: Pool; render: Render; counter: ViewCounter },
): Promise<void> {
  app.get('/', async (req, reply) => {
    const content = await getContent(pool);

    // Cuenta personas, no máquinas: sin robots, sin HEAD, sin el propio administrador (cookie que deja el login)
    // y respetando "No rastrear" (DNT) y Global Privacy Control.
    const skip =
      req.method === 'HEAD' ||
      Boolean(req.cookies.notrack) ||
      req.headers.dnt === '1' ||
      req.headers['sec-gpc'] === '1' ||
      isBot(req.headers['user-agent']);

    // Se lee el total antes de registrar la visita y se le suma esta visita, para que la persona que
    // llega se vea contada y el resultado no dependa de qué consulta termine primero.
    const base = content.profile.showViewCount ? await counter.total() : 0;
    if (!skip) counter.record({ ip: req.ip, userAgent: String(req.headers['user-agent']) });
    const viewsLabel = content.profile.showViewCount ? formatViews(base + (skip ? 0 : 1)) : null;

    return reply
      .header('Cache-Control', 'public, max-age=0, must-revalidate')
      .type('text/html; charset=utf-8')
      .send(render('home.njk', { ...content, year: new Date().getFullYear(), viewsLabel }));
  });

  // Favicon generado con las iniciales del nombre corto, para que cambie con el perfil.
  app.get('/favicon.svg', async (_req, reply) => {
    const { profile } = await getContent(pool);
    const svg =
      `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="12" fill="#0e1320"/>` +
      `<text x="32" y="42" font-family="sans-serif" font-size="30" font-weight="700" text-anchor="middle" fill="#e2c48a">` +
      `${escapeXml(initials(profile.displayName))}</text></svg>`;
    return reply.header('Cache-Control', 'public, max-age=3600').type('image/svg+xml').send(svg);
  });

  // Para el balanceador o el healthcheck de Docker: comprueba que la base responde.
  app.get('/healthz', async (_req, reply) => {
    try {
      await pool.query('select 1');
      return reply.header('Cache-Control', 'no-store').send({ status: 'ok' });
    } catch {
      return reply.code(503).header('Cache-Control', 'no-store').send({ status: 'error' });
    }
  });
}
