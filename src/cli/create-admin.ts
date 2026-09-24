import { loadConfig } from '../config.js';
import { createPoolFromConfig } from '../db.js';
import { migrate } from '../migrate.js';
import { MIN_PASSWORD_LENGTH, ensureAdmin, hashPassword } from '../auth.js';

/**
 * Crea el administrador (o, con --reset, cambia su contraseña) leyendo ADMIN_USERNAME y ADMIN_PASSWORD
 * del entorno, para que la contraseña no quede en el historial de la terminal.
 */
const config = loadConfig();
const { adminUsername, adminPassword } = config;
if (!adminUsername || !adminPassword) {
  console.error('Define ADMIN_USERNAME y ADMIN_PASSWORD en el entorno o en .env.');
  process.exit(1);
}

const pool = createPoolFromConfig(config);
try {
  await migrate(pool);
  if (process.argv.includes('--reset')) {
    if (adminPassword.length < MIN_PASSWORD_LENGTH) {
      throw new Error(`ADMIN_PASSWORD debe tener al menos ${MIN_PASSWORD_LENGTH} caracteres.`);
    }
    const { rowCount } = await pool.query('update admin_users set password_hash = $2 where username = $1', [
      adminUsername, await hashPassword(adminPassword),
    ]);
    if (!rowCount) throw new Error(`No existe el usuario "${adminUsername}".`);
    await pool.query('delete from sessions');
    console.log(`Contraseña de "${adminUsername}" actualizada; se cerraron todas las sesiones.`);
  } else {
    const created = await ensureAdmin(pool, adminUsername, adminPassword);
    console.log(created ? `Administrador "${adminUsername}" creado.` : 'Ya existe un administrador; no se cambió nada (usa --reset para cambiar la contraseña).');
  }
} finally {
  await pool.end();
}
