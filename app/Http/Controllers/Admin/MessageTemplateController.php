<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\MessageTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MessageTemplateController extends Controller
{
    public function index(): View
    {
        $items  = MessageTemplateService::all();
        $groups = [];

        foreach ((array) config('message-templates.groups', []) as $key => $group) {
            $groups[$key] = $group + [
                'items' => array_filter($items, fn ($i) => ($i['group'] ?? null) === $key),
            ];
        }

        return view('message-templates.index', [
            'groups'    => $groups,
            'meta'      => (array) config('message-templates.placeholder_meta', []),
            'templates' => $items,
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $definition = MessageTemplateService::definition($key);

        if (! $definition) {
            abort(404);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Isi pesan tidak boleh kosong.',
            'body.max'      => 'Isi pesan maksimal 4000 karakter.',
        ]);

        $body = str_replace("\r\n", "\n", trim($data['body']));

        if ($body === $definition['default']) {
            MessageTemplateService::reset($key);
        } else {
            MessageTemplateService::save($key, $body, $request->user()?->id);
        }

        ActivityLogService::log('update', "mengubah template pesan: {$definition['label']}", 'message_template', $key);

        return redirect()->route('message-templates.index')
            ->with('success', "Template \"{$definition['label']}\" tersimpan.")
            ->with('open_template', $key);
    }

    public function reset(string $key): RedirectResponse
    {
        $definition = MessageTemplateService::definition($key);

        if (! $definition) {
            abort(404);
        }

        MessageTemplateService::reset($key);

        ActivityLogService::log('update', "mengembalikan template pesan ke bawaan: {$definition['label']}", 'message_template', $key);

        return redirect()->route('message-templates.index')
            ->with('success', "Template \"{$definition['label']}\" kembali ke teks bawaan.")
            ->with('open_template', $key);
    }
}
