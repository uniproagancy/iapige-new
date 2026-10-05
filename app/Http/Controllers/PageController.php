<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use App\Support\Store;
use Illuminate\View\View;

class PageController extends Controller
{
    public function contact(): View
    {
        return view('pages.info', Store::info() + [
            'view' => 'contact',
            'seo' => $this->seo('ContactPage', __('info.contact'), route('contact')),
        ]);
    }

    public function about(): View
    {
        return view('pages.info', Store::info() + [
            'view' => 'about',
            'seo' => $this->seo('AboutPage', __('info.about'), route('about')),
        ]);
    }

    public function info(string $doc = 'delivery'): View
    {
        $data = Store::info();
        abort_unless(isset($data['docs'][$doc]), 404);

        return view('pages.info', $data + [
            'view' => 'text',
            'doc' => $doc,
            'seo' => $this->seo(
                'WebPage',
                $data['docs'][$doc]['title'],
                route('info', $doc),
                [['name' => __('info.breadcrumb'), 'url' => route('info')]],
            ),
        ]);
    }

    /**
     * The shared part of an info page's structured data.
     *
     * @param  array<int, array{name: string, url: string}>  $parents
     * @return array<string, mixed>
     */
    protected function seo(string $type, string $heading, string $canonical, array $parents = []): array
    {
        return [
            'canonical' => $canonical,
            'schema' => [
                Seo::page($type, $heading, __('info.description')),
                Seo::breadcrumbs([...$parents, ['name' => $heading, 'url' => $canonical]]),
            ],
        ];
    }
}
