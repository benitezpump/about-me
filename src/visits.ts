import crypto from 'node:crypto';
import type { Pool } from './db.js';

// Robots, vistas previas de enlaces y clientes de scripts: no son personas leyendo la página.
const BOT =
  /bot|crawl|spider|slurp|preview|scanner|monitor|uptime|headless|lighthouse|pagespeed|curl|wget|python|httpclient|java\/|go-http|axios|node-fetch|okhttp|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|pinterest|reddit/i;

export function isBot(userAgent: string | undefined): boolean {
  return !userAgent || userAgent.length < 10 || BOT.test(userAgent);
}

/** Día calendario ('YYYY-MM-DD') en la zona horaria indicada. */
export function dayKey(timeZone: string, at: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(at);
}

/** 1 → '1 visualización'; 1234 → '1,234 visualizaciones'. */
export function formatViews(n: number): string {
  return `${n.toLocaleString('es-MX')} ${n === 1 ? 'visualización' : 'visualizaciones'}`;
}

export interface DayStats { day: string; views: number; visitors: number }

export interface VisitStats {
  totalViews: number;
  /** Suma de los visitantes únicos de cada día: una persona que vuelve otro día cuenta otra vez. */
  totalVisitorDays: number;
  today: DayStats;
  last7: { views: number; visitors: number };
  last30: { views: number; visitors: number };
  /** Los últimos 14 días, del más reciente al más antiguo, con ceros donde no hubo visitas. */
  series: DayStats[];
  /** Mayor número de visualizaciones de un día de la serie (mínimo 1), para escalar las barras. */
  maxDay: number;
}

const TOTAL_TTL_MS = 30_000;

export class ViewCounter {
  private saltCache: { day: string; salt: string } | null = null;
  private totalCache: { value: number; at: number } | null = null;
  private inflight = new Set<Promise<void>>();

  constructor(
    private pool: Pool,
    private timeZone: string,
    private onError: (err: unknown) => void = () => undefined,
  ) {}

  /** Registra una visita en segundo plano: un fallo de la base nunca debe afectar a la página. */
  record(visitor: { ip: string; userAgent: string }): void {
    const task: Promise<void> = this.write(visitor)
      .catch((err) => this.onError(err))
      .finally(() => this.inflight.delete(task));
    this.inflight.add(task);
  }

  /** Espera a que terminen los registros pendientes (cierre ordenado y pruebas). */
  async flush(): Promise<void> {
    while (this.inflight.size) await Promise.allSettled([...this.inflight]);
  }

  private async write({ ip, userAgent }: { ip: string; userAgent: string }): Promise<void> {
    const day = dayKey(this.timeZone);
    const salt = await this.saltFor(day);
    const hash = crypto.createHash('sha256').update(`${salt}|${ip}|${userAgent}`).digest('hex');

    // Una sola sentencia (atómica): si el hash ya existía hoy suma una visualización; si no, también un visitante.
    await this.pool.query(
      `with seen as (
         insert into daily_visitors (day, hash) values ($1, $2) on conflict do nothing returning 1
       )
       insert into page_views (day, views, visitors) values ($1, 1, (select count(*)::int from seen))
       on conflict (day) do update
         set views = page_views.views + 1, visitors = page_views.visitors + excluded.visitors`,
      [day, hash],
    );
    if (this.totalCache) this.totalCache.value += 1;
  }

  /** La sal del día se crea en la primera visita del día; al hacerlo se borra lo de hace más de 2 días. */
  private async saltFor(day: string): Promise<string> {
    if (this.saltCache?.day === day) return this.saltCache.salt;
    await this.pool.query('insert into daily_salts (day, salt) values ($1, $2) on conflict (day) do nothing', [
      day, crypto.randomBytes(16).toString('hex'),
    ]);
    const { rows } = await this.pool.query<{ salt: string }>('select salt from daily_salts where day = $1', [day]);
    const salt = rows[0]!.salt;
    await this.pool.query('delete from daily_visitors where day < $1::date - 1', [day]);
    await this.pool.query('delete from daily_salts where day < $1::date - 1', [day]);
    this.saltCache = { day, salt };
    return salt;
  }

  /** Total de visualizaciones, con caché corta para no consultar la base en cada carga. */
  async total(): Promise<number> {
    if (this.totalCache && Date.now() - this.totalCache.at < TOTAL_TTL_MS) return this.totalCache.value;
    const { rows } = await this.pool.query<{ n: number }>('select coalesce(sum(views), 0)::int as n from page_views');
    this.totalCache = { value: rows[0]?.n ?? 0, at: Date.now() };
    return this.totalCache.value;
  }

  async stats(): Promise<VisitStats> {
    const today = dayKey(this.timeZone);
    const [recent, totals] = await Promise.all([
      this.pool.query<DayStats>(
        `select day::text as day, views, visitors from page_views where day >= $1::date - 29 order by day desc`, [today],
      ),
      this.pool.query<{ v: number; u: number }>(
        'select coalesce(sum(views), 0)::int as v, coalesce(sum(visitors), 0)::int as u from page_views',
      ),
    ]);

    const byDay = new Map(recent.rows.map((r) => [r.day, r]));
    const sum = (days: number) => {
      let views = 0;
      let visitors = 0;
      for (let i = 0; i < days; i++) {
        const row = byDay.get(shiftDay(today, -i));
        views += row?.views ?? 0;
        visitors += row?.visitors ?? 0;
      }
      return { views, visitors };
    };
    const series = Array.from({ length: 14 }, (_, i) => {
      const day = shiftDay(today, -i);
      return { day, views: byDay.get(day)?.views ?? 0, visitors: byDay.get(day)?.visitors ?? 0 };
    });

    return {
      totalViews: totals.rows[0]?.v ?? 0,
      totalVisitorDays: totals.rows[0]?.u ?? 0,
      today: series[0]!,
      last7: sum(7),
      last30: sum(30),
      series,
      maxDay: Math.max(1, ...series.map((d) => d.views)),
    };
  }
}

/** Suma días a 'YYYY-MM-DD' sin depender de la zona horaria del servidor. */
export function shiftDay(day: string, delta: number): string {
  const [y, m, d] = day.split('-').map(Number) as [number, number, number];
  return new Date(Date.UTC(y, m - 1, d + delta)).toISOString().slice(0, 10);
}
