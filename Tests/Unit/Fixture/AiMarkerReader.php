<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use DOMDocument;
use DOMXPath;
use UnexpectedValueException;

/**
 * Independent readers for the AI markers, written from the PNG and ID3v2 specifications
 * rather than from AiContentMarker, so a test reads back what a third-party tool would
 * see: every PNG chunk with its CRC checked, every ID3v2.3 frame, and the XMP attribute.
 */
final class AiMarkerReader
{
    /**
     * @return list<array{type: string, data: string, crcValid: bool}>
     */
    public static function pngChunks(string $png): array
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new UnexpectedValueException('not a PNG');
        }

        $chunks = [];
        $offset = 8;
        while ($offset < strlen($png)) {
            $length   = unpack('N', substr($png, $offset, 4))[1];
            $type     = substr($png, $offset + 4, 4);
            $data     = substr($png, $offset + 8, $length);
            $crc      = unpack('N', substr($png, $offset + 8 + $length, 4))[1];
            $chunks[] = ['type' => $type, 'data' => $data, 'crcValid' => $crc === crc32($type . $data)];
            $offset += 12 + $length;
        }

        return $chunks;
    }

    /**
     * tEXt keyword => text.
     *
     * @return array<string, string>
     */
    public static function pngText(string $png): array
    {
        $text = [];
        foreach (self::pngChunks($png) as $chunk) {
            if ($chunk['type'] === 'tEXt') {
                [$keyword, $value] = explode("\0", $chunk['data'], 2);
                $text[$keyword]    = $value;
            }
        }

        return $text;
    }

    /** The UTF-8 text of the iTXt chunk with the XMP keyword, or null. */
    public static function pngXmp(string $png): ?string
    {
        foreach (self::pngChunks($png) as $chunk) {
            if ($chunk['type'] !== 'iTXt') {
                continue;
            }

            [$keyword, $rest] = explode("\0", $chunk['data'], 2);
            if ($keyword !== 'XML:com.adobe.xmp') {
                continue;
            }

            // compression flag, compression method, then language tag \0 and translated keyword \0.
            if ($rest[0] !== "\0") {
                throw new UnexpectedValueException('compressed XMP not expected');
            }

            $parts = explode("\0", substr($rest, 2), 3);

            return $parts[2];
        }

        return null;
    }

    /** Iptc4xmpExt:DigitalSourceType from an XMP packet, parsed as XML. */
    public static function xmpDigitalSourceType(string $xmp): string
    {
        $document = new DOMDocument();
        if (!$document->loadXML($xmp)) {
            throw new UnexpectedValueException('XMP is not well-formed XML');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xpath->registerNamespace('Iptc4xmpExt', 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/');

        return (string) $xpath->evaluate('string(//rdf:Description/@Iptc4xmpExt:DigitalSourceType)');
    }

    /**
     * The ID3v2 tag at the start of an MP3: its major version, its frames and the bytes
     * that follow it (the audio).
     *
     * @return array{version: int, frames: list<array{id: string, data: string}>, audio: string}
     */
    public static function id3(string $mp3): array
    {
        if (!str_starts_with($mp3, 'ID3')) {
            throw new UnexpectedValueException('no ID3v2 tag');
        }

        $version = ord($mp3[3]);
        $size    = (ord($mp3[6]) << 21) | (ord($mp3[7]) << 14) | (ord($mp3[8]) << 7) | ord($mp3[9]);
        $body    = substr($mp3, 10, $size);
        $frames  = [];
        $offset  = 0;
        while ($offset + 10 <= strlen($body) && $body[$offset] !== "\0") {
            $id     = substr($body, $offset, 4);
            $bytes  = substr($body, $offset + 4, 4);
            $length = $version === 4
                ? (ord($bytes[0]) << 21) | (ord($bytes[1]) << 14) | (ord($bytes[2]) << 7) | ord($bytes[3])
                : unpack('N', $bytes)[1];
            $frames[] = ['id' => $id, 'data' => substr($body, $offset + 10, $length)];
            $offset += 10 + $length;
        }

        return ['version' => $version, 'frames' => $frames, 'audio' => substr($mp3, 10 + $size)];
    }

    /**
     * TXXX description => value of an ISO-8859-1 encoded tag.
     *
     * @param list<array{id: string, data: string}> $frames
     *
     * @return array<string, string>
     */
    public static function id3UserText(array $frames): array
    {
        $values = [];
        foreach ($frames as $frame) {
            if ($frame['id'] === 'TXXX') {
                [$description, $value] = explode("\0", substr($frame['data'], 1), 2);
                $values[$description]  = $value;
            }
        }

        return $values;
    }
}
