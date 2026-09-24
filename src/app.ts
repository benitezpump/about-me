import path from 'node:path';
import Fastify from 'fastify';
import fastifyCookie from '@fastify/cookie';
import fastifyFormbody from '@fastify/formbody';
import fastifyHelmet from '@fastify/helmet';
import fastifyRateLimit from '@fastify/rate-limit';
import fastifyStatic from '@fastify/static';
import qs from 'qs';
import { adminRoutes } from './admin/routes.js';
import { ROOT, toFastifyTrustProxy, type Config } from './config.js';
import type { Pool } from './db.js';
import { publicRoutes } from './routes/public.js';
import { createViews } from './views.js';
import { ViewCounter } from './visits.js';

export interface AppDeps {
  pool: Pool;
  config: Config;
}

declare module 'fastify' {
  interface FastifyInstance {
    viewCounter: ViewCounter;
  }
}

export async function buildApp({ pool, config }: AppDeps) {
  const app = Fastify({
    logger: config.env === 'test' ? false : { level: 'info' },
    trustProxy: toFastifyTrustProxy(config.trustProxy),
    bodyLimit: 512 * 1024,
  });

  // Se calcula una vez por arranque: cambia en cada despliegue y así invalida la caché del navegador.
  const render = createViews({ watch: config.env === 'development', assetVersion: String(Date.now()) });

  await app.register(fastifyHelmet, {
    contentSecurityPolicy: {
      useDefaults: false,
      directives: {
        defaultSrc: ["'self'"],
        scriptSrc: ["'self'"],
        styleSrc: ["'self'", 'https://fonts.googleapis.com'],
        fontSrc: ['https://fonts.gstatic.com'],
        imgSrc: ["'self'", 'data:'],
        connectSrc: ["'self'"],
        objectSrc: ["'none'"],
        baseUri: ["'self'"],
        formAction: ["'self'"],
        frameAncestors: ["'none'"],
      },
    },
    crossOriginEmbedderPolicy: false,
    strictTransportSecurity: config.cookieSecure,
  });
  await app.register(fastifyCookie);
  // `qs` para que los campos `children[highlights][0][body]` lleguen como objetos anidados.
  await app.register(fastifyFormbody, { parser: (str) => qs.parse(str, { arrayLimit: 300, parameterLimit: 2000, depth: 4 }) });
  await app.register(fastifyRateLimit, { global: true, max: 300, timeWindow: '1 minute' });
  await app.register(fastifyStatic, { root: path.join(ROOT, 'public'), prefix: '/static/', maxAge: '1h' });

  const counter = new ViewCounter(pool, config.statsTimezone, (err) => app.log.error({ err }, 'No se pudo registrar la visita'));
  app.decorate('viewCounter', counter);
  app.addHook('onClose', async () => counter.flush());

  await app.register(publicRoutes, { pool, render, counter });
  await app.register(adminRoutes, { pool, config, render, counter, prefix: '/admin' });

  const errorPage = (status: number, title: string, message: string) =>
    render('error.njk', { status, title, message });

  app.setNotFoundHandler((_req, reply) => {
    reply.code(404).type('text/html; charset=utf-8').send(errorPage(404, 'Página no encontrada', 'La dirección no existe o cambió de lugar.'));
  });

  app.setErrorHandler((err: Error & { statusCode?: number }, req, reply) => {
    const status = err.statusCode && err.statusCode >= 400 ? err.statusCode : 500;
    if (status >= 500) req.log.error({ err }, 'Error no controlado');
    const message = status === 429
      ? 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.'
      : status >= 500
        ? 'Algo salió mal de nuestro lado. Inténtalo de nuevo en un momento.'
        : 'La solicitud no es válida.';
    reply.code(status).type('text/html; charset=utf-8').send(errorPage(status, status === 429 ? 'Demasiados intentos' : 'No se pudo completar', message));
  });

  return app;
}
