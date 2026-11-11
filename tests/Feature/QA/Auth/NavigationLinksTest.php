<?php

namespace Tests\Feature\QA\Auth;

use App\Models\Organization;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/**
 * QA: navegacion. El menu lateral, la barra superior y los submenus (Configuracion, Usuarios,
 * Tratamientos) deben enlazar a rutas existentes; ningun enlace de layout queda en "#" y el menu
 * solo muestra los modulos permitidos al rol.
 */
class NavigationLinksTest extends TestCase
{
    /** @return array<int,string> */
    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*?(?<![-\w])href="([^"]*)"/i', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    private function internal(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(javascript:|mailto:|tel:|data:)#i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            $host = parse_url($href, PHP_URL_HOST);
            if (! in_array($host, ['localhost', '127.0.0.1', parse_url(config('app.url'), PHP_URL_HOST)], true)) {
                return null;
            }
            $href = (parse_url($href, PHP_URL_PATH) ?: '/').(($q = parse_url($href, PHP_URL_QUERY)) ? "?{$q}" : '');
        }

        return explode('#', $href)[0] ?: null;
    }

    public function test_sidebar_has_no_dead_links_and_matches_permissions(): void
    {
        $expected = [
            'admin@dental.test' => ['dashboard', 'agenda', 'pacientes', 'recetas', 'presupuestos', 'facturacion', 'finanzas', 'inventario', 'configuracion'],
        ];

        foreach (['admin@dental.test', 'doctor@dental.test', 'recepcion@dental.test'] as $email) {
            Organization::flushCurrent();
            $this->flushSession();
            $user = $this->userByEmail($email);
            $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

            // barra lateral: <aside ...> ... </aside>
            $this->assertTrue((bool) preg_match('#<aside\b.*?</aside>#s', $html, $aside), 'No se encontro el <aside> del menu');
            $links = $this->hrefs($aside[0]);
            $this->assertNotEmpty($links);
            foreach ($links as $href) {
                $this->assertNotSame('#', $href, "Enlace muerto '#' en el menu lateral para {$email}");
                $this->assertNotSame('', $href, "Enlace vacio en el menu lateral para {$email}");
            }

            // cada modulo visible debe ser consistente con hasPermission(modulo,'ver')
            foreach (config('menu.sections') as $section) {
                foreach ($section['items'] as $item) {
                    $visible = in_array(route($item['route'], [], false), array_map(fn ($h) => parse_url($h, PHP_URL_PATH), $links), true);
                    $can = $user->fresh()->hasPermission($item['module'], 'ver');
                    $this->assertSame($can, $visible, "Menu '{$item['label']}' para {$email}: permiso=".var_export($can, true).' visible='.var_export($visible, true));
                }
            }
        }
    }

    public function test_crawl_internal_links_from_main_pages_as_admin(): void
    {
        $this->seed(DemoSeeder::class);
        Organization::flushCurrent();
        $admin = $this->userByEmail('admin@dental.test');

        $seeds = [
            '/dashboard', '/agenda', '/pacientes', '/recetas', '/recetas/medicamentos', '/presupuestos', '/tratamientos',
            '/facturacion', '/finanzas', '/finanzas/cajas', '/finanzas/categorias', '/inventario', '/inventario/proveedores',
            '/configuracion', '/configuracion/facturacion', '/configuracion/notificaciones', '/configuracion/sucursales',
            '/configuracion/perfil', '/usuarios', '/roles', '/documentos', '/notificaciones', '/profile',
        ];

        $seen = [];
        $broken = [];
        $deadAnchors = [];
        foreach ($seeds as $page) {
            Organization::flushCurrent();
            $this->flushSession();
            $resp = $this->actingAs($admin)->get($page);
            $this->assertLessThan(400, $resp->getStatusCode(), "La pagina {$page} no carga");
            $html = $resp->getContent();

            // enlaces '#' a nivel layout (aside, header/topbar, nav de submenus)
            foreach (['aside', 'header', 'nav'] as $tag) {
                if (preg_match_all("#<{$tag}\\b.*?</{$tag}>#s", $html, $blocks)) {
                    foreach ($blocks[0] as $blk) {
                        foreach ($this->hrefs($blk) as $h) {
                            if (trim($h) === '#' || trim($h) === '') {
                                $deadAnchors[$page.' <'.$tag.'>'] = true;
                            }
                        }
                    }
                }
            }

            foreach ($this->hrefs($html) as $href) {
                $url = $this->internal($href);
                if ($url === null || isset($seen[$url]) || preg_match('#/(logout|salir)$#', $url) || str_contains($url, 'signature=')) {
                    continue;
                }
                $seen[$url] = true;
                Organization::flushCurrent();
                $this->flushSession();
                $r = $this->actingAs($admin)->get($url);
                if ($r->getStatusCode() >= 400) {
                    $broken[] = "{$r->getStatusCode()} {$url} (desde {$page})";
                }
            }
        }

        $this->assertSame([], array_keys($deadAnchors), "Enlaces '#' en layout/submenus:\n".implode("\n", array_keys($deadAnchors)));
        $this->assertSame([], $broken, "Enlaces rotos:\n".implode("\n", $broken));
    }

    public function test_every_submenu_link_target_exists(): void
    {
        // Rutas que los submenus de Configuracion/Usuarios/Tratamientos intentan enlazar
        foreach (['configuracion.index', 'configuracion.facturacion', 'configuracion.notificaciones', 'configuracion.sucursales', 'configuracion.perfil', 'usuarios.index', 'roles.index', 'tratamientos.index', 'documentos.index', 'notifications.index'] as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "Falta la ruta {$name}");
        }
    }
}
