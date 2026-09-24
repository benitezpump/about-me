import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { CONTENT_VERSION, loadSeedContent, parseContent, parseContentText, serializeContent } from '../src/content-data.js';

/** Contenido mínimo válido, para romperlo de a una cosa. */
const minimal = () => ({
  profile: {
    first_names: 'Ada', last_names: 'Lovelace', display_name: 'Ada', site_title: 'Ada', headline: 'Programadora.',
    intro: ['Hola.'],
  },
});

const issuesOf = (value: unknown): string[] => {
  const r = parseContent(value);
  assert.equal(r.ok, false, 'debía fallar');
  return r.ok ? [] : r.issues;
};

describe('formato de contenido (JSON)', () => {
  test('el contenido inicial del repositorio es válido', () => {
    const data = loadSeedContent();
    assert.ok(data.experiences.length >= 2 && data.certification_groups.length > 0);
  });

  test('lo mínimo es un perfil; todo lo demás es opcional y se rellena con valores por defecto', () => {
    const r = parseContent(minimal());
    assert.ok(r.ok);
    if (!r.ok) return;
    assert.equal(r.data.profile.location, '');
    assert.equal(r.data.profile.show_view_count, true);
    assert.deepEqual([r.data.experiences, r.data.tool_groups, r.data.certification_groups], [[], [], []]);
  });

  test('serializar y volver a leer da lo mismo (sin perder ni inventar nada)', () => {
    const data = loadSeedContent();
    const text = serializeContent(data);
    const again = parseContentText(text);
    assert.ok(again.ok);
    if (again.ok) assert.deepEqual(again.data, data);
    assert.equal(serializeContent(again.ok ? again.data : data), text, 'el texto es estable');
    assert.match(text, new RegExp(`"version": ${CONTENT_VERSION}`));
  });

  test('cada experiencia solo lleva las listas de su tipo', () => {
    const out = JSON.parse(serializeContent(loadSeedContent())) as { experiences: Record<string, unknown>[] };
    for (const e of out.experiences) {
      if (e.kind === 'work') assert.ok('projects' in e && !('courses' in e) && !('workshops' in e));
      else assert.ok(!('projects' in e) && 'courses' in e && 'workshops' in e);
    }
  });

  test('los errores dicen DÓNDE están y se juntan todos, no solo el primero', () => {
    const bad = {
      ...minimal(),
      experiences: [{ kind: 'work', company: 'X', role: 'Y', start_date: '2020-13-01', projects: [{ title: '', start_date: '2020-01-01' }] }],
      education: [{ title: 'T' }],
    };
    const issues = issuesOf(bad);
    assert.ok(issues.some((i) => i.startsWith('experiences[0].start_date:') && /fecha válida/.test(i)), issues.join('\n'));
    assert.ok(issues.some((i) => i.startsWith('experiences[0].projects[0].title:') && /vacío/.test(i)));
    assert.ok(issues.some((i) => i.startsWith('education[0].period_label:') && /obligatorio/.test(i)));
  });

  test('rechaza campos desconocidos (un error de tipeo no se pierde en silencio)', () => {
    const issues = issuesOf({ ...minimal(), education: [{ title: 'T', period_label: 'P', institucion: 'X' }] });
    assert.ok(issues.some((i) => /education\[0\]\.institucion: campo desconocido/.test(i)), issues.join('\n'));
    assert.ok(issuesOf({ ...minimal(), extra: 1 }).some((i) => /extra: campo desconocido/.test(i)));
  });

  test('exige coherencia: fechas en orden, enlaces seguros, año razonable, tipos de experiencia', () => {
    const at = (patch: object) => issuesOf({ ...minimal(), ...patch }).join('\n');
    assert.match(at({ experiences: [{ kind: 'work', company: 'X', role: 'Y', start_date: '2020-05-01', end_date: '2020-01-01' }] }), /anterior al inicio/);
    assert.match(at({ contact_links: [{ label: 'x', url: 'javascript:alert(1)' }] }), /el enlace debe empezar con/);
    assert.match(at({ contact_links: [{ label: 'x', url: 'https://a b.com' }] }), /el enlace debe empezar con/);
    assert.match(at({ certification_groups: [{ title: 'G', items: [{ name: 'n', year: 1800 }] }] }), /entre 1990 y 2100/);
    assert.match(at({ certification_groups: [{ title: 'G', items: [{ name: 'n', year: '2020' }] }] }), /año \(número entero\)/);
    assert.match(at({ experiences: [{ kind: 'freelance', company: 'X', role: 'Y', start_date: '2020-01-01' }] }), /"work" o "teaching"/);
    assert.match(at({ experiences: [{ kind: 'teaching', company: 'X', role: 'Y', start_date: '2020-01-01', projects: [{ title: 'p', start_date: '2020-01-01' }] }] }), /de docencia no lleva "projects"/);
    assert.match(at({ experiences: [{ kind: 'work', company: 'X', role: 'Y', start_date: '2020-01-01', courses: [{ subject: 's', start_date: '2020-01-01' }] }] }), /de trabajo no lleva/);
  });

  test('una tecnología repetida en el mismo proyecto o grupo se rechaza sin distinguir mayúsculas', () => {
    const project = { title: 'p', start_date: '2020-01-01', technologies: [{ name: 'Node.js' }, { name: 'node.js' }] };
    assert.match(issuesOf({ ...minimal(), personal_projects: [project] }).join('\n'), /repetida/);
    assert.match(issuesOf({ ...minimal(), tool_groups: [{ label: 'g', tools: ['Go', 'GO'] }] }).join('\n'), /repetida/);
  });

  test('las mismas longitudes que los formularios', () => {
    const long = 'x'.repeat(1501);
    assert.match(issuesOf({ ...minimal(), personal_projects: [{ title: 'p', start_date: '2020-01-01', description: long }] }).join('\n'), /máximo 1500/);
    assert.match(issuesOf({ ...minimal(), tool_groups: [{ label: 'g', tools: ['y'.repeat(81)] }] }).join('\n'), /máximo 80/);
  });

  test('el texto que no es JSON, un arreglo o una versión futura dan un mensaje claro', () => {
    const text = parseContentText('{ no es json');
    assert.ok(!text.ok && /no es JSON válido/.test(text.issues[0]!));
    assert.match(issuesOf([]).join(), /objeto JSON/);
    assert.match(issuesOf({ ...minimal(), version: 99 }).join(), /versión 1, no 99/);
    assert.match(issuesOf({ ...minimal(), format: 'otra-cosa' }).join(), /about-me-content/);
    assert.match(issuesOf({}).join(), /profile: es obligatorio/);
  });

  test('tolera el BOM de los editores de Windows y recorta espacios', () => {
    const r = parseContentText(`﻿${JSON.stringify({ profile: { ...minimal().profile, headline: '  Hola  ' } })}`);
    assert.ok(r.ok);
    if (r.ok) assert.equal(r.data.profile.headline, 'Hola');
  });

  test('con muchos errores se muestran los primeros y se cuenta el resto', () => {
    const r = parseContentText(JSON.stringify({ ...minimal(), education: Array.from({ length: 40 }, () => ({})) }));
    assert.ok(!r.ok);
    if (!r.ok) {
      assert.equal(r.issues.length, 26);
      assert.match(r.issues.at(-1)!, /y \d+ problemas más/);
    }
  });
});
