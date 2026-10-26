/*global $, document, Chart, LINECHART, data, options, window*/
$(document).ready(function () {
    if ($(window).outerWidth() <= 1199) {
        $('nav.side-navbar').addClass('shrink');
    }

    'use strict';

    // ------------------------------------------------------- //
    // full screen button
    // ------------------------------------------------------ //

    function toggleFullscreen(elem) {
        elem = elem || document.documentElement;
        if (!document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
            if (elem.requestFullscreen) {
                elem.requestFullscreen();
            } else if (elem.msRequestFullscreen) {
                elem.msRequestFullscreen();
            } else if (elem.mozRequestFullScreen) {
                elem.mozRequestFullScreen();
            } else if (elem.webkitRequestFullscreen) {
                elem.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
            }
        }
        else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
        }
    }

    if(('#btnFullscreen').length > 0) {
        document.getElementById('btnFullscreen').addEventListener('click', function() {
            toggleFullscreen();
        });
    }

    // Keep dropdown menus inside the screen, not the nearest scrolling card: with the default scrollParent boundary
    // Popper pushed tall selectpicker menus down over their own field.
    if ($.fn.dropdown && $.fn.dropdown.Constructor) {
        $.fn.dropdown.Constructor.Default.boundary = 'viewport';

        // Strip Bootstrap 4's position-static hack on dropdown parents so menus never detach or stretch across the card
        $(document).on('show.bs.dropdown shown.bs.dropdown', function (e) {
            var $parent = $(e.target).parent();
            if ($parent.hasClass('position-static') && !$parent.is('body')) {
                $parent.removeClass('position-static');
            }
        });
    }

    //Custom select - Universal anti-clipping container (exclude DataTables, native selects and debugbars)
    if ($.fn.selectpicker) {
        $.fn.selectpicker.Constructor.DEFAULTS.container = 'body';
        $('select:not(.dataTables_length select):not([name$="_length"]):not(.custom-select-native):not(.phpdebugbar select):not([class*="phpdebugbar"])').selectpicker({
            container: 'body'
        });
    }

    $('[data-toggle="tooltip"]').tooltip();

    // Main Template Color
    var brandPrimary = '#33b35a';

    // ------------------------------------------------------- //
    // Custom Scrollbar
    // ------------------------------------------------------ //

    if ($(window).outerWidth() > 992) {
        $(".transaction-list,.right-sidebar").mCustomScrollbar({
            theme: "light",
            scrollInertia: 200
        });
    }

    // ------------------------------------------------------- //
    // 3-State Side Navbar Controller (Expanded -> Icon-Only -> Full-Hide)
    // ------------------------------------------------------ //
    var SIDEBAR_CYCLE = {
        'expanded': 'icon-only',
        'icon-only': 'full-hide',
        'full-hide': 'expanded'
    };

    function applySidebarState(state) {
        var $navbar = $('nav.side-navbar');
        var $page = $('.page');
        var $btn = $('#toggle-btn');

        if (!state || !SIDEBAR_CYCLE[state]) {
            state = 'expanded';
        }

        $navbar.removeClass('icon-only full-hide shrink');
        $page.removeClass('sidebar-icon-only sidebar-full-hide active active-sm');
        $btn.find('.dot').removeClass('active');

        if (state === 'icon-only') {
            $navbar.addClass('icon-only');
            $page.addClass('sidebar-icon-only');
            $btn.attr('data-state', 'icon-only')
                .attr('title', 'Hide sidebar (2nd tap)')
                .attr('aria-label', 'Hide sidebar');
            $btn.find('.dot-2').addClass('active');
        } else if (state === 'full-hide') {
            $navbar.addClass('full-hide shrink');
            $page.addClass('sidebar-full-hide active');
            $btn.attr('data-state', 'full-hide')
                .attr('title', 'Show full sidebar (3rd tap)')
                .attr('aria-label', 'Show full sidebar');
            $btn.find('.dot-3').addClass('active');
        } else {
            $btn.attr('data-state', 'expanded')
                .attr('title', 'Collapse to icons (1st tap)')
                .attr('aria-label', 'Collapse sidebar to icons');
            $btn.find('.dot-1').addClass('active');
        }

        try {
            localStorage.setItem('zolo_sidebar_state', state);
        } catch (e) {}

        // Notify charts and DataTables to re-measure width
        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 50);
    }

    // Read initial state
    var initialSidebarState = 'expanded';
    try {
        var storedState = localStorage.getItem('zolo_sidebar_state');
        if (storedState && SIDEBAR_CYCLE[storedState]) {
            initialSidebarState = storedState;
        } else if ($(window).outerWidth() < 1200) {
            initialSidebarState = 'full-hide';
        }
    } catch (e) {}

    applySidebarState(initialSidebarState);

    // Toggle button click handler
    $(document).off('click', '#toggle-btn').on('click', '#toggle-btn', function (e) {
        e.preventDefault();
        var current = $(this).attr('data-state') || 'expanded';
        var next = SIDEBAR_CYCLE[current] || 'expanded';
        applySidebarState(next);
    });

    if ($(window).outerWidth() < 1199) {
        if (!$('nav.side-navbar .close').length) {
            $('nav.side-navbar').append('<span class="close"><i class="dripicons-cross"></i></span>');
        }
    }
    $(document).on('click', 'nav.side-navbar .close', function(){
        applySidebarState('full-hide');
    });

    $('.pos-page nav.side-navbar').addClass('shrink');

    // ------------------------------------------------------- //
    // Header Dropdown / Right Sidebar
    // ------------------------------------------------------ //
    $(document).on('click', 'header .dropdown-item', function(){
        $('.right-sidebar.open').removeClass('open');
        $(this).siblings('.right-sidebar').addClass('open');
        $('.page,.pos-page').on('click', function(){
            $('.right-sidebar.open').removeClass('open');
        })
    });


    // ------------------------------------------------------- //
    // Login  form validation
    // ------------------------------------------------------ //
    $('#login-form').validate({
        messages: {
            loginUsername: 'please enter your username',
            loginPassword: 'please enter your password'
        }
    });

    // ------------------------------------------------------- //
    // Register form validation
    // ------------------------------------------------------ //
    $('#register-form').validate({
        messages: {
            registerUsername: 'please enter your first name',
            registerEmail: 'please enter a vaild Email Address',
            registerPassword: 'please enter your password'
        }
    });

    // ------------------------------------------------------- //
    // Jquery Progress Circle
    // ------------------------------------------------------ //
    var progress_circle = $("#progress-circle").gmpc({
        color: brandPrimary,
        line_width: 5,
        percent: 80
    });
    progress_circle.gmpc('animate', 80, 3000);

    // ------------------------------------------------------- //
    // External links to new window
    // ------------------------------------------------------ //

    $('.external').on('click', function (e) {

        e.preventDefault();
        window.open($(this).attr("href"));
    });

    // ------------------------------------------------------ //
    // For demo purposes, can be deleted
    // ------------------------------------------------------ //

    var stylesheet = $('link#theme-stylesheet');
    $( "<link id='new-stylesheet' rel='stylesheet'>" ).insertAfter(stylesheet);
    var alternateColour = $('link#new-stylesheet');

    if ($.cookie("theme_csspath")) {
        alternateColour.attr("href", $.cookie("theme_csspath"));
    }

    $('.periods li').on('click', function(){
        $('.decade-select').addClass('hidden');
        $('.month-select').removeClass('hidden');
        $('.year-select').removeClass('hidden');
    });

    $('.periods li:nth-child(5)').on('click', function(){
        $('.decade-select').removeClass('hidden');
        $('.month-select').addClass('hidden');
        $('.year-select').addClass('hidden');
    });

    $('.periods li:nth-child(3), .periods li:nth-child(4)').on('click', function(){
        $('.decade-select').addClass('hidden');
        $('.month-select').addClass('hidden');
        $('.year-select').removeClass('hidden');
    });

});
