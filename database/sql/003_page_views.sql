-- Contador de visualizaciones de la portada, pensado para la privacidad:
--   * page_views guarda solo totales por día (no hay registro por visita).
--   * daily_visitors guarda un hash (IP + navegador + sal del día) únicamente para no contar dos veces
--     al mismo visitante en el mismo día. La sal rota cada día y ambas tablas se purgan a los 2 días,
--     así que el hash no permite seguir a nadie de un día a otro.

alter table profile add column show_view_count boolean not null default true;

create table page_views (
  day      date primary key,
  views    int not null default 0,
  visitors int not null default 0
);

create table daily_salts (
  day  date primary key,
  salt text not null
);

create table daily_visitors (
  day  date not null,
  hash text not null,
  primary key (day, hash)
);
