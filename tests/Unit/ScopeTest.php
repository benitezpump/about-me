<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Alarma de alcance del panel (portada de tests/scope.test.ts).
 *
 * El panel se mantiene con un alcance pequeño (un administrador; campos de texto, número, fecha, casilla, lista y enlace)
 * porque cada capacidad nueva es superficie de seguridad. Ver docs/decisions/0001-arquitectura-y-alcance-del-panel.md.
 *
 * Estas pruebas NO prohíben cambiar el alcance: obligan a hacerlo a propósito. Si una falla, no la "arregles" editándola sin
 * más: lee el documento, cuenta las señales de crecimiento (archivos, texto enriquecido, varios usuarios o roles, varios
 * idiomas, borradores, 2FA o correo) y, si sigues, actualiza la prueba y el documento en el mismo cambio.
 */
class ScopeTest extends TestCase
{
    private const ADR = 'docs/decisions/0001-arquitectura-y-alcance-del-panel.md';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** El documento vive en la raíz del repositorio (hoy `../docs`; tras el corte, `docs`). */
    private function adrPath(): string
    {
        foreach ([$this->root().'/'.self::ADR, dirname($this->root()).'/'.self::ADR] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        $this->fail('No se encontró '.self::ADR);
    }

    private function because(string $what): string
    {
        return $what.' → Esto cambia el alcance del panel. Lee '.self::ADR.': con dos o más señales de crecimiento la decisión '
            .'acordada es no construirlo a mano. Si decides seguir, actualiza esta prueba y el documento.';
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    public function test_los_formularios_no_usan_subida_de_archivos_ni_texto_enriquecido(): void
    {
        $prohibidos = '/\b(FileUpload|SpatieMediaLibraryFileUpload|RichEditor|MarkdownEditor|TinyEditor|ColorPicker|Builder|KeyValue|CodeEditor)::make/';
        $encontrados = [];
        foreach ($this->phpFiles($this->root().'/app') as $file) {
            if (preg_match($prohibidos, (string) file_get_contents($file), $m)) {
                $encontrados[] = str_replace($this->root().'/', '', $file).' ('.$m[1].')';
            }
        }

        $this->assertSame([], $encontrados, $this->because('Aparecieron componentes de subida o de texto enriquecido: '.implode(', ', $encontrados).'.'));
    }

    public function test_no_hay_dependencias_de_archivos_editores_idiomas_2fa_ni_roles(): void
    {
        $composer = json_decode((string) file_get_contents($this->root().'/composer.json'), true);
        $nombres = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));
        $senales = '/media-library|medialibrary|intervention|glide|spatie\/laravel-permission|bouncer|laratrust|tiptap|quill|tinymce|ckeditor|lexical|laravel-lang|laravel-translatable|astrotomic|pragmarx\/google2fa|laragear\/two-factor|fortify|jetstream|breeze|sanctum|passport/i';
        $encontradas = array_values(array_filter($nombres, fn ($n) => preg_match($senales, $n) === 1));

        $this->assertSame([], $encontradas, $this->because('Nuevas dependencias que apuntan a una señal de crecimiento: '.implode(', ', $encontradas).'.'));
    }

    public function test_el_modelo_de_usuarios_sigue_siendo_de_un_solo_administrador_sin_roles(): void
    {
        $sql = '';
        foreach ([...glob($this->root().'/database/sql/*.sql'), ...glob($this->root().'/database/migrations/*.php')] as $file) {
            $sql .= file_get_contents($file)."\n";
        }

        preg_match('/create table admin_users \((.*?)\n\);/s', $sql, $tabla);
        $this->assertNotEmpty($tabla, 'no se encontró la tabla admin_users');
        $this->assertDoesNotMatchRegularExpression('/\brol(es)?\b|\brole(s)?\b|permission/i', $tabla[1], $this->because('admin_users ahora tiene roles o permisos.'));
        $this->assertDoesNotMatchRegularExpression('/create table (roles?|permissions?|user_roles|users)\b/i', $sql, $this->because('Aparecieron tablas de roles o usuarios.'));
        $this->assertDoesNotMatchRegularExpression('/alter table admin_users add column (role|roles|permission)/i', $sql, $this->because('Se añadieron roles a admin_users.'));
        $this->assertDoesNotMatchRegularExpression('/Schema::create\(\'(roles|permissions|users|model_has_roles)\'/', $sql, $this->because('Aparecieron tablas de roles o usuarios.'));
    }

    public function test_el_panel_no_activa_registro_recuperacion_por_correo_ni_verificacion(): void
    {
        $panel = (string) file_get_contents($this->root().'/app/Providers/Filament/AdminPanelProvider.php');

        $this->assertDoesNotMatchRegularExpression('/->(registration|passwordReset|emailVerification|emailChangeVerification|multiFactorAuthentication|profile)\(/', $panel,
            $this->because('El panel activó registro, recuperación de contraseña, verificación de correo, 2FA o perfil.'));
    }

    public function test_el_documento_de_la_decision_existe_y_enumera_las_senales_de_crecimiento(): void
    {
        $adr = (string) file_get_contents($this->adrPath());
        foreach (['imágenes o archivos', 'Texto enriquecido', 'roles y permisos', 'Varios idiomas', 'Borradores', 'dos pasos'] as $senal) {
            $this->assertStringContainsString($senal, $adr, "falta la señal \"{$senal}\" en ".self::ADR);
        }
    }
}
