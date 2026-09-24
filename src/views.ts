import path from 'node:path';
import nunjucks from 'nunjucks';
import { ROOT } from './config.js';

const CELL_LABELS: Record<string, string> = {
  work: 'Trabajo',
  teaching: 'Docencia',
  personal: 'Propio',
};

/** Entorno de Nunjucks con autoescape activo: nada del contenido se inserta como HTML crudo. */
export function createViews(opts: { watch: boolean; assetVersion: string }) {
  const env = new nunjucks.Environment(
    new nunjucks.FileSystemLoader(path.join(ROOT, 'views'), { noCache: opts.watch }),
    { autoescape: true, throwOnUndefined: false },
  );

  env.addFilter('isHttp', (url: unknown) => typeof url === 'string' && /^https?:\/\//i.test(url));
  env.addFilter('displayUrl', (url: unknown) =>
    String(url ?? '').replace(/^(https?:\/\/(www\.)?|mailto:|tel:)/i, '').replace(/\/$/, ''));
  env.addFilter('numberFormat', (n: unknown) => Number(n ?? 0).toLocaleString('es-MX'));
  env.addFilter('visitors', (n: unknown) => `${Number(n ?? 0).toLocaleString('es-MX')} ${Number(n) === 1 ? 'visitante' : 'visitantes'}`);
  env.addFilter('cell', (v: unknown) => {
    if (v === true) return 'Sí';
    if (v === false) return 'No';
    if (v === null || v === undefined || v === '') return '—';
    return typeof v === 'string' && v in CELL_LABELS ? CELL_LABELS[v] : String(v);
  });

  return (template: string, data: Record<string, unknown> = {}): string =>
    env.render(template, { assetVersion: opts.assetVersion, ...data });
}
