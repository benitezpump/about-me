<?php

namespace Tests\Feature;

use App\Filament\Resources\Certifications\Pages\CreateCertification;
use App\Filament\Resources\Certifications\Pages\ListCertifications;
use App\Filament\Resources\CertificationGroups\Pages\ListCertificationGroups;
use App\Filament\Resources\ContactLinks\Pages\CreateContactLink;
use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\Education\Pages\CreateEducation;
use App\Filament\Resources\Education\Pages\EditEducation;
use App\Filament\Resources\Experiences\Pages\CreateExperience;
use App\Filament\Resources\Experiences\Pages\EditExperience;
use App\Filament\Resources\Experiences\Pages\ListExperiences;
use App\Filament\Resources\NowItems\Pages\CreateNowItem;
use App\Filament\Resources\NowItems\Pages\ListNowItems;
use App\Filament\Resources\Profiles\Pages\CreateProfile;
use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Technologies\Pages\CreateTechnology;
use App\Filament\Resources\Technologies\Pages\EditTechnology;
use App\Filament\Resources\Technologies\Pages\ListTechnologies;
use App\Filament\Resources\ToolGroups\Pages\CreateToolGroup;
use App\Filament\Resources\Workshops\Pages\CreateWorkshop;
use App\Models\Certification;
use App\Models\CertificationGroup;
use App\Models\Experience;
use App\Models\NowItem;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Technology;
use App\Models\ToolGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AsAdmin;
use Tests\Support\Fixture;
use Tests\TestCase;

