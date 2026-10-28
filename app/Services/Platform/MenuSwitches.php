<?php

namespace App\Services\Platform;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sections and screens the developer switched off for a company (HRM, Sale Agents, a Settings page ...).
 *
 * The catalog is the rendered sidebar itself: a section is a collapsible group (`<a href="#hrm">`), a screen is a
 * link inside it (or a Settings page from the menu-search index). Switching one off stores the paths of its links, so
 * the sidebar hides it and every request under those paths is refused for everyone in the company except developers.
 */
class MenuSwitches
{
    /** Paths a switch may never block: signing in, picking a company, the dashboard and the developer console. */
    private const NEVER = ['/', '/login', '/logout', '/dashboard', '/select-company', '/developer', '/settings'];
    /** Everything under these stays open whatever is switched off (own profile and password, developer console). */
    private const NEVER_UNDER = ['/user/profile', '/user/update_profile', '/user/changepass', '/developer'];

    private array $offByCompany = [];
    /** Schema answers do not change while the app runs; asked once instead of once per menu link. */
    private static array $schema = [];

    private static function ready(string $what): bool
    {
        return self::$schema[$what] ??= $what === 'column' ? Schema::hasColumn('users', 'is_developer') : Schema::hasTable('company_menu_switches');
    }

    public static function isDeveloper($user = null): bool
    {
        $user ??= Auth::user();
        return $user && (bool) ($user->is_developer ?? false) && self::ready('column');
    }

    public function companyId(): ?int
    {
        $context = request()->attributes->get(CompanyContext::class);
        return $context?->companyId;
    }

    /** menu_key => ['label' => ..., 'paths' => [...]] switched off for the company. */
    public function off(?int $companyId = null): array
    {
        $companyId ??= $this->companyId();
        if (!$companyId || !self::ready('table')) return [];
        return $this->offByCompany[$companyId] ??= DB::table('company_menu_switches')->where('company_id', $companyId)->get()
            ->mapWithKeys(fn ($row) => [$row->menu_key => ['label' => $row->label, 'paths' => json_decode($row->paths, true) ?: []]])->all();
    }

    /** Switches apply to the signed-in user (developers see and open everything so they can manage it). */
    private function applies(): bool
    {
        return Auth::check() && !self::isDeveloper() && $this->off() !== [];
    }

    public function allowsPath(string $path, array $query = []): bool
    {
        if (!$this->applies()) return true;
        $path = self::normalise($path);
        foreach (self::NEVER_UNDER as $open) {
            if ($path === $open || str_starts_with($path, $open.'/')) return true;
        }
        foreach ($this->off() as $switch) {
            foreach ($switch['paths'] as $blocked) {
                if (self::matches($blocked, $path, $query)) return false;
            }
        }
        return true;
    }

