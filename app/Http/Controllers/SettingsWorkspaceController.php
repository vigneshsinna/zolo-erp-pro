<?php

namespace App\Http\Controllers;

use App\Services\Platform\{NavigationAccess, SettingsCatalog};

/** Settings: one workspace listing every settings page the user may open, grouped by area. */
class SettingsWorkspaceController extends Controller
{
    public function index()
    {
        return view('backend.settings.workspace', ['sections' => SettingsCatalog::sections(new NavigationAccess)]);
    }
}
