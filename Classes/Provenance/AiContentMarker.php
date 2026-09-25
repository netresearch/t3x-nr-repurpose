<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Provenance;

use Netresearch\NrRepurpose\Rendering\RenderingException;

/**
 * Embeds the machine-readable AI marker into the bytes of a generated file (ADR-005).
 * Pure byte manipulation, no library: PNG chunks and ID3v2 frames are simple, fixed
 * formats, and the extension has no metadata library as a dependency.
 *
 * Runs at store time (JobFileStorage), after the last re-encode — the GD compositor
 * writes a fresh PNG and would drop any chunk inserted earlier.
 *
 * - PNG: a tEXt "Software" and a tEXt "Comment" chunk plus an iTXt "XML:com.adobe.xmp"
 *   packet carrying Iptc4xmpExt:DigitalSourceType, inserted right after IHDR. A PNG that
 *   already carries a C2PA manifest (caBX chunk) is returned unchanged: any byte change
 *   would break the manifest's signature, and the manifest already declares the origin.
 * - MP3: an existing ID3v2 tag (ffmpeg's stitcher writes an ID3v2.4 tag holding only
 *   its own TSSE) is replaced by an ID3v2.3 tag with TXXX "AI-generated",
 *   TXXX "DigitalSourceType", TSSE and COMM frames.
 *
 * Other file types are returned unchanged; they carry the marker in the artifact
 * metadata and the FAL description only.
 */
final class AiContentMarker
{
    public const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public const XMP_KEYWORD = 'XML:com.adobe.xmp';

    /** TXXX description of the flag frame; value "true". */
    public const ID3_AI_FLAG = 'AI-generated';

    public const ID3_SOURCE_TYPE = 'DigitalSourceType';

    public function mark(string $bytes, string $fileName, AiProvenance $provenance): string
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'png'   => $this->markPng($bytes, $provenance),
            'mp3'   => $this->markMp3($bytes, $provenance),
            default => $bytes,
        };
    }

    public function markPng(string $png, AiProvenance $provenance): string
    {
        // Signature, then IHDR is mandatory as the first chunk: length 13 + "IHDR".
        if (!str_starts_with($png, self::PNG_SIGNATURE) || substr($png, 12, 4) !== 'IHDR') {
            throw RenderingException::because('Cannot AI-label the file: it is not a PNG', 1790000501);
        }

        if ($this->pngHasChunk($png, 'caBX')) {
            return $png;
        }

        $afterIhdr = 8 + 12 + 13;
        $chunks    = $this->pngChunk('tEXt', 'Software' . "\0" . AiProvenance::ascii($provenance->generator))
            . $this->pngChunk('tEXt', 'Comment' . "\0" . $provenance->describe())
            // iTXt: keyword \0, compression flag 0, method 0, language tag \0, translated keyword \0, UTF-8 text.
            . $this->pngChunk('iTXt', self::XMP_KEYWORD . "\0\0\0\0\0" . $this->xmp($provenance));

        return substr($png, 0, $afterIhdr) . $chunks . substr($png, $afterIhdr);
    }

    public function markMp3(string $mp3, AiProvenance $provenance): string
    {
        $audio = $mp3;
        if (str_starts_with($mp3, 'ID3')) {
            if (strlen($mp3) < 10) {
                throw RenderingException::because('Cannot AI-label the file: truncated ID3 header', 1790000502);
            }

            $size = $this->syncsafeDecode(substr($mp3, 6, 4));
            // Footer present flag (ID3v2.4): a 10-byte copy of the header after the frames.
            $footer = (ord($mp3[5]) & 0x10) !== 0 ? 10 : 0;
            $audio  = substr($mp3, 10 + $size + $footer);
        }

        // The remaining bytes must start with an MPEG audio frame sync (11 set bits).
        if (strlen($audio) < 2 || ord($audio[0]) !== 0xFF || (ord($audio[1]) & 0xE0) !== 0xE0) {
            throw RenderingException::because('Cannot AI-label the file: it is not an MP3', 1790000503);
        }

        $frames = $this->id3Frame('TXXX', "\0" . self::ID3_AI_FLAG . "\0" . 'true')
            . $this->id3Frame('TXXX', "\0" . self::ID3_SOURCE_TYPE . "\0" . $provenance->sourceType->value)
            . $this->id3Frame('TSSE', "\0" . AiProvenance::ascii($provenance->generator))
            // COMM: encoding, language, empty short description \0, text.
            . $this->id3Frame('COMM', "\0" . 'eng' . "\0" . $provenance->describe());

        // ID3v2.3 header: "ID3", version 3.0, no flags, syncsafe size of the frames.
        return 'ID3' . "\x03\x00\x00" . $this->syncsafeEncode(strlen($frames)) . $frames . $audio;
    }

    private function xmp(AiProvenance $provenance): string
    {
        $attr = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about=""'
            . ' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
            . ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' Iptc4xmpExt:DigitalSourceType="' . $attr($provenance->sourceType->value) . '"'
            . ' xmp:CreatorTool="' . $attr($provenance->generator) . '">'
            . '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $attr($provenance->describe()) . '</rdf:li></rdf:Alt></dc:description>'
            . '</rdf:Description>'
            . '</rdf:RDF>'
            . '</x:xmpmeta>';
    }

    private function pngHasChunk(string $png, string $type): bool
    {
        $offset = 8;
        $length = strlen($png);
        while ($offset + 8 <= $length) {
            $chunkLength = (ord($png[$offset]) << 24) | (ord($png[$offset + 1]) << 16) | (ord($png[$offset + 2]) << 8) | ord($png[$offset + 3]);
            if (substr($png, $offset + 4, 4) === $type) {
                return true;
            }

            $offset += 12 + $chunkLength;
        }

        return false;
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** ID3v2.3 frame: id, 32-bit big-endian size (not syncsafe in v2.3), two flag bytes. */
    private function id3Frame(string $id, string $data): string
    {
        return $id . pack('N', strlen($data)) . "\x00\x00" . $data;
    }

    private function syncsafeEncode(int $size): string
    {
        return chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
    }

    private function syncsafeDecode(string $bytes): int
    {
        return ((ord($bytes[0]) & 0x7F) << 21) | ((ord($bytes[1]) & 0x7F) << 14) | ((ord($bytes[2]) & 0x7F) << 7) | (ord($bytes[3]) & 0x7F);
    }
}