    public function allowsUrl(string $url): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return $this->allowsPath((string) parse_url($url, PHP_URL_PATH), $query);
    }

    public function routeAllowed(string $name): bool
    {
        return !\Illuminate\Support\Facades\Route::has($name) || $this->allowsUrl(route($name));
    }

    public function allowsRequest(Request $request): bool
    {
        return $this->allowsPath('/'.ltrim($request->path(), '/'), $request->query());
    }

    /** The label of the switch that blocks this request, for the refusal message. */
    public function blockedBy(Request $request): ?string
    {
        $path = self::normalise('/'.ltrim($request->path(), '/'));
        foreach ($this->off() as $switch) {
            foreach ($switch['paths'] as $blocked) {
                if (self::matches($blocked, $path, $request->query())) return $switch['label'];
            }
        }
        return null;
    }

    /** Remove switched-off sections and screens from the rendered sidebar (no-op when nothing is off). */
    public function filterSidebar(string $html): string
    {
        if (!$this->applies()) return $html;
        $off = $this->off();
        [$dom, $root] = self::load($html);
        $xpath = new DOMXPath($dom);
        $remove = [];
        foreach ($xpath->query('.//a[@href]', $root) as $link) {
            /** @var DOMElement $link */
            $key = self::keyOf($link);
            $item = self::closest($link, 'li');
            if (!$item) continue;
            if ($key && isset($off[$key])) { $remove[] = $item; continue; }
            $href = $link->getAttribute('href');
            if ($href !== '' && $href[0] !== '#' && !$this->allowsUrl($href)) $remove[] = $item;
        }
        foreach ($remove as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
        // A section whose screens are all gone, and a heading left with nothing under it, go too.
        foreach (iterator_to_array($xpath->query('.//li[a[starts-with(@href, "#")]]', $root)) as $section) {
            $list = $xpath->query('./ul', $section)->item(0);
            if ($list && $xpath->query('.//li', $list)->length === 0) $section->parentNode->removeChild($section);
        }
        foreach (iterator_to_array($xpath->query('.//li[contains(concat(" ", @class, " "), " sidebar-heading ")]', $root)) as $heading) {
            $next = $heading->nextSibling;
            while ($next && !($next instanceof DOMElement)) $next = $next->nextSibling;
            if (!$next || str_contains(' '.$next->getAttribute('class').' ', ' sidebar-heading ')) $heading->parentNode->removeChild($heading);
        }
        return self::html($dom, $root);
    }

    /**
     * Sections and their screens as the developer sees the sidebar:
     * [['key' => 'section:hrm', 'label' => 'HRM', 'items' => [['key' => 'dept-menu', 'label' => 'Department', 'paths' => [...]]]]].
     */
    public function catalog(string $html): array
    {
        [$dom, $root] = self::load($html);
        $xpath = new DOMXPath($dom);
        $sections = [];
        foreach ($xpath->query('.//li[a[starts-with(@href, "#") and string-length(@href) > 1]]', $root) as $section) {
            $toggle = $xpath->query('./a', $section)->item(0);
            $items = [];
            foreach ($xpath->query('./ul//a[@href]', $section) as $link) {
                $paths = self::pathsOf($link->getAttribute('href'));
                if (!$paths || !($key = self::keyOf($link))) continue;
                $items[$key] ??= ['key' => $key, 'label' => self::text($link), 'paths' => []];
                $items[$key]['paths'] = array_values(array_unique(array_merge($items[$key]['paths'], $paths), SORT_REGULAR));
            }
            if (!$items) continue;
            $sections[] = ['key' => 'section:'.substr($toggle->getAttribute('href'), 1), 'label' => self::text($toggle), 'items' => array_values($items)];
        }
        // Single-link entries (Settings, Booking Calendar ...) and the Settings pages listed for the menu search.
        $settings = [];
        foreach ($xpath->query('.//a[@data-nav-owner="settings-menu"]', $root) as $link) {
            $paths = self::pathsOf($link->getAttribute('href'));
            if (!$paths) continue;
            $group = trim(str_replace('Settings ·', '', $link->getAttribute('data-group')));
            $settings[] = ['key' => 'settings:'.$paths[0]['path'], 'label' => ($group ? $group.' › ' : '').self::text($link), 'paths' => $paths];
        }
        if ($settings) $sections[] = ['key' => 'section:settings', 'label' => 'Settings', 'items' => $settings];
        $singles = [];
        foreach ($xpath->query('.//ul[@id="side-main-menu"]/li[@id]/a[@href and not(starts-with(@href, "#"))]', $root) as $link) {
            $item = self::closest($link, 'li');
            if (!$item || $item->getAttribute('id') === 'settings-menu') continue;
            if ($paths = self::pathsOf($link->getAttribute('href'))) $singles[] = ['key' => $item->getAttribute('id'), 'label' => self::text($link), 'paths' => $paths];
        }
        if ($singles) $sections[] = ['key' => 'section:other', 'label' => 'Other screens', 'items' => $singles];
        return $sections;
    }

    /** Store exactly these switched-off keys for the company, resolved against the catalog the developer saw. */
    public function save(int $companyId, array $offKeys, array $catalog, int $actor): void
    {
        $rows = [];
        foreach ($catalog as $section) {
            $sectionOff = in_array($section['key'], $offKeys, true);
            if ($sectionOff) {
                $rows[$section['key']] = ['label' => $section['label'], 'paths' => array_merge(...array_column($section['items'], 'paths'))];
            }
            foreach ($section['items'] as $item) {
                if (!$sectionOff && in_array($item['key'], $offKeys, true)) {
                    $rows[$item['key']] = ['label' => $section['label'].' › '.$item['label'], 'paths' => $item['paths']];
                }
            }
        }
        DB::transaction(function () use ($companyId, $rows, $actor) {
            DB::table('company_menu_switches')->where('company_id', $companyId)->delete();
            foreach ($rows as $key => $row) {
                DB::table('company_menu_switches')->insert(['company_id' => $companyId, 'menu_key' => $key, 'label' => mb_substr($row['label'], 0, 150),
                    'paths' => json_encode(array_values($row['paths'])), 'switched_off_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        unset($this->offByCompany[$companyId]);
    }

    /** [['path' => '/employees', 'query' => []]] for a sidebar href; nothing for anchors, javascript: and protected paths. */
    public static function pathsOf(string $href): array
    {
        if ($href === '' || $href[0] === '#' || str_starts_with($href, 'javascript')) return [];
        $path = self::normalise((string) parse_url($href, PHP_URL_PATH));
        if (in_array($path, self::NEVER, true)) return [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        return [['path' => $path, 'query' => $query]];
    }

    private static function matches(array $blocked, string $path, array $query): bool
    {
        $base = $blocked['path'] ?? '';
        if ($base === '' || in_array($base, self::NEVER, true)) return false;
        if ($path !== $base && !str_starts_with($path, $base.'/')) return false;
        foreach ($blocked['query'] ?? [] as $name => $value) {
            if ((string) ($query[$name] ?? '') !== (string) $value) return false;
        }
        return true;
    }

    private static function normalise(string $path): string
    {
        $base = rtrim((string) request()->getBasePath(), '/');
        if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
        $path = '/'.trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function keyOf(DOMElement $link): ?string
    {
        if ($link->getAttribute('data-nav-owner') === 'settings-menu') {
            $paths = self::pathsOf($link->getAttribute('href'));
            return $paths ? 'settings:'.$paths[0]['path'] : null;
        }
        $item = self::closest($link, 'li');
        if ($item && $item->getAttribute('id')) return $item->getAttribute('id');
        if (str_starts_with($link->getAttribute('href'), '#') && strlen($link->getAttribute('href')) > 1) return 'section:'.substr($link->getAttribute('href'), 1);
        $paths = self::pathsOf($link->getAttribute('href'));
        return $paths ? 'link:'.$paths[0]['path'].($paths[0]['query'] ? '?'.http_build_query($paths[0]['query']) : '') : null;
    }

    private static function closest(DOMElement $node, string $tag): ?DOMElement
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if ($parent->tagName === $tag) return $parent;
        }
        return null;
    }

    private static function text(DOMElement $node): string
    {
        return trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    /** @return array{DOMDocument, DOMElement} */
    private static function load(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="menu-switch-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return [$dom, $dom->getElementById('menu-switch-root') ?? $dom->documentElement];
    }

    private static function html(DOMDocument $dom, DOMElement $root): string
    {
        $html = '';
        foreach ($root->childNodes as $child) $html .= $dom->saveHTML($child);
        return $html;
    }
}
