<?php

use Callcocam\AiKit\Documents\DocumentPreparer;
use Laravel\Ai\Files\Base64Image;

beforeEach(function () {
    if (! DocumentPreparer::available()) {
        $this->markTestSkipped('poppler-utils (pdftotext/pdftoppm) não está instalado.');
    }
});

function docFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../fixtures/docs/'.$name);
}

it('reads a digital PDF as text', function () {
    $document = app(DocumentPreparer::class)->from(docFixture('extrato-texto.pdf'), 'application/pdf');

    expect($document['images'])->toBe([])
        ->and($document['text'])->toContain('MERCADO BOM PRECO')->toContain('-234,56');
});

it('caps the text', function () {
    config(['ai-kit.documents.max_text_chars' => 30, 'ai-kit.documents.min_text_chars' => 10]);

    expect(mb_strlen(app(DocumentPreparer::class)->from(docFixture('extrato-texto.pdf'), 'application/pdf')['text']))->toBe(30);
});

it('turns a scanned PDF into JPEG pages, up to the cap', function () {
    $preparer = app(DocumentPreparer::class);
    $document = $preparer->from(docFixture('comprovante-escaneado.pdf'), 'application/pdf');

    expect($document['text'])->toBeNull()
        ->and($document['images'])->toHaveCount(2)
        ->and($document['images'][0])->toBeInstanceOf(Base64Image::class)
        ->and($document['images'][0]->mimeType())->toBe('image/jpeg')
        ->and(substr(base64_decode($document['images'][0]->base64), 0, 3))->toBe("\xFF\xD8\xFF");

    expect($preparer->from(docFixture('comprovante-escaneado.pdf'), 'application/pdf', maxPages: 1)['images'])->toHaveCount(1);

    config(['ai-kit.documents.max_pages' => 1]);
    expect($preparer->from(docFixture('comprovante-escaneado.pdf'), 'application/pdf')['images'])->toHaveCount(1);
});

it('sends the pages of a text PDF as images when asked', function () {
    $document = app(DocumentPreparer::class)->from(docFixture('extrato-texto.pdf'), 'application/pdf', text: false);

    expect($document['text'])->toBeNull()->and($document['images'])->toHaveCount(1);
});

it('sends a photo as it is', function () {
    $photo = docFixture('comprovante-foto.jpg');
    $document = app(DocumentPreparer::class)->from($photo, 'image/jpeg');

    expect($document['text'])->toBeNull()
        ->and($document['images'])->toHaveCount(1)
        ->and(base64_decode($document['images'][0]->base64))->toBe($photo);
});

it('ignores what it can not read', function () {
    expect(app(DocumentPreparer::class)->from('PK...', 'application/zip'))->toBe(['text' => null, 'images' => []])
        ->and(app(DocumentPreparer::class)->from('not a pdf', 'application/pdf'))->toBe(['text' => null, 'images' => []]);
});

it('caps the images of several files and says which were used', function () {
    config(['ai-kit.documents.max_images' => 3]);

    $result = app(DocumentPreparer::class)->fromMany([
        'a' => ['mime' => 'application/pdf', 'contents' => docFixture('comprovante-escaneado.pdf')],
        'missing' => ['mime' => 'image/jpeg', 'contents' => fn () => null],
        'b' => ['mime' => 'image/jpeg', 'contents' => fn () => docFixture('comprovante-foto.jpg')],
        'c' => ['mime' => 'application/pdf', 'contents' => docFixture('comprovante-escaneado.pdf')],
        'd' => ['mime' => 'application/pdf', 'contents' => docFixture('extrato-texto.pdf')],
    ], maxPagesPerFile: 3, text: false);

    expect($result['images'])->toHaveCount(3)
        ->and($result['used'])->toBe(['a', 'b'])
        ->and($result['skipped'])->toBe(['missing', 'c', 'd'])
        ->and($result['text'])->toBeNull();
});

it('joins the text of several digital PDFs', function () {
    $result = app(DocumentPreparer::class)->fromMany([
        1 => ['mime' => 'application/pdf', 'contents' => docFixture('extrato-texto.pdf')],
        2 => ['mime' => 'image/jpeg', 'contents' => docFixture('comprovante-foto.jpg')],
    ]);

    expect($result['text'])->toContain('Saldo final')
        ->and($result['images'])->toHaveCount(1)
        ->and($result['used'])->toBe([1, 2]);
});
