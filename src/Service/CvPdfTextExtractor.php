<?php

namespace App\Service;

use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CvPdfTextExtractor
{
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const MAX_LENGTH = 30000;

    public function extract(UploadedFile $file): string
    {
        $size = $file->getSize();

        if ($size === false || $size > self::MAX_SIZE) {
            throw new \RuntimeException('Le fichier ne doit pas dépasser 5 Mo.');
        }

        if ($file->getMimeType() !== 'application/pdf') {
            throw new \RuntimeException('Le fichier doit être un PDF valide.');
        }

        $parser = new Parser();
        $pdf = $parser->parseFile($file->getPathname());
        $text = trim($pdf->getText());

        if ($text === '') {
            throw new \RuntimeException(
                'Aucun texte détecté dans ce PDF. Un PDF scanné nécessite un OCR.'
            );
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        return $text;
    }
}