/** Edición del contenido desde el panel: cada cambio debe verse en el sitio al instante (invalida la caché). */
class AdminContentTest extends TestCase
{
    use AsAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    private function home(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    // ------------------------------------------------------------------------------------------- Perfil ----

    public function test_con_la_base_vacia_el_perfil_se_crea_desde_el_panel_y_el_sitio_lo_muestra(): void
    {
        $this->get('/admin/perfil')->assertRedirect('/admin/perfil/create');

        Livewire::test(CreateProfile::class)
            ->fillForm([
                'first_names' => 'Ana', 'last_names' => 'Prueba', 'display_name' => 'Ana', 'site_title' => 'Ana Prueba',
                'headline' => 'Desarrolladora.', 'intro' => "Primer párrafo.\n\nSegundo párrafo.",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profile = Profile::findOrFail(1);
        $this->assertSame(['Primer párrafo.', 'Segundo párrafo.'], $profile->intro);
        $this->assertSame('', $profile->location, 'un campo vacío se guarda como texto vacío, no como NULL');
        $this->assertStringContainsString('<p class="lead">Segundo párrafo.</p>', $this->home());
        $this->get('/admin/perfil/create')->assertForbidden(); // ya existe: no se puede crear otro
    }

    public function test_editar_el_perfil_divide_los_parrafos_y_cambia_el_sitio_al_instante(): void
    {
        Fixture::insert();
        $this->home(); // calienta la caché

        Livewire::test(EditProfile::class)
            ->assertFormSet(['headline' => 'Desarrolladora de ejemplo.'])
            ->fillForm(['headline' => 'Titular nuevo.', 'intro' => "Uno.\n\n\n  \nDos.\r\n\r\nTres.", 'location' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = Profile::findOrFail(1);
        $this->assertSame(['Uno.', 'Dos.', 'Tres.'], $profile->intro);
        $this->assertSame('', $profile->location);
        $html = $this->home();
        $this->assertStringContainsString('Titular nuevo.', $html);
        $this->assertStringContainsString('<p class="lead">Tres.</p>', $html);
    }

    public function test_el_perfil_valida_obligatorios_enlaces_seguros_y_longitudes(): void
    {
        Fixture::insert();

        Livewire::test(EditProfile::class)
            ->fillForm(['headline' => '', 'cta_url' => 'javascript:alert(1)', 'meta_description' => str_repeat('x', 301)])
            ->call('save')
            ->assertHasFormErrors(['headline' => 'required', 'cta_url', 'meta_description']);

        $this->assertSame('Desarrolladora de ejemplo.', Profile::findOrFail(1)->headline, 'no se guardó nada');
    }

    // ------------------------------------------------------------------------------------ Actualmente ----

    public function test_crear_un_elemento_de_actualmente_lo_publica_y_escapa_el_html(): void
    {
        Fixture::insert();

        Livewire::test(CreateNowItem::class)
            ->fillForm(['since_label' => 'Desde hoy', 'title' => '<script>alert(1)</script>', 'position' => 5, 'visible' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('', NowItem::where('since_label', 'Desde hoy')->firstOrFail()->body);
        $html = $this->home();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);

        $lista = $this->get('/admin/actualmente')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $lista, 'la lista del panel también escapa');
    }

    public function test_los_elementos_de_actualmente_se_listan_y_se_eliminan(): void
    {
        Fixture::insert();
        $item = NowItem::where('visible', true)->firstOrFail();

        Livewire::test(ListNowItems::class)
            ->assertCanSeeTableRecords(NowItem::all())
            ->callAction(TestAction::make('delete')->table($item));

        $this->assertNull(NowItem::find($item->id));
        $this->assertStringNotContainsString('en Acme.', $this->home());
    }

    // ------------------------------------------------------------------------------------ Tecnologías ----

    public function test_una_tecnologia_duplicada_aunque_cambie_la_mayuscula_o_los_espacios_se_rechaza(): void
    {
        Fixture::insert();

        Livewire::test(CreateTechnology::class)->fillForm(['name' => 'php'])->call('create')->assertHasFormErrors(['name']);
        Livewire::test(CreateTechnology::class)->fillForm(['name' => '  PHP  '])->call('create')->assertHasFormErrors(['name']);
        Livewire::test(CreateTechnology::class)->fillForm(['name' => ''])->call('create')->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateTechnology::class)->fillForm(['name' => str_repeat('x', 81)])->call('create')->assertHasFormErrors(['name']);
        $this->assertSame(3, Technology::count());

        Livewire::test(CreateTechnology::class)->fillForm(['name' => 'Rust'])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Technology::where('name', 'Rust')->exists());
    }

    public function test_renombrar_una_tecnologia_la_actualiza_en_todo_el_sitio(): void
    {
        Fixture::insert();
        $php = Technology::where('name', 'PHP')->firstOrFail();
        $this->assertStringContainsString('<ul class="stack chips" aria-label="Tecnologías"><li>PHP</li><li>Laravel (reportes)</li></ul>', $this->home());

        Livewire::test(EditTechnology::class, ['record' => $php->id])
            ->fillForm(['name' => 'PHP 8'])->call('save')->assertHasNoFormErrors();

        $html = $this->home();
        $this->assertStringContainsString('<ul class="stack chips" aria-label="Tecnologías"><li>PHP 8</li><li>Laravel (reportes)</li></ul>', $html, 'en el proyecto');
        $this->assertStringContainsString('<dd>Node.js, PHP 8.</dd>', $html, 'y en Herramientas');
    }

    public function test_guardar_una_tecnologia_sin_cambiar_su_nombre_no_choca_consigo_misma(): void
    {
        Fixture::insert();
        $php = Technology::where('name', 'PHP')->firstOrFail();

        Livewire::test(EditTechnology::class, ['record' => $php->id])->call('save')->assertHasNoFormErrors();
    }

    public function test_borrar_una_tecnologia_en_uso_se_bloquea_con_un_mensaje_y_una_sin_uso_se_borra(): void
    {
        Fixture::insert();
        $enUso = Technology::where('name', 'PHP')->firstOrFail();
        $libre = Technology::create(['name' => 'Sin uso']);

        Livewire::test(ListTechnologies::class)
            ->callAction(TestAction::make('delete')->table($enUso))
            ->assertNotified('No se puede eliminar porque se está usando o tiene elementos asociados (proyectos, materias, talleres, certificaciones o tecnologías). Quítalo de donde aparece o elimina primero lo asociado.');
        $this->assertTrue(Technology::whereKey($enUso->id)->exists(), 'sigue en el catálogo');

        Livewire::test(ListTechnologies::class)->callAction(TestAction::make('delete')->table($libre));
        $this->assertFalse(Technology::whereKey($libre->id)->exists());
    }

    public function test_la_lista_de_tecnologias_cuenta_en_cuantos_proyectos_y_grupos_se_usa_cada_una(): void
    {
        Fixture::insert();
        $php = Technology::withCount(['projectLinks', 'toolItems'])->where('name', 'PHP')->firstOrFail();

        $this->assertSame(1, $php->project_links_count);
        $this->assertSame(1, $php->tool_items_count);
        Livewire::test(ListTechnologies::class)->assertCanSeeTableRecords(Technology::all());
    }

    // ------------------------------------------------------------------------------------ Herramientas ----

    public function test_un_grupo_de_herramientas_se_arma_eligiendo_del_catalogo_en_el_orden_dado(): void
    {
        Fixture::insert();
        $ids = Technology::pluck('id', 'name');

        Livewire::test(CreateToolGroup::class)
            ->fillForm([
                'label' => 'Nuevo grupo', 'position' => 9,
                'items' => [['technology_id' => $ids['Laravel']], ['technology_id' => $ids['PHP']]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $grupo = ToolGroup::where('label', 'Nuevo grupo')->firstOrFail();
        $this->assertSame(['Laravel', 'PHP'], $grupo->technologies->pluck('name')->all());
        $this->assertStringContainsString('<dt>Nuevo grupo</dt>', $this->home());
        $this->assertStringContainsString('<dd>Laravel, PHP.</dd>', $this->home());
    }

    public function test_la_misma_tecnologia_no_puede_repetirse_en_un_grupo(): void
    {
        Fixture::insert();
        $php = Technology::where('name', 'PHP')->firstOrFail()->id;

        Livewire::test(CreateToolGroup::class)
            ->fillForm(['label' => 'Repetido', 'items' => [['technology_id' => $php], ['technology_id' => $php]]])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertFalse(ToolGroup::where('label', 'Repetido')->exists());
    }

    // ------------------------------------------------------------------------------ Empleos y docencia ----

    public function test_crear_una_experiencia_valida_fechas_y_muestra_el_titulo_de_talleres_solo_en_docencia(): void
    {
        Fixture::insert();

        Livewire::test(CreateExperience::class)
            ->fillForm(['kind' => 'work', 'company' => 'Otra', 'role' => 'Dev', 'start_date' => '2022-05-01', 'end_date' => '2021-01-01'])
            ->assertFormFieldHidden('workshops_title')
            ->call('create')
            ->assertHasFormErrors(['end_date']);

        Livewire::test(CreateExperience::class)
            ->fillForm(['kind' => 'teaching'])
            ->assertFormFieldVisible('workshops_title');

        Livewire::test(CreateExperience::class)
            ->fillForm(['kind' => 'work', 'company' => 'Otra', 'role' => 'Dev', 'start_date' => '2019-05-01', 'end_date' => '2020-01-01', 'position' => 3])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('', Experience::where('company', 'Otra')->firstOrFail()->location);
    }

    public function test_no_se_puede_cambiar_el_tipo_de_una_experiencia_que_tiene_elementos_asociados(): void
    {
        Fixture::insert();
        $work = Experience::where('kind', 'work')->firstOrFail();
        $teaching = Experience::where('kind', 'teaching')->firstOrFail();

        Livewire::test(EditExperience::class, ['record' => $work->id])
            ->fillForm(['kind' => 'teaching'])->call('save')
            ->assertHasFormErrors(['kind']);
        Livewire::test(EditExperience::class, ['record' => $teaching->id])
            ->fillForm(['kind' => 'work'])->call('save')
            ->assertHasFormErrors(['kind']);

        $this->assertSame('work', $work->fresh()->kind);
    }

    public function test_borrar_una_experiencia_con_proyectos_se_bloquea_y_una_vacia_se_borra(): void
    {
        Fixture::insert();
        $conProyectos = Experience::where('kind', 'work')->firstOrFail();
        $vacia = Experience::create(['kind' => 'work', 'company' => 'Vacía', 'role' => 'x', 'start_date' => '2010-01-01']);

        Livewire::test(ListExperiences::class)
            ->callAction(TestAction::make('delete')->table($conProyectos))
            ->assertNotified();
        $this->assertTrue(Experience::whereKey($conProyectos->id)->exists());

        Livewire::test(ListExperiences::class)->callAction(TestAction::make('delete')->table($vacia));
        $this->assertFalse(Experience::whereKey($vacia->id)->exists());
    }

    // ---------------------------------------------------------------------------------------- Proyectos ----

    public function test_crear_un_proyecto_con_tecnologias_y_puntos_de_detalle_lo_publica_en_orden(): void
    {
        Fixture::insert();
        $work = Experience::where('kind', 'work')->firstOrFail();
        $ids = Technology::pluck('id', 'name');

        Livewire::test(CreateProject::class)
            ->fillForm([
                'kind' => 'work', 'experience_id' => $work->id, 'title' => 'Proyecto de 2030', 'description' => 'Nuevo.',
                'start_date' => '2030-01-01', 'visible' => true, 'position' => 0,
                'technologyLinks' => [['technology_id' => $ids['Node.js'], 'note' => 'API'], ['technology_id' => $ids['PHP'], 'note' => '']],
                'highlights' => [['label' => 'Etiqueta', 'body' => 'Primer punto'], ['label' => '', 'body' => 'Segundo punto']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Project::where('title', 'Proyecto de 2030')->firstOrFail();
        $this->assertSame(['Node.js', 'PHP'], $p->technologies->pluck('name')->all());
        $this->assertSame('API', $p->technologies->first()->pivot->note);
        $this->assertSame(['Primer punto', 'Segundo punto'], $p->highlights->pluck('body')->all());

        $html = $this->home();
        $this->assertLessThan(strpos($html, 'Proyecto reciente'), strpos($html, 'Proyecto de 2030'), '2030 es la fecha más reciente');
        $this->assertStringContainsString('<ul class="stack chips" aria-label="Tecnologías"><li>Node.js (API)</li><li>PHP</li></ul>', $html);
        $this->assertStringContainsString('<li><strong>Etiqueta:</strong> Primer punto</li>', $html);
        $this->assertStringContainsString('<li>Segundo punto</li>', $html);
    }

    public function test_un_proyecto_de_trabajo_necesita_experiencia_y_las_fechas_deben_estar_en_orden(): void
    {
        Fixture::insert();

        Livewire::test(CreateProject::class)
            ->fillForm(['kind' => 'work', 'title' => 'x', 'start_date' => '2020-01-01'])
            ->call('create')
            ->assertHasFormErrors(['experience_id' => 'required']);

        Livewire::test(CreateProject::class)
            ->fillForm(['kind' => 'personal', 'title' => 'x', 'start_date' => '2020-05-01', 'end_date' => '2020-01-01'])
            ->call('create')
            ->assertHasFormErrors(['end_date']);

        $this->assertFalse(Project::where('title', 'x')->exists());
    }

    public function test_un_proyecto_propio_no_cuelga_de_ninguna_experiencia_aunque_se_haya_elegido_una_antes(): void
    {
        Fixture::insert();
        $work = Experience::where('kind', 'work')->firstOrFail();
        $proyecto = Project::where('title', 'Proyecto reciente')->firstOrFail();

        Livewire::test(EditProject::class, ['record' => $proyecto->id])
            ->fillForm(['kind' => 'personal'])->call('save')->assertHasNoFormErrors();

        $this->assertNull($proyecto->fresh()->experience_id);
        $this->assertStringContainsString('<article class="project">', $this->home());
        $this->assertNotNull($work->id);
    }

    public function test_editar_reemplaza_las_filas_hijas_y_respeta_el_orden_enviado(): void
    {
        Fixture::insert();
        $ids = Technology::pluck('id', 'name');
        $proyecto = Project::where('title', 'Proyecto reciente')->firstOrFail();

        Livewire::test(EditProject::class, ['record' => $proyecto->id])
            ->fillForm([
                'technologyLinks' => [['technology_id' => $ids['Node.js'], 'note' => ''], ['technology_id' => $ids['Laravel'], 'note' => 'nota']],
                'highlights' => [['label' => '', 'body' => 'Único punto']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $html = $this->home();
        $this->assertStringContainsString('<ul class="stack chips" aria-label="Tecnologías"><li>Node.js</li><li>Laravel (nota)</li></ul>', $html);
        $this->assertStringContainsString('<li>Único punto</li>', $html);
        $this->assertStringNotContainsString('informes en PDF.', $html);
    }

    // ---------------------------------------------------------------------- Materias, talleres y estudios ----

    public function test_los_selectores_de_experiencia_solo_ofrecen_las_del_tipo_correcto(): void
    {
        Fixture::insert();
        $work = Experience::where('kind', 'work')->firstOrFail()->id;
        $teaching = Experience::where('kind', 'teaching')->firstOrFail()->id;
        $opciones = fn (Select $s): array => array_keys($s->getOptions());

        Livewire::test(CreateProject::class)->fillForm(['kind' => 'work'])
            ->assertFormFieldExists('experience_id', fn (Select $s) => $opciones($s) === [$work]);
        Livewire::test(CreateCourse::class)
            ->assertFormFieldExists('experience_id', fn (Select $s) => $opciones($s) === [$teaching]);
        Livewire::test(CreateWorkshop::class)
            ->assertFormFieldExists('experience_id', fn (Select $s) => $opciones($s) === [$teaching]);
    }

    public function test_crear_una_materia_y_un_taller_los_publica(): void
    {
        Fixture::insert();
        $teaching = Experience::where('kind', 'teaching')->firstOrFail()->id;

        Livewire::test(CreateCourse::class)
            ->fillForm(['experience_id' => $teaching, 'subject' => 'Sistemas operativos', 'start_date' => '2026-01-01', 'position' => 0])
            ->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateWorkshop::class)
            ->fillForm(['experience_id' => $teaching, 'name' => 'Git', 'period_label' => '01 – 03 mar 2026', 'sort_date' => '2026-03-01', 'position' => 0])
            ->call('create')->assertHasNoFormErrors();

        $html = $this->home();
        $this->assertStringContainsString('<dd>Sistemas operativos</dd>', $html);
        $this->assertStringContainsString('<dt>01 – 03 mar 2026</dt><dd>Git</dd>', $html);
    }

    // ---------------------------------------------------------------------------------------------- Estudios ----

    public function test_un_estudio_puede_llevar_cedula_profesional_pero_es_opcional_y_solo_numeros(): void
    {
        Fixture::insert();

        Livewire::test(CreateEducation::class)
            ->fillForm(['title' => 'Sin cédula', 'period_label' => '2010', 'position' => 5])
            ->call('create')->assertHasNoFormErrors();
        $this->assertNull(DB::table('education')->where('title', 'Sin cédula')->value('professional_license'));

        foreach (['12345', 'ABC1234567', '1234-567'] as $mala) {
            Livewire::test(CreateEducation::class)
                ->fillForm(['title' => 'Mala', 'period_label' => '2011', 'professional_license' => $mala, 'position' => 6])
                ->call('create')->assertHasFormErrors(['professional_license']);
        }
        $this->assertSame(0, DB::table('education')->where('title', 'Mala')->count());

        Livewire::test(CreateEducation::class)
            ->fillForm(['title' => 'Con cédula', 'period_label' => '2012', 'professional_license' => '9876543', 'position' => 7])
            ->call('create')->assertHasNoFormErrors();
        $this->assertStringContainsString('Cédula profesional 9876543.', $this->home());

        // Borrar el campo al editar la deja en NULL (no '') y desaparece del sitio.
        $id = DB::table('education')->where('title', 'Con cédula')->value('id');
        Livewire::test(EditEducation::class, ['record' => $id])
            ->fillForm(['professional_license' => ''])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull(DB::table('education')->where('id', $id)->value('professional_license'));
        $this->assertStringNotContainsString('Cédula profesional', $this->home());
    }

    // ------------------------------------------------------------------------------------ Certificaciones ----

    public function test_las_certificaciones_validan_el_anio_y_los_enlaces_peligrosos(): void
    {
        Fixture::insert();
        $grupo = CertificationGroup::where('title', 'Cursos')->firstOrFail()->id;
        $base = ['group_id' => $grupo, 'name' => 'Curso', 'year' => 2024, 'position' => 0];

        Livewire::test(CreateCertification::class)->fillForm($base + ['url' => 'javascript:alert(1)'])->call('create')->assertHasFormErrors(['url']);
        Livewire::test(CreateCertification::class)->fillForm(['year' => 1800] + $base)->call('create')->assertHasFormErrors(['year']);
        Livewire::test(CreateCertification::class)->fillForm(['year' => 2101] + $base)->call('create')->assertHasFormErrors(['year']);
        Livewire::test(CreateCertification::class)->fillForm(['group_id' => null] + $base)->call('create')->assertHasFormErrors(['group_id' => 'required']);
        $this->assertSame(2, Certification::count());

        Livewire::test(CreateCertification::class)->fillForm($base + ['url' => 'https://example.com/ok'])->call('create')->assertHasNoFormErrors();
        $this->assertStringContainsString('href="https://example.com/ok"', $this->home());
    }

    public function test_un_grupo_de_certificaciones_con_certificados_no_se_puede_borrar(): void
    {
        Fixture::insert();
        $conCerts = CertificationGroup::where('title', 'Cursos')->firstOrFail();
        $vacio = CertificationGroup::where('title', 'Grupo vacío')->firstOrFail();

        Livewire::test(ListCertificationGroups::class)->callAction(TestAction::make('delete')->table($conCerts))->assertNotified();
        $this->assertTrue(CertificationGroup::whereKey($conCerts->id)->exists());

        Livewire::test(ListCertificationGroups::class)->callAction(TestAction::make('delete')->table($vacio));
        $this->assertFalse(CertificationGroup::whereKey($vacio->id)->exists());
        Livewire::test(ListCertifications::class)->assertCanSeeTableRecords(Certification::all());
    }

    // ------------------------------------------------------------------------------ Enlaces de contacto ----

    public function test_los_enlaces_de_contacto_exigen_etiqueta_corta_y_enlace_seguro(): void
    {
        Fixture::insert();

        Livewire::test(CreateContactLink::class)->fillForm(['label' => '', 'url' => ''])->call('create')
            ->assertHasFormErrors(['label' => 'required', 'url' => 'required']);
        Livewire::test(CreateContactLink::class)->fillForm(['label' => str_repeat('x', 61), 'url' => 'https://a.com'])->call('create')
            ->assertHasFormErrors(['label']);
        Livewire::test(CreateContactLink::class)->fillForm(['label' => 'x', 'url' => 'data:text/html,<script>1</script>'])->call('create')
            ->assertHasFormErrors(['url']);
        Livewire::test(CreateContactLink::class)->fillForm(['label' => 'Blog', 'url' => 'https://blog.example.com', 'position' => 0])->call('create')
            ->assertHasNoFormErrors();

        $this->assertStringContainsString('>blog.example.com</a>', $this->home());
    }

    public function test_los_cambios_directos_con_sql_no_invalidan_pero_forget_si(): void
    {
        Fixture::insert();
        $this->home();

        DB::table('now_items')->update(['title' => 'Cambiado por SQL']);

        $this->assertStringNotContainsString('Cambiado por SQL', $this->home());
        \App\Services\SiteContent::forget();
        $this->assertStringContainsString('Cambiado por SQL', $this->home());
    }
}
