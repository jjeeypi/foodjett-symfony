<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class UploadStorage
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir)
    {
    }

    /** @param list<string> $allowedMimeTypes */
    public function store(UploadedFile $file, string $directory, array $allowedMimeTypes): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The uploaded file is invalid.');
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Uploads cannot exceed 10 MB.');
        }
        if (!in_array((string) $file->getMimeType(), $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('The uploaded file type is not supported.');
        }
        $extension = $file->guessExtension() ?: 'bin';
        $filename = bin2hex(random_bytes(20)).'.'.$extension;
        $relativeDirectory = 'uploads/'.trim($directory, '/');
        $targetDirectory = $this->projectDir.'/public/'.$relativeDirectory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('The upload directory could not be created.');
        }
        $file->move($targetDirectory, $filename);

        return $relativeDirectory.'/'.$filename;
    }

    public function delete(?string $relativePath): void
    {
        if (null === $relativePath || !str_starts_with($relativePath, 'uploads/')) {
            return;
        }
        $fullPath = $this->projectDir.'/public/'.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
