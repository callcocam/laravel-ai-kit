<?php

namespace Callcocam\AiKit\Routing;

use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * O tipo de entrada de uma chamada, que escolhe a rota (config ai-kit.routes).
 */
enum Input: string
{
    case Text = 'text';
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';

    /**
     * Pelo que vai anexado: áudio manda sobre documento, que manda sobre imagem.
     * Um PDF que o DocumentPreparer virou imagens é `image`; um PDF que virou
     * texto é `text`.
     *
     * @param  array<mixed>  $attachments
     */
    public static function fromAttachments(array $attachments): self
    {
        $has = fn (string $class): bool => array_filter($attachments, fn ($file): bool => $file instanceof $class) !== [];

        return match (true) {
            $has(Audio::class) => self::Audio,
            $has(Document::class) => self::Document,
            $attachments !== [] => self::Image,
            default => self::Text,
        };
    }

    public function needsVision(): bool
    {
        return $this === self::Image || $this === self::Document;
    }
}
