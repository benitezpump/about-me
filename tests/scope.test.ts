import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { describe, test } from 'node:test';
import { FIELD_TYPES } from '../src/admin/resources.js';
import { ROOT } from '../src/config.js';

/**
 * Alarma de alcance del panel.
 *
 * El panel se construyó a propósito con un alcance pequeño (un administrador; campos de texto, número, fecha,
 * casilla, lista y enlace) porque es código propio de seguridad y CRUD que un framework traería hecho. Ver
 * docs/decisions/0001-arquitectura-y-alcance-del-panel.md.
 *
 * Estas pruebas NO prohíben cambiar el alcance: obligan a hacerlo a propósito. Si una falla, no la "arregles"
 * editándola sin más: lee el documento, comprueba cuántas señales de crecimiento hay, y si sigues adelante actualiza
 * la prueba y el documento en el mismo cambio.
 */
const ADR = 'docs/decisions/0001-arquitectura-y-alcance-del-panel.md';
const because = (what: string): string =>
  `${what}\n→ Esto cambia el alcance del panel. Lee ${ADR}: con dos o más señales de crecimiento (archivos, texto ` +
  'enriquecido, varios usuarios o roles, varios idiomas, borradores, 2FA o correo) la decisión acordada es migrar el ' +
  'panel a un framework, no construirlo a mano. Si decides seguir, actualiza esta prueba y el documento.';

const read = (rel: string): string => fs.readFileSync(path.join(ROOT, rel), 'utf8');

describe('alcance del panel', () => {
  test('el documento de la decisión existe y enumera las señales de crecimiento', () => {
    const adr = read(ADR);
    for (const señal of ['imágenes o archivos', 'Texto enriquecido', 'roles y permisos', 'Varios idiomas', 'Borradores', 'dos pasos']) {
      assert.ok(adr.includes(señal), `falta la señal "${señal}" en ${ADR}`);
    }
  });

  test('los tipos de campo del panel son los conocidos (sin archivos ni texto enriquecido)', () => {
    assert.deepEqual(
      [...FIELD_TYPES],
      ['text', 'textarea', 'paragraphs', 'number', 'date', 'checkbox', 'select', 'url'],
      because(`Cambiaron los tipos de campo: ${FIELD_TYPES.join(', ')}.`),
    );
  });

  test('no hay dependencias de subida de archivos, editores, idiomas, 2FA ni correo', () => {
    const pkg = JSON.parse(read('package.json')) as { dependencies?: object; devDependencies?: object };
    const names = Object.keys({ ...pkg.dependencies, ...pkg.devDependencies });
    const señales = /multipart|multer|formidable|busboy|sharp|jimp|quill|tiptap|prosemirror|tinymce|ckeditor|lexical|i18next|formatjs|intl-messageformat|speakeasy|otplib|otpauth|nodemailer/i;
    const encontradas = names.filter((n) => señales.test(n));
    assert.deepEqual(encontradas, [], because(`Nuevas dependencias que apuntan a una señal de crecimiento: ${encontradas.join(', ')}.`));
  });

  test('el modelo de usuarios sigue siendo de un solo administrador, sin roles', () => {
    const sql = fs.readdirSync(path.join(ROOT, 'db', 'migrations'))
      .filter((f) => f.endsWith('.sql'))
      .map((f) => read(`db/migrations/${f}`))
      .join('\n');

    const tabla = /create table admin_users \(([\s\S]*?)\n\);/.exec(sql)?.[1] ?? '';
    assert.ok(tabla.length > 0, 'no se encontró la tabla admin_users');
    assert.doesNotMatch(tabla, /\brol(es)?\b|\brole(s)?\b|permission/i, because('admin_users ahora tiene roles o permisos.'));
    assert.doesNotMatch(sql, /create table (roles?|permissions?|user_roles|users)\b/i, because('Aparecieron tablas de roles o usuarios.'));
    assert.doesNotMatch(sql, /alter table admin_users add column (role|roles|permission)/i, because('Se añadieron roles a admin_users.'));
  });
});
