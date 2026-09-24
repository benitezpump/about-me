const MONTHS = [
  'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

/** '2023-02-01' → '02/2023' */
export function monthYear(date: string): string {
  const [y, m] = date.split('-');
  return `${m}/${y}`;
}

/** '2015-03-01' → 'marzo de 2015' */
export function longMonthYear(date: string): string {
  const [y, m] = date.split('-');
  return `${MONTHS[Number(m) - 1]} de ${y}`;
}

export interface Period {
  /** Texto libre que sustituye al periodo calculado (p. ej. '21 jul – 19 oct 2022'). */
  label: string | null;
  from: string;
  to: string | null;
  /** Sin fecha de fin: se muestra como "actualidad". */
  current: boolean;
}

export function buildPeriod(start: string, end: string | null, label: string | null = null): Period {
  return {
    label: label && label.trim() ? label.trim() : null,
    from: monthYear(start),
    to: end ? monthYear(end) : null,
    current: end === null && !(label && label.trim()),
  };
}

/** 'Ana Prueba' → 'AP' (para el favicon). */
export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]!.toUpperCase())
    .join('');
}

/** Divide por líneas en blanco; descarta párrafos vacíos. */
export function splitParagraphs(text: string): string[] {
  return text
    .split(/\r?\n\s*\r?\n/)
    .map((p) => p.trim())
    .filter(Boolean);
}
