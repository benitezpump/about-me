import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import Fastify from 'fastify';
import { hashPassword, verifyPassword } from '../src/auth.js';
import { parseSslCa, parseTrustProxy, toFastifyTrustProxy } from '../src/config.js';
import { createPool } from '../src/db.js';
import { isSafeUrl, parseChildren, parseFields } from '../src/admin/crud.js';
import { resourceBySlug } from '../src/admin/resources.js';
import { buildPeriod, initials, longMonthYear, monthYear, splitParagraphs } from '../src/format.js';
import { dayKey, formatViews, isBot, shiftDay } from '../src/visits.js';
import { createContentCache, stackLine } from '../src/content.js';

describe('formato', () => {
  test('fechas de mes', () => {
    assert.equal(monthYear('2023-02-01'), '02/2023');
    assert.equal(longMonthYear('2015-03-01'), 'marzo de 2015');
  });

  test('periodos: en curso, cerrado y con texto libre', () => {
    assert.deepEqual(buildPeriod('2023-02-01', null), { label: null, from: '02/2023', to: null, current: true });
    assert.deepEqual(buildPeriod('2020-12-01', '2022-04-01'), { label: null, from: '12/2020', to: '04/2022', current: false });
    // Con texto libre nunca se muestra "actualidad", aunque no tenga fecha de fin.
    assert.equal(buildPeriod('2022-07-21', null, '21 jul – 19 oct 2022').current, false);
  });

  test('iniciales y párrafos', () => {
    assert.equal(initials('Ana Prueba'), 'AP');
    assert.equal(initials('ana'), 'A');
    assert.deepEqual(splitParagraphs('a\n\nb\r\n\r\n\r\nc\n'), ['a', 'b', 'c']);
  });
});

describe('contraseñas', () => {
  test('hash y verificación', async () => {
    const hash = await hashPassword('una contraseña larga');
    assert.match(hash, /^scrypt\$32768\$8\$3\$/);
    assert.equal(await verifyPassword('una contraseña larga', hash), true);
    assert.equal(await verifyPassword('otra', hash), false);
    assert.notEqual(hash, await hashPassword('una contraseña larga'), 'la sal hace único cada hash');
  });

  test('un hash con formato inválido nunca coincide', async () => {
    assert.equal(await verifyPassword('x', ''), false);
    assert.equal(await verifyPassword('x', 'plain$text'), false);
    assert.equal(await verifyPassword('x', 'bcrypt$1$2$3$4$5'), false);
  });
});

describe('validación de formularios', () => {
  test('urls seguras', () => {
    for (const ok of ['https://a.com', 'http://a.com/x?y=1', 'mailto:a@b.co', 'tel:+526421234567']) assert.equal(isSafeUrl(ok), true, ok);
    for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'ftp://a.com', '//a.com', 'a.com', 'mailto:', '']) {
      assert.equal(isSafeUrl(bad), false, bad);
    }
  });

  test('fechas inexistentes se rechazan', () => {
    const fields = [{ name: 'd', label: 'D', type: 'date' as const, required: true }];
    assert.deepEqual(parseFields(fields, { d: '2024-02-30' }, new Map()).errors, { d: 'Escribe una fecha válida.' });
    assert.deepEqual(parseFields(fields, { d: '2024-02-29' }, new Map()).errors, {});
  });

  test('vacío: obligatorio falla, opcional nulo o cadena vacía según la columna', () => {
    const fields = [
      { name: 'a', label: 'A', type: 'text' as const, required: true },
      { name: 'b', label: 'B', type: 'text' as const },
      { name: 'c', label: 'C', type: 'text' as const, nullable: true },
      { name: 'd', label: 'D', type: 'number' as const, default: 0 },
    ];
    const { values, errors } = parseFields(fields, { a: '  ', b: '', c: '', d: '' }, new Map());
    assert.deepEqual(errors, { a: 'Este campo es obligatorio.' });
    assert.equal(values.b, '');
    assert.equal(values.c, null);
    assert.equal(values.d, 0);
  });

  test('longitud máxima y enteros', () => {
    const fields = [
      { name: 't', label: 'T', type: 'text' as const, max: 5 },
      { name: 'n', label: 'N', type: 'number' as const, min: 1, max: 10 },
    ];
    assert.match(parseFields(fields, { t: 'seis!!', n: '5' }, new Map()).errors.t ?? '', /Máximo 5/);
    assert.match(parseFields(fields, { t: 'ok', n: '1.5' }, new Map()).errors.n ?? '', /entero/);
    assert.match(parseFields(fields, { t: 'ok', n: '11' }, new Map()).errors.n ?? '', /como máximo 10/);
  });
});

