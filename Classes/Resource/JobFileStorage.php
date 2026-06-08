<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Resource;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Stores generated artifact bytes into the default FAL storage under a `repurpose/` folder
 * and returns the resulting sys_file (File). Artifacts reference it by sys_file uid.
 */
class JobFileStorage
{
    private const SUBFOLDER = 'repurpose';

    public function __construct(private readonly StorageRepository $storageRepository) {}

    public function store(string $content, string $fileName): File
    {
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            throw new \RuntimeException('No default FAL storage available', 1749379300);
        }

        $folder = $storage->hasFolder(self::SUBFOLDER)
            ? $storage->getFolder(self::SUBFOLDER)
            : $storage->createFolder(self::SUBFOLDER);

        // Unique target name to avoid collisions across runs.
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $unique = pathinfo($fileName, PATHINFO_FILENAME)
            . '-' . bin2hex(random_bytes(4))
            . ($extension !== '' ? '.' . $extension : '');

        $file = $storage->createFile($unique, $folder);
        $file->setContents($content);

        return $file;
    }
}
