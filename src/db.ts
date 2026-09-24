import pg from 'pg';

// `date` llega como 'YYYY-MM-DD' (texto) en lugar de un Date: evita corrimientos por zona horaria.
pg.types.setTypeParser(1082, (value: string) => value);

export type Pool = pg.Pool;
export type Client = pg.PoolClient;

export interface PoolOptions {
  /** Certificado PEM de la CA de la base: cifra y VERIFICA al servidor (ver DATABASE_SSL_CA en config.ts). */
  sslCa?: string | undefined;
}

export function createPool(connectionString: string, max = 10, options: PoolOptions = {}): Pool {
  const config: pg.PoolConfig = { connectionString, max };
  if (options.sslCa) {
    // `pg` da prioridad a lo que diga la URL sobre la opción `ssl` (comprobado contra un servidor con TLS): un
    // `sslmode=require` en la URL pisaría la CA y la conexión fallaría. Con la CA explícita se quita de la URL.
    const url = new URL(connectionString);
    url.searchParams.delete('sslmode');
    config.connectionString = url.toString();
    config.ssl = { ca: options.sslCa, rejectUnauthorized: true };
  }
  const pool = new pg.Pool(config);
  // Sin este manejador, un error en una conexión inactiva (reinicio de la base, corte de red) tumbaría el proceso.
  pool.on('error', (err) => console.error('Error en una conexión inactiva del pool:', err.message));
  return pool;
}

/** El pool de la aplicación, según la configuración (URL, tamaño y CA opcional). */
export function createPoolFromConfig(config: { databaseUrl: string; poolMax: number; databaseSslCa?: string | undefined }): Pool {
  return createPool(config.databaseUrl, config.poolMax, { sslCa: config.databaseSslCa });
}

/** Ejecuta `fn` dentro de una transacción; confirma si termina bien y revierte si lanza. */
export async function withTx<T>(pool: Pool, fn: (client: Client) => Promise<T>): Promise<T> {
  const client = await pool.connect();
  try {
    await client.query('begin');
    const result = await fn(client);
    await client.query('commit');
    return result;
  } catch (err) {
    await client.query('rollback').catch(() => undefined);
    throw err;
  } finally {
    client.release();
  }
}
