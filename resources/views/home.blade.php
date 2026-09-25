@extends('layouts.site')

@section('body')
<a class="skip" href="#inicio">Saltar al contenido</a>

<header class="top">
  <div class="wrap">
    <a class="brand" href="#inicio">{{ $profile->displayName }}</a>
    <nav aria-label="Secciones">
      @if (count($work))<a href="#trabajo">Trabajo</a>@endif
      @if (count($teaching))<a href="#docencia">Docencia</a>@endif
      @if (count($personalProjects))<a href="#proyectos">Proyectos</a>@endif
      @if (count($education) || count($certificationGroups))<a href="#formacion">Formación</a>@endif
      <a href="#contacto">Contacto</a>
      <button class="theme" type="button" aria-label="Cambiar a tema claro">
        <svg class="sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>
      </button>
    </nav>
  </div>
</header>

<main id="inicio">

  <section class="hero">
    <div class="wrap">
      <div class="hero-main">
        <h1><span>{{ $profile->firstNames }}</span><span>{{ $profile->lastNames }}</span></h1>
        <p class="role">{{ $profile->headline }}</p>
        @foreach ($profile->intro as $paragraph)<p class="lead">{{ $paragraph }}</p>
        @endforeach
        <div class="cta">
          @if ($profile->ctaUrl)<a class="btn" href="{{ $profile->ctaUrl }}"@if (\App\Support\Format::isHttp($profile->ctaUrl)) target="_blank" rel="noopener noreferrer"@endif>{{ $profile->ctaLabel }}</a>@endif
          @if (count($work))<a class="more" href="#trabajo">Ver mi trabajo</a>@endif
        </div>
      </div>

      @if (count($now))
      <aside class="now-panel" aria-labelledby="h-ahora">
        <h2 id="h-ahora">Actualmente</h2>
        @foreach ($now as $n)
        <div class="item">
          <p class="when">{{ $n->sinceLabel }}</p>
          <p><strong>{{ $n->title }}</strong>@if ($n->body) {{ $n->body }}@endif</p>
        </div>
        @endforeach
      </aside>
      @endif
    </div>
  </section>

  @if (count($toolGroups))
  <section class="sec" aria-labelledby="herramientas">
    <div class="wrap">
      <h2 id="herramientas">Herramientas</h2>
      <dl class="rows tools">
        @foreach ($toolGroups as $g)
        <div{!! $g->emphasis ? ' class="emphasis"' : '' !!}>
          <dt>{{ $g->label }}</dt>
          <dd>{{ implode(', ', $g->tools) }}.</dd>
        </div>
        @endforeach
      </dl>
    </div>
  </section>
  @endif

  @if (count($work))
  <section class="sec" id="trabajo" aria-labelledby="h-trabajo">
    <div class="wrap">
      <h2 id="h-trabajo">Trabajo</h2>
      <div>
        @foreach ($work as $e)
        <div class="exp">
          <p class="intro"><strong>{{ $e->role }} en @if ($e->article){{ $e->article }} @endif{{ $e->company }}</strong>{{ $e->location ? ', '.$e->location : '' }}{{ $e->since ? ', desde '.$e->since : '' }}.@if (count($e->projects)) Proyectos del más reciente al más antiguo:@endif</p>
          @if (count($e->projects))
          <button class="expand" type="button" aria-expanded="false">Expandir todo</button>
          <ol class="timeline">
            @foreach ($e->projects as $p)
            <li{!! $p->period->current ? ' class="current"' : '' !!}>
              <p class="when"><x-period :p="$p->period" /></p>
              <h3>{{ $p->title }}</h3>
              @if ($p->description)<p class="desc">{{ $p->description }}</p>@endif
              <x-details :p="$p" />
              @if ($p->stack)<p class="stack">{{ $p->stack }}</p>@endif
            </li>
            @endforeach
          </ol>
          @endif
        </div>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  @if (count($teaching))
  <section class="sec" id="docencia" aria-labelledby="h-docencia">
    <div class="wrap">
      <h2 id="h-docencia">Docencia</h2>
      <div>
        @foreach ($teaching as $e)
        <div class="exp">
          <p class="intro"><strong>{{ $e->role }} en @if ($e->article){{ $e->article }} @endif{{ $e->company }}</strong>{{ $e->location ? ', '.$e->location : '' }}{{ $e->since ? ', desde '.$e->since : '' }}.</p>

          @if (count($e->courses))
          <div class="group">
            <dl class="rows">
              @foreach ($e->courses as $c)<div><dt><x-period :p="$c->period" /></dt><dd>{{ $c->subject }}</dd></div>
              @endforeach
            </dl>
          </div>
          @endif

          @if (count($e->workshops))
          <div class="group">
            @if ($e->workshopsTitle)<h3>{{ $e->workshopsTitle }}</h3>@endif
            <dl class="rows">
              @foreach ($e->workshops as $w)<div><dt>{{ $w->label }}</dt><dd>{{ $w->name }}</dd></div>
              @endforeach
            </dl>
          </div>
          @endif
        </div>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  @if (count($personalProjects))
  <section class="sec" id="proyectos" aria-labelledby="h-proyectos">
    <div class="wrap">
      <h2 id="h-proyectos">Proyectos propios</h2>
      <div>
        @foreach ($personalProjects as $p)
        <article class="project">
          <p class="when"><x-period :p="$p->period" /></p>
          <h3>{{ $p->title }}</h3>
          @if ($p->description)<p class="desc">{{ $p->description }}</p>@endif
          @if (count($p->highlights))
          <ul>
            @foreach ($p->highlights as $h)<li>@if ($h->label)<strong>{{ $h->label }}:</strong> @endif{{ $h->body }}</li>
            @endforeach
          </ul>
          @endif
          @if ($p->stack)<p class="stack">{{ $p->stack }}</p>@endif
        </article>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  @if (count($education) || count($certificationGroups))
  <section class="sec" id="formacion" aria-labelledby="h-formacion">
    <div class="wrap">
      <h2 id="h-formacion">Formación</h2>
      <div>
        @if (count($education))
        <div class="group">
          <dl class="rows">
            @foreach ($education as $e)<div><dt>{{ $e->periodLabel }}</dt><dd><strong class="strong-text">{{ $e->title }}</strong>@if ($e->institution), {{ $e->institution }}@endif.@if ($e->professionalLicense) Cédula profesional {{ $e->professionalLicense }}.@endif</dd></div>
            @endforeach
          </dl>
        </div>
        @endif

        @foreach ($certificationGroups as $g)
        <div class="group">
          <h3>{{ $g->title }}</h3>
          <dl class="rows">
            @foreach ($g->items as $c)<div><dt>{{ $c->year }}</dt><dd>@if ($c->url)<x-extlink :url="$c->url" :text="$c->name" />@else{{ $c->name }}@endif{{ $c->issuer ? ', '.$c->issuer : '' }}.@if ($c->note) {{ $c->note }}@endif</dd></div>
            @endforeach
          </dl>
        </div>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  <section class="sec" id="contacto" aria-labelledby="h-contacto">
    <div class="wrap">
      <h2 id="h-contacto">Contacto</h2>
      <div>
        @if ($profile->contactPrompt)<p class="intro">{{ $profile->contactPrompt }}</p>@endif
        @if ($profile->ctaUrl)<p class="contact-mail"><x-extlink :url="$profile->ctaUrl" :text="$profile->ctaLabel" /></p>@endif
        @if ($profile->location || count($contactLinks))
        <dl class="rows contact-rows">
          @if ($profile->location)<div><dt>Ubicación</dt><dd>{{ $profile->location }}</dd></div>@endif
          @foreach ($contactLinks as $l)<div><dt>{{ $l->label }}</dt><dd><x-extlink :url="$l->url" :text="\App\Support\Format::displayUrl($l->url)" /></dd></div>
          @endforeach
        </dl>
        @endif
      </div>
    </div>
  </section>

</main>

<footer class="foot">
  <div class="wrap foot-row">
    <span>© {{ $year }} {{ $profile->firstNames }} {{ $profile->lastNames }}</span>
    @if ($viewsLabel)<span class="views"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>{{ $viewsLabel }}</span>@endif
  </div>
</footer>
@endsection
