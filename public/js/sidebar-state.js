/* Left sidebar behaviour:
 *  - marks the current page (pages may mark their own entry; otherwise the best-matching link wins),
 *  - keeps one top-level group open: the current page's, else the one last opened in this tab,
 *  - keeps the scroll offset across full page loads (per tab, sessionStorage),
 *  - "Search menu…" filters every permitted destination, settings pages included. */
(function ($) {
    'use strict';
    var KEY = 'zolo_sidebar_nav_state';
    var $nav = $('nav.side-navbar'), $menu = $('#side-main-menu');
    if (!$nav.length || !$menu.length) return;

    function read() { try { return JSON.parse(sessionStorage.getItem(KEY)) || {}; } catch (e) { return {}; } }
    function write(state) { try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }

    function topGroups() { return $menu.children('li').children('ul.collapse'); }
    function setOpen($ul, open) {
        $ul.toggleClass('show', open).prev('a').attr('aria-expanded', open ? 'true' : 'false').toggleClass('collapsed', !open);
    }
    function openOnly($keep) {
        topGroups().each(function () { setOpen($(this), $keep.is(this)); });
        $keep.parents('ul.collapse').addBack().each(function () { setOpen($(this), true); });
    }

    function scrollOffset() {
        var $container = $nav.find('.mCSB_container').first();
        return $container.length ? Math.abs($container.position().top) : $nav.scrollTop();
    }
    function save() {
        var open = topGroups().filter('.show').attr('id');
        write({ scroll: scrollOffset(), open: open || null });
    }
    function restoreScroll() {
        var scroll = read().scroll;
        if (!scroll) return;
        if ($nav.find('.mCSB_container').length && $.fn.mCustomScrollbar) {
            $nav.mCustomScrollbar('update');
            $nav.mCustomScrollbar('scrollTo', scroll, { scrollInertia: 0, timeout: 0 });
        } else {
            $nav.scrollTop(scroll);
        }
    }

    // Best link for the current URL: same path (or a sub-path), all its query params present; a matching hash breaks ties.
    function bestLink() {
        var here = window.location, current = here.pathname.replace(/\/+$/, '') || '/', params = new URLSearchParams(here.search);
        var best = null, bestScore = 0;
        $menu.add('#side-search-index').find('a[href]').each(function () {
            var href = this.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
            var url;
            try { url = new URL(this.href); } catch (e) { return; }
            if (url.host !== here.host) return;
            var path = url.pathname.replace(/\/+$/, '') || '/';
            if (current !== path && current.indexOf(path === '/' ? '/' : path + '/') !== 0) return;
            var matched = 0, ok = true;
            url.searchParams.forEach(function (value, key) { if (params.get(key) === value) matched++; else ok = false; });
            if (!ok) return;
            var score = path.length * 10 + (current === path ? 5 : 0) + matched + (url.hash && url.hash === here.hash ? 1 : 0);
            if (score > bestScore) { best = this; bestScore = score; }
        });
        return best;
    }

    function settle() {
        var marked = $menu.find('li.active').not('.sidebar-heading');
        if (!marked.length) {
            var best = bestLink(), owner = best && best.getAttribute('data-nav-owner');
            if (owner) marked = $('#' + owner).addClass('active');
            else if (best) marked = $(best).closest('li').addClass('active');
        }
        // Page scripts may still open the group an entry used to sit in; open only the one around the current page.
        var $group = marked.length ? marked.first().parents('ul.collapse').last() : topGroups().filter('#' + (read().open || '-'));
        openOnly($group);
    }

    // One top-level group open at a time.
    $menu.on('show.bs.collapse', function (e) {
        var $ul = $(e.target);
        if (!$ul.parent().parent().is($menu)) return;
        topGroups().filter('.show').not($ul).each(function () { setOpen($(this), false); });
    });
    $nav.on('click', 'a', save);
    $menu.on('shown.bs.collapse hidden.bs.collapse', save);
    $(window).on('pagehide beforeunload', save);
    $(function () { setTimeout(function () { settle(); restoreScroll(); }, 0); });
    $(window).on('load', restoreScroll);

    // ---- Menu search -------------------------------------------------------------------------------
    var $search = $('#side-menu-search'), $results = $('#side-search-results');
    if (!$search.length) return;

    function groupOf(link) {
        var $link = $(link), $top = $link.parents('ul.collapse').last();
        if ($link.attr('data-group')) return $link.attr('data-group');
        if ($top.length) return $.trim($top.prev('a').find('span').first().text());
        return $.trim($link.closest('li').prevAll('.sidebar-heading').first().text());
    }
    var entries = null;
    function index() {
        if (entries) return entries;
        entries = [];
        $menu.add('#side-search-index').find('a[href]').each(function () {
            var href = this.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
            var label = $.trim($(this).text()), group = groupOf(this);
            entries.push({ href: this.href, label: label, group: group,
                text: (label + ' ' + group + ' ' + (this.getAttribute('data-keywords') || '')).toLowerCase() });
        });
        return entries;
    }
    function render(query) {
        var words = query.toLowerCase().split(/\s+/).filter(Boolean);
        $results.empty();
        if (!words.length) { $results.attr('hidden', true); $menu.removeAttr('hidden'); return; }
        var hits = index().filter(function (e) { return words.every(function (w) { return e.text.indexOf(w) !== -1; }); });
        hits.slice(0, 30).forEach(function (e) {
            var $a = $('<a>').attr('href', e.href);
            $a.append($('<span>').text(e.label), $('<small>').text(e.group));
            $results.append($('<li>').append($a));
        });
        if (!hits.length) $results.append($('<li class="side-search-empty">').text('No menu item matches'));
        $results.removeAttr('hidden');
        $menu.attr('hidden', true);
    }
    function resultLinks() { return $results.find('a'); }

    $search.on('input', function () { render(this.value); });
    $search.on('keydown', function (e) {
        var $first = resultLinks().first();
        if (e.key === 'Enter' && $first.length) { e.preventDefault(); window.location.href = $first.attr('href'); }
        else if (e.key === 'ArrowDown' && $first.length) { e.preventDefault(); $first.focus(); }
        else if (e.key === 'Escape') { this.value = ''; render(''); }
    });
    $results.on('keydown', 'a', function (e) {
        var $links = resultLinks(), at = $links.index(this);
        if (e.key === 'ArrowDown') { e.preventDefault(); ($links.eq(at + 1).length ? $links.eq(at + 1) : $(this)).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (at > 0 ? $links.eq(at - 1) : $search).focus(); }
        else if (e.key === 'Escape') { $search.val('').focus(); render(''); }
    });
})(jQuery);
