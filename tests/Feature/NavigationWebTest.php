<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Every page a user can reach from the menu renders without a server error, against the seeded development database.
 * Each request runs in a fresh application: View::share() from one request must not hide a page that forgot its
 * layout data (the compliance pages once rendered only after another page had shared $general_setting).
 */
class NavigationWebTest extends TestCase
{
    private function fresh(): void
    {
        foreach (array_keys($this->app['db']->getConnections()) as $name) {
            $this->app['db']->purge($name);
        }
        $this->refreshApplication();
        $this->actingAs(User::first());
    }

    public function test_every_menu_link_renders_without_a_server_error(): void
    {
        $links = [];
        foreach (['/dashboard', '/purchases', '/sales'] as $page) {
            $this->fresh();
            preg_match_all('/href="(?:'.preg_quote(rtrim(url('/'), '/'), '/').')?(\/[^"#]*)"/', $this->get($page)->getContent(), $match);
            foreach ($match[1] as $href) {
                $links[html_entity_decode($href)] = true;
            }
        }
        // Sign-out, cache and destructive links, file downloads and static assets are not pages.
        $skip = '/logout|clear|backup|delete|destroy|switch|language|lang\/|currency|toggle|download|export|\.(css|js|png|jpe?g|ico|svg|webp)(\?|$)|_debugbar|^\/(images|css|js|vendor|logo|public)\b/i';
        $failures = [];
        foreach (array_keys($links) as $href) {
            if (preg_match($skip, $href)) continue;
            $this->fresh();
            $response = $this->get($href);
            if ($response->getStatusCode() >= 500) {
                $failures[] = $href.' -> '.($response->exception ? class_basename($response->exception).': '.$response->exception->getMessage() : $response->getStatusCode());
            }
        }
        $this->assertGreaterThan(50, count($links), 'the menu was rendered');
        $this->assertSame([], $failures);
    }

    public function test_a_route_without_a_page_is_not_found_rather_than_a_server_error(): void
    {
        $this->fresh();
        $this->get('/unit/create')->assertNotFound();           // resource route whose screen is a modal
        $catalogue = $this->get('/menu/no-such-catalogue');
        if ($catalogue->status() === 403) {
            // Capability denial intentionally precedes catalogue lookup on installations without this optional module.
            $this->assertInstanceOf(\Illuminate\Auth\Access\AuthorizationException::class, $catalogue->exception);
            $this->assertSame('Capability is disabled: sales.catalogue_qr', $catalogue->exception->getMessage());
        } else {
            $catalogue->assertNotFound();
        }
        $this->get('/purchases/999999999/edit')->assertNotFound();
    }
}