describe('visitas', () => {
  test('formato singular y plural', () => {
    assert.equal(formatViews(1), '1 visualización');
    assert.equal(formatViews(0), '0 visualizaciones');
    assert.equal(formatViews(1234), '1,234 visualizaciones');
  });

  test('detección de robots', () => {
    for (const bot of ['Googlebot/2.1', 'LinkedInBot/1.0', 'curl/8.4.0', 'WhatsApp/2.23', 'Lighthouse', '', undefined]) {
      assert.equal(isBot(bot), true, String(bot));
    }
    assert.equal(isBot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36'), false);
  });

  test('el día depende de la zona horaria, no del servidor', () => {
    const at = new Date('2026-09-23T03:30:00Z'); // 20:30 del 22 en Hermosillo (UTC-7); ya es 23 en UTC
    assert.equal(dayKey('America/Hermosillo', at), '2026-09-22');
    assert.equal(dayKey('UTC', at), '2026-09-23');
  });

  test('sumar días cruza meses y años', () => {
    assert.equal(shiftDay('2026-03-01', -1), '2026-02-28');
    assert.equal(shiftDay('2026-01-01', -1), '2025-12-31');
    assert.equal(shiftDay('2024-03-01', -1), '2024-02-29');
  });
});

describe('caché de contenido', () => {
  const deferred = <T>() => {
    let resolve!: (v: T) => void;
    let reject!: (e: unknown) => void;
    const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
  };

  test('una sola carga aunque lleguen muchas peticiones a la vez', async () => {
    let calls = 0;
    const gate = deferred<string>();
    const cache = createContentCache(() => { calls += 1; return gate.promise; });
    const all = Promise.all(Array.from({ length: 50 }, () => cache.get()));
    gate.resolve('contenido');
    assert.deepEqual(new Set(await all), new Set(['contenido']));
    assert.equal(calls, 1, 'la base se consulta una vez, no 50');
  });

  test('respeta el tiempo de vida y vuelve a cargar al expirar', async () => {
    let calls = 0;
    let clock = 1_000;
    const cache = createContentCache(async () => ++calls, 60_000, () => clock);
    assert.equal(await cache.get(), 1);
    clock += 59_999;
    assert.equal(await cache.get(), 1, 'sigue vigente');
    clock += 2;
    assert.equal(await cache.get(), 2, 'expiró');
  });

  test('una invalidación durante una carga impide guardar el resultado viejo', async () => {
    const first = deferred<string>();
    const values = [first.promise, Promise.resolve('nuevo')];
    let calls = 0;
    const cache = createContentCache(() => values[calls++]!);

    const slow = cache.get();       // empieza a leer (aún sin edición)
    cache.invalidate();             // el administrador guarda un cambio mientras tanto
    first.resolve('viejo');         // la lectura vieja termina después
    assert.equal(await slow, 'viejo', 'quien ya la esperaba recibe lo que leyó');

    assert.equal(await cache.get(), 'nuevo', 'lo viejo NO quedó en la caché');
    assert.equal(calls, 2);
  });

  test('un error de carga no se cachea: la siguiente petición reintenta', async () => {
    let calls = 0;
    const cache = createContentCache(async () => {
      calls += 1;
      if (calls === 1) throw new Error('base caída');
      return 'ok';
    });
    await assert.rejects(cache.get(), /base caída/);
    assert.equal(await cache.get(), 'ok');
    assert.equal(calls, 2);
  });
});

describe('enlaces', () => {
  test('un enlace con espacios se rechaza (coincide con la restricción de la base)', () => {
    assert.equal(isSafeUrl('https://a.com/x y'), false);
    assert.equal(isSafeUrl('https://a.com/x%20y'), true);
    assert.equal(isSafeUrl('mailto:a@b.co'), true);
  });
});

describe('tecnologías del catálogo', () => {
  test('la línea de un proyecto sale del catálogo, con la nota entre paréntesis', () => {
    assert.equal(stackLine([{ name: 'PHP' }, { name: 'Browsershot', note: 'generación de PDF' }], 'texto viejo'),
      'PHP, Browsershot (generación de PDF).');
    assert.equal(stackLine([{ name: 'Go', note: null }], 'texto viejo'), 'Go.');
  });

  test('sin tecnologías en el catálogo se conserva el texto libre heredado', () => {
    assert.equal(stackLine([], 'Node.js con Strapi.'), 'Node.js con Strapi.');
    assert.equal(stackLine([], ''), '');
  });

  test('una fila repetida se rechaza, nombrando la tecnología', () => {
    const tools = resourceBySlug.get('tools')!;
    const options = new Map([['technology_id', [{ value: '1', label: 'Node.js' }, { value: '2', label: 'Vue.js' }]]]);
    const ok = parseChildren(tools, { children: { tools: { 0: { technology_id: '1' }, 1: { technology_id: '2' } } } }, options);
    assert.deepEqual(ok.errors, {});
    assert.deepEqual(ok.values.get('tools'), [{ technology_id: 1 }, { technology_id: 2 }]);

    const dup = parseChildren(tools, { children: { tools: { 0: { technology_id: '1' }, 1: { technology_id: '2' }, 2: { technology_id: '1' } } } }, options);
    assert.match(dup.errors['children.tools'] ?? '', /«Node\.js» está repetida/);
  });

  test('una opción que no está en el catálogo se rechaza', () => {
    const tools = resourceBySlug.get('tools')!;
    const options = new Map([['technology_id', [{ value: '1', label: 'Node.js' }]]]);
    const res = parseChildren(tools, { children: { tools: { 0: { technology_id: '7' } } } }, options);
    assert.match(res.errors['children.tools'] ?? '', /Elige una opción de la lista/);
  });
});

describe('confianza en proxies (TRUST_PROXY)', () => {
  test('se interpreta como booleano, número de saltos o lista de IP/CIDR', () => {
    for (const v of [undefined, '', '  ', 'false', 'FALSE', 'no', 'off', '0']) assert.equal(parseTrustProxy(v), false, String(v));
    for (const v of ['true', 'TRUE', 'yes', 'on']) assert.equal(parseTrustProxy(v), true, v);
    assert.equal(parseTrustProxy('1'), 1);
    assert.equal(parseTrustProxy(' 2 '), 2);
    assert.deepEqual(parseTrustProxy('10.0.0.0/8, 172.16.0.1'), ['10.0.0.0/8', '172.16.0.1']);
    assert.deepEqual(parseTrustProxy('fd00::/8'), ['fd00::/8']);
  });

  test('un valor inválido falla al arrancar en lugar de confiar a ciegas', () => {
    for (const v of ['maybe', '1.5', '999.1.1.1', '10.0.0.0/33', '10.0.0.0/8/8', 'localhost', '10.0.0.0/8; drop', '-1']) {
      assert.throws(() => parseTrustProxy(v), /TRUST_PROXY no es válido/, v);
    }
  });

  /**
   * Escenario típico de Render: el visitante envía una cabecera falsa; Cloudflare añade la IP real que vio; el
   * balanceador de Render añade la IP de Cloudflare; la app recibe la conexión del balanceador.
   *   X-Forwarded-For: 6.6.6.6 (falsa), 1.1.1.1 (visitante real), 172.70.0.1 (Cloudflare)   ·  conexión: 10.0.0.1 (balanceador)
   */
  const ipSeen = async (trustProxy: boolean | number | string[]): Promise<string> => {
    const app = Fastify({ trustProxy: toFastifyTrustProxy(trustProxy) }); // la misma conversión que usa la app
    app.get('/ip', async (req) => ({ ip: req.ip }));
    const res = await app.inject({
      url: '/ip', remoteAddress: '10.0.0.1', headers: { 'x-forwarded-for': '6.6.6.6, 1.1.1.1, 172.70.0.1' },
    });
    await app.close();
    return (res.json() as { ip: string }).ip;
  };

  test('con un número de saltos se ignora la IP falsificada; con `true` NO', async () => {
    assert.equal(await ipSeen(false), '10.0.0.1', 'sin proxy de confianza: la IP de la conexión');
    assert.equal(await ipSeen(1), '172.70.0.1', '1 salto: se ve el proxy anterior (prudente, pero no es el visitante)');
    assert.equal(await ipSeen(2), '1.1.1.1', '2 saltos: el visitante real, y lo falsificado queda ignorado');
    assert.equal(await ipSeen(3), '6.6.6.6', 'confiar de más deja pasar la IP falsificada');
    assert.equal(await ipSeen(true), '6.6.6.6', '`true` toma la IP más a la izquierda: un visitante puede elegirla');
  });

  test('una lista de proxies de confianza se comporta como los saltos que cubre', async () => {
    assert.equal(await ipSeen(['10.0.0.0/8', '172.70.0.0/16']), '1.1.1.1');
    assert.equal(await ipSeen(['10.0.0.0/8']), '172.70.0.1');
  });
});

describe('conexión cifrada a la base de datos (DATABASE_SSL_CA)', () => {
  const PEM = '-----BEGIN CERTIFICATE-----\nMIIBfake\nline2\n-----END CERTIFICATE-----';

  test('acepta el PEM en varias líneas o en una sola con \\n literales, y sin valor es opcional', () => {
    assert.equal(parseSslCa(undefined), undefined);
    assert.equal(parseSslCa('   '), undefined);
    assert.equal(parseSslCa(PEM), PEM);
    assert.equal(parseSslCa(PEM.replace(/\n/g, '\\n')), PEM, 'una línea con \\n literales se normaliza');
  });

  test('un valor que no es un certificado falla al arrancar con un mensaje claro', () => {
    for (const v of ['no soy un certificado', '-----BEGIN CERTIFICATE-----', 'https://supabase.com/ca.crt']) {
      assert.throws(() => parseSslCa(v), /DATABASE_SSL_CA debe contener el certificado completo/, v);
    }
  });

  test('con CA el pool verifica al servidor y quita sslmode de la URL, que de otro modo pisaría la CA', async () => {
    const url = 'postgres://postgres.abc:p%40ss@aws-0-us-west-1.pooler.supabase.com:5432/postgres?sslmode=require&application_name=x';
    const pool = createPool(url, 3, { sslCa: PEM });
    const opts = (pool as unknown as { options: { connectionString: string; ssl: { ca: string; rejectUnauthorized: boolean }; max: number } }).options;
    await pool.end();

    assert.deepEqual(opts.ssl, { ca: PEM, rejectUnauthorized: true });
    assert.ok(!opts.connectionString.includes('sslmode'), opts.connectionString);
    assert.ok(opts.connectionString.includes('application_name=x'), 'el resto de parámetros se conserva');
    assert.ok(opts.connectionString.includes('postgres.abc:p%40ss@'), 'usuario y contraseña codificada intactos');
    assert.equal(opts.max, 3);
  });

  test('sin CA la URL no se toca (para bases locales, PGlite y para no-verify)', async () => {
    const url = 'postgres://u:p@localhost:5432/db?sslmode=no-verify';
    const pool = createPool(url, 2);
    const opts = (pool as unknown as { options: { connectionString: string; ssl?: unknown } }).options;
    await pool.end();
    assert.equal(opts.connectionString, url);
    assert.equal(opts.ssl, undefined);
  });
});
