<?php

namespace App\Http\Controllers;

use App\Services\Cabme\SupportApi;

class ContentController extends Controller
{
    public function __construct(private readonly SupportApi $support)
    {
    }

    public function privacy()
    {
        // data.privacy_policy holds the HTML authored in the admin's editor.
        return view('pages.legal', [
            'title' => __('Privacy Policy'),
            'body' => $this->contentFrom($this->support->privacyPolicy(), 'privacy_policy'),
        ]);
    }

    public function terms()
    {
        // data.terms holds the HTML authored in the admin's editor.
        return view('pages.legal', [
            'title' => __('Terms & Conditions'),
            'body' => $this->contentFrom($this->support->terms(), 'terms'),
        ]);
    }

    public function faq()
    {
        return view('pages.faq');
    }

    public function contact()
    {
        // CMS pages live at /page/{slug}; this one is the panel's own.
        return view('pages.contact');
    }

    /** Any other page authored under CMS Pages, reached by its slug. */
    public function page(string $slug)
    {
        $result = $this->support->cmsPage($slug);

        abort_if($result->failed(), 404);

        $data = is_array($result->data) ? $result->data : [];

        return view('pages.legal', [
            'title' => $data['cms_name'] ?? __('Page'),
            'body' => (string) ($data['cms_desc'] ?? ''),
        ]);
    }

    /**
     * Pull the HTML body out of a CMS response. The admin nests each page's
     * HTML under its own key — data.privacy_policy for the privacy endpoint,
     * data.terms for the terms endpoint — so the caller names the key it
     * expects; common fallbacks cover any future pages.
     */
    private function contentFrom($result, string $key): string
    {
        if ($result->failed()) {
            return '<p>'.__('This content is currently unavailable.').'</p>';
        }

        $data = $result->data;

        if (is_string($data) && trim($data) !== '') {
            return $data;
        }

        foreach ([$key, 'content', 'description', 'text', 'value'] as $candidate) {
            if (is_array($data) && ! empty($data[$candidate])) {
                return (string) $data[$candidate];
            }
        }

        return '<p>'.__('This content is currently unavailable.').'</p>';
    }
}
