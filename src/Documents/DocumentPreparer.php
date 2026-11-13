<?php

namespace Callcocam\AiKit\Documents;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * O documento no formato que qualquer provedor lê: o driver openai-compatible
 * (Cloudflare) não aceita PDF, só texto e imagem. PDF com texto (extrato
 * digital) vai como texto, pelo `pdftotext`: exato e mais barato que imagem. PDF
 * escaneado tem as primeiras páginas convertidas em JPEG pelo `pdftoppm`; foto vai
 * como está. Páginas, imagens e texto têm teto (config ai-kit.documents), para um
 * documento longo não esgotar a cota grátis do dia. Precisa de poppler-utils.
 */
class DocumentPreparer
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Um arquivo. Com `text: false` o PDF sempre vira imagens (quem quer que o
     * modelo veja a página, não só o texto).
     *
     * @return array{text: string|null, images: list<Base64Image>}
     */
    public function from(string $contents, string $mime, ?int $maxPages = null, bool $text = true): array
    {
        if (in_array($mime, self::IMAGE_MIMES, true)) {
            return ['text' => null, 'images' => [Image::fromBase64(base64_encode($contents), $mime)]];
        }

        if (str_starts_with($mime, 'text/')) {
            return ['text' => mb_substr($contents, 0, (int) config('ai-kit.documents.max_text_chars', 60_000)), 'images' => []];
        }

        if ($mime !== 'application/pdf') {
            return ['text' => null, 'images' => []];
        }

        $dir = storage_path('app/private/tmp/ai-doc-'.Str::ulid());
        File::ensureDirectoryExists($dir);

        try {
            $pdf = $dir.'/document.pdf';
            file_put_contents($pdf, $contents);

            $extracted = $text ? $this->textOf($pdf) : null;

            if ($extracted !== null) {
                return ['text' => $extracted, 'images' => []];
            }

            return ['text' => null, 'images' => $this->pagesOf($pdf, $dir, $maxPages ?? (int) config('ai-kit.documents.max_pages', 5))];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Vários arquivos de uma chamada, com teto de imagens somando todos
     * (`documents.max_images`). Cada item é `id => ['mime' => …, 'contents' => …]`,
     * com o conteúdo como texto ou uma Closure que o lê (null = arquivo sumiu). O
     * arquivo que não coube ou não deu para ler fica em `skipped`.
     *
     * @param  iterable<string|int, array{mime: string, contents: string|Closure(): (string|null)|null}>  $files
     * @return array{text: string|null, images: list<Base64Image>, used: list<string|int>, skipped: list<string|int>}
     */
    public function fromMany(iterable $files, ?int $maxPagesPerFile = null, bool $text = true): array
    {
        $maxImages = (int) config('ai-kit.documents.max_images', 8);
        $texts = [];
        $images = [];
        $used = [];
        $skipped = [];

        foreach ($files as $id => $file) {
            $room = $maxImages - count($images);
            $contents = $file['contents'] instanceof Closure ? ($file['contents'])() : $file['contents'];

            if ($room <= 0 || $contents === null) {
                $skipped[] = $id;

                continue;
            }

            $document = $this->from($contents, $file['mime'], min($maxPagesPerFile ?? PHP_INT_MAX, $room), $text);

            if ($document['text'] === null && $document['images'] === []) {
                $skipped[] = $id;

                continue;
            }

            if ($document['text'] !== null) {
                $texts[] = $document['text'];
            }

            array_push($images, ...array_slice($document['images'], 0, $room));
            $used[] = $id;
        }

        $joined = $texts === [] ? null : mb_substr(implode("\n\n", $texts), 0, (int) config('ai-kit.documents.max_text_chars', 60_000));

        return ['text' => $joined, 'images' => $images, 'used' => $used, 'skipped' => $skipped];
    }

    /**
     * O servidor tem o poppler-utils (pdftotext e pdftoppm).
     */
    public static function available(): bool
    {
        $finder = new ExecutableFinder;

        return $finder->find('pdftotext') !== null && $finder->find('pdftoppm') !== null;
    }

    private function textOf(string $pdf): ?string
    {
        $process = $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $pdf, '-']);

        if ($process === null) {
            return null;
        }

        $text = trim(preg_replace('/[ \t]+$/m', '', $process->getOutput()) ?? '');

        if (mb_strlen(preg_replace('/\s+/', '', $text) ?? '') < (int) config('ai-kit.documents.min_text_chars', 80)) {
            return null;
        }

        return mb_substr($text, 0, (int) config('ai-kit.documents.max_text_chars', 60_000));
    }

    /**
     * @return list<Base64Image>
     */
    private function pagesOf(string $pdf, string $dir, int $maxPages): array
    {
        if ($maxPages < 1) {
            return [];
        }

        $dpi = (string) config('ai-kit.documents.dpi', 110);

        if ($this->run(['pdftoppm', '-jpeg', '-r', $dpi, '-f', '1', '-l', (string) $maxPages, $pdf, $dir.'/page']) === null) {
            return [];
        }

        $files = glob($dir.'/page*.jpg') ?: [];
        sort($files);

        return array_map(fn (string $file): Base64Image => Image::fromBase64(base64_encode((string) file_get_contents($file)), 'image/jpeg'), $files);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ?Process
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()) {
            return $process;
        }

        // 127: o comando não existe. Sem o poppler todo PDF sai vazio: avisar quem opera.
        if ($process->getExitCode() === 127) {
            Log::warning('AI documents: poppler-utils is missing ('.$command[0].' not found); PDFs can not be read.');
        }

        return null;
    }
}
