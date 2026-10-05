<?php

namespace App\Http\Controllers;

use App\Support\Store;
use Illuminate\View\View;

class PageController extends Controller
{
    public function contact(): View
    {
        return view('pages.info', Store::info() + ['view' => 'contact']);
    }

    public function about(): View
    {
        return view('pages.info', Store::info() + ['view' => 'about']);
    }

    public function info(string $doc = 'delivery'): View
    {
        $data = Store::info();
        abort_unless(isset($data['docs'][$doc]), 404);

        return view('pages.info', $data + ['view' => 'text', 'doc' => $doc]);
    }
}
