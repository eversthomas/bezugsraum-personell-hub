<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Request;
use App\Core\Response;
use App\Items\ItemService;

final class MediaController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly ItemService $items,
    ) {
    }

    /**
     * Foto-Erfassung: legt einen Inbox-Eintrag mit Bild an. Mit gleicher client_uuid wird nichts doppelt gespeichert.
     *
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params = []): Response
    {
        $file = $request->file('photo');
        if ($file === null) {
            return Response::json(['error' => 'Kein Foto empfangen.'], 422);
        }
        if ($file['size'] > ImageProcessor::MAX_BYTES) {
            return Response::json(['error' => 'Das Foto ist zu groß.'], 413);
        }
        $binary = (string) file_get_contents($file['tmp_name']);

        try {
            // Bild zuerst prüfen: ein ungültiges Foto legt keinen Eintrag an.
            (new ImageProcessor())->process($binary);
            $text = trim($request->input('text'));
            $result = $this->items->capture($text === '' ? 'Foto' : $text, 'photo', $request->input('client_uuid') ?: null);
            if (!$result['duplicate']) {
                $this->media->attach($result['item']->id, $binary);
            }
        } catch (ImageException|\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json(
            ['id' => $result['item']->id, 'duplicate' => $result['duplicate']],
            $result['duplicate'] ? 200 : 201,
        );
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $media = $this->media->load((int) $params['id']);
        if ($media === null) {
            return Response::notFound();
        }

        return new Response(200, $media['data'], [
            'Content-Type' => $media['mime'],
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
