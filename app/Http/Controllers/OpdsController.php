<?php

namespace App\Http\Controllers;

use App\Novel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Minimal OPDS 1.2 acquisition feed: every novel with a generated ePub,
 * ready for ereader apps (KOReader, Moon+ Reader, etc.) pointed at /opds.
 */
class OpdsController extends Controller
{
    public function index()
    {
        $novels = Novel::with(['file' => fn($q) => $q->orderBy('id', 'desc')])
            ->whereNotNull('epub_generated')
            ->orderBy('name')
            ->get(['id', 'name', 'author', 'description', 'epub_generated', 'updated_at']);

        $updated = optional($novels->max('epub_generated'))->toAtomString() ?? now()->toAtomString();

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', url('/opds'));
        $xml->writeElement('title', config('app.name', 'Novarr') . ' Library');
        $xml->writeElement('updated', $updated);
        $this->link($xml, 'self', url('/opds'), 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $this->link($xml, 'start', url('/opds'), 'application/atom+xml;profile=opds-catalog;kind=acquisition');

        foreach ($novels as $novel) {
            $xml->startElement('entry');
            $xml->writeElement('id', url("/novels/{$novel->id}"));
            $xml->writeElement('title', $novel->name);
            $xml->writeElement('updated', optional($novel->epub_generated)->toAtomString() ?? $novel->updated_at->toAtomString());

            if ($novel->author) {
                $xml->startElement('author');
                $xml->writeElement('name', $novel->author);
                $xml->endElement();
            }

            $summary = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($novel->description ?? ''))), 500);
            if ($summary !== '') {
                $xml->startElement('summary');
                $xml->writeAttribute('type', 'text');
                $xml->text($summary);
                $xml->endElement();
            }

            $this->link($xml, 'http://opds-spec.org/acquisition', route('novels.download_epub', $novel->id), 'application/epub+zip');
            if ($novel->file) {
                $cover = url(Storage::url($novel->file->file_path));
                $this->link($xml, 'http://opds-spec.org/image', $cover, 'image/jpeg');
                $this->link($xml, 'http://opds-spec.org/image/thumbnail', $cover, 'image/jpeg');
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/atom+xml;profile=opds-catalog;kind=acquisition;charset=utf-8',
        ]);
    }

    private function link(\XMLWriter $xml, string $rel, string $href, string $type): void
    {
        $xml->startElement('link');
        $xml->writeAttribute('rel', $rel);
        $xml->writeAttribute('href', $href);
        $xml->writeAttribute('type', $type);
        $xml->endElement();
    }
}
