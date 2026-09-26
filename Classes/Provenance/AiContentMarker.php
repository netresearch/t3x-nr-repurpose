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
 * Pure byte manipulation, no library: PNG chunks, ID3v2 frames and WebVTT NOTE blocks
 * are simple, fixed formats, and the extension has no metadata library as a dependency.
 *
 * Runs at store time (JobFileStorage), after the last re-encode — the GD compositor
 * writes a fresh PNG and would drop any chunk inserted earlier.
 *
 * - PNG: a tEXt "Software" and a tEXt "Comment" chunk plus an iTXt "XML:com.adobe.xmp"
 *   packet carrying Iptc4xmpExt:DigitalSourceType, inserted right after IHDR. The PNG
 *   must be complete (IHDR first, IEND last and exactly at the end). A PNG that already
 *   carries a C2PA manifest (caBX chunk) is returned unchanged: any byte change would
 *   break the C2PA content hash binding, so the manifest would fail validation.
 * - MP3: an existing ID3v2 tag (ffmpeg's stitcher writes an ID3v2.4 tag holding only
 *   its own TSSE) is replaced by an ID3v2.3 tag with TXXX "AI-generated",
 *   TXXX "DigitalSourceType" and COMM frames. The encoder's TSSE is carried over when
 *   the replaced tag had one; this extension does not encode audio, so it never
 *   writes a TSSE of its own.
 * - WebVTT: a NOTE block with the AI origin right after the header.
 * - PDF: an incremental update appended to the file: a new document information
 *   dictionary (the old entries plus Subject, Keywords, AIGenerated and
 *   DigitalSourceType), the XMP packet as the catalog's /Metadata stream, and a
 *   cross-reference section for the three objects. The original bytes stay as they are.
 *   Only a complete, unencrypted PDF with a classic cross-reference table is accepted,
 *   which is what Chromium writes; anything else is refused.
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
            'vtt'   => $this->markVtt($bytes, $provenance),
            'pdf'   => $this->markPdf($bytes, $provenance),
            default => $bytes,
        };
    }

    public function markPng(string $png, AiProvenance $provenance): string
    {
        $types = $this->pngChunkTypes($png);
        if ($types === null) {
            throw RenderingException::because('Cannot AI-label the file: it is not a complete PNG', 1790000501);
        }

        if (in_array('caBX', $types, true)) {
            return $png;
        }

        $afterIhdr = 8 + 12 + 13;
        $chunks    = $this->pngChunk('tEXt', 'Software' . "\0" . AiProvenance::ascii($provenance->generator))
            . $this->pngChunk('tEXt', 'Comment' . "\0" . $provenance->describeAscii())
            // iTXt: keyword \0, compression flag 0, method 0, language tag \0, translated keyword \0, UTF-8 text.
            . $this->pngChunk('iTXt', self::XMP_KEYWORD . "\0\0\0\0\0" . $this->xmp($provenance));

        return substr($png, 0, $afterIhdr) . $chunks . substr($png, $afterIhdr);
    }

    public function markMp3(string $mp3, AiProvenance $provenance): string
    {
        $audio   = $mp3;
        $encoder = null;
        if (str_starts_with($mp3, 'ID3')) {
            if (strlen($mp3) < 10) {
                throw RenderingException::because('Cannot AI-label the file: truncated ID3 header', 1790000502);
            }

            $size = $this->syncsafeDecode(substr($mp3, 6, 4));
            // Footer present flag (ID3v2.4): a 10-byte copy of the header after the frames.
            $footer  = (ord($mp3[5]) & 0x10) !== 0 ? 10 : 0;
            $audio   = substr($mp3, 10 + $size + $footer);
            $encoder = $this->id3Tsse($mp3, $size);
        }

        // The remaining bytes must start with an MPEG audio frame sync (11 set bits).
        if (strlen($audio) < 2 || ord($audio[0]) !== 0xFF || (ord($audio[1]) & 0xE0) !== 0xE0) {
            throw RenderingException::because('Cannot AI-label the file: it is not an MP3', 1790000503);
        }

        $frames = $this->id3Frame('TXXX', "\0" . self::ID3_AI_FLAG . "\0" . 'true')
            . $this->id3Frame('TXXX', "\0" . self::ID3_SOURCE_TYPE . "\0" . $provenance->sourceType->value)
            . ($encoder !== null ? $this->id3Frame('TSSE', "\0" . $encoder) : '')
            // COMM: encoding, language, empty short description \0, text.
            . $this->id3Frame('COMM', "\0" . 'eng' . "\0" . $provenance->describeAscii());

        // ID3v2.3 header: "ID3", version 3.0, no flags, syncsafe size of the frames.
        return 'ID3' . "\x03\x00\x00" . $this->syncsafeEncode(strlen($frames)) . $frames . $audio;
    }

    /**
     * A WebVTT NOTE block (a comment block, ignored by players) after the header block.
     * "-->" is not allowed inside a NOTE, so it cannot end up there.
     */
    public function markVtt(string $vtt, AiProvenance $provenance): string
    {
        $body = str_starts_with($vtt, "\u{FEFF}") ? substr($vtt, 3) : $vtt;
        if (preg_match('/^WEBVTT(?:[ \t][^\r\n]*)?(?:\r\n|\r|\n|$)/', $body) !== 1) {
            throw RenderingException::because('Cannot AI-label the file: it is not WebVTT', 1790000504);
        }

        $note = 'NOTE ' . str_replace('-->', '->', $provenance->describe());
        if (preg_match('/\r?\n\r?\n/', $vtt, $match, PREG_OFFSET_CAPTURE) !== 1) {
            // Header only, no cue yet.
            return rtrim($vtt, "\r\n") . "\n\n" . $note . "\n";
        }

        $headerEnd = $match[0][1] + strlen($match[0][0]);

        return substr($vtt, 0, $headerEnd) . $note . "\n\n" . substr($vtt, $headerEnd);
    }

    public function markPdf(string $pdf, AiProvenance $provenance): string
    {
        if (!str_starts_with($pdf, '%PDF-1.')
            || preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $match) !== 1
        ) {
            throw RenderingException::because('Cannot AI-label the file: it is not a complete PDF', 1790000505);
        }

        $previousXref = (int) $match[1];
        if (substr($pdf, $previousXref, 4) !== 'xref') {
            throw RenderingException::because('Cannot AI-label the file: the PDF has no classic cross-reference table', 1790000506);
        }

        $trailerAt = strpos($pdf, 'trailer', $previousXref);
        $trailer   = $trailerAt === false ? null : $this->pdfDictionary($pdf, $trailerAt);
        if ($trailer === null
            || str_contains($trailer, '/Encrypt')
            || preg_match('/\/Size\s+(\d+)/', $trailer, $size) !== 1
            || preg_match('/\/Root\s+(\d+)\s+0\s+R/', $trailer, $root) !== 1
        ) {
            throw RenderingException::because('Cannot AI-label the file: the PDF trailer is not usable', 1790000507);
        }

        $catalog = $this->pdfObjectDictionary($pdf, (int) $root[1]);
        if ($catalog === null) {
            throw RenderingException::because('Cannot AI-label the file: the PDF catalog is missing', 1790000508);
        }

        $info = preg_match('/\/Info\s+(\d+)\s+0\s+R/', $trailer, $infoRef) === 1
            ? ($this->pdfObjectDictionary($pdf, (int) $infoRef[1]) ?? '<<>>')
            : '<<>>';

        $infoNumber = (int) $size[1];
        $xmpNumber  = $infoNumber + 1;
        $xmp        = '<?xpacket begin="' . "\u{FEFF}" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . $this->xmp($provenance) . '<?xpacket end="r"?>';

        // Keep every entry the document had, add the AI entries it does not have yet.
        $entries = [
            'Subject'           => $this->pdfString($provenance->describeAscii()),
            'Keywords'          => $this->pdfString('AI-generated; ' . $provenance->sourceType->value),
            'AIGenerated'       => '(true)',
            'DigitalSourceType' => $this->pdfString($provenance->sourceType->value),
        ];
        $added = '';
        foreach ($entries as $key => $value) {
            if (preg_match('/\/' . $key . '(?![A-Za-z0-9])/', $info) !== 1) {
                $added .= ' /' . $key . ' ' . $value;
            }
        }

        $body    = str_ends_with($pdf, "\n") ? '' : "\n";
        $offsets = [];

        $offsets[(int) $root[1]] = strlen($pdf) + strlen($body);
        $body .= $root[1] . " 0 obj\n"
            . substr(preg_replace('/\/Metadata\s+\d+\s+\d+\s+R/', '', $catalog) ?? $catalog, 0, -2)
            . ' /Metadata ' . $xmpNumber . " 0 R >>\nendobj\n";

        $offsets[$infoNumber] = strlen($pdf) + strlen($body);
        $body .= $infoNumber . " 0 obj\n" . substr($info, 0, -2) . $added . " >>\nendobj\n";

        $offsets[$xmpNumber] = strlen($pdf) + strlen($body);
        $body .= $xmpNumber . " 0 obj\n<< /Type /Metadata /Subtype /XML /Length " . strlen($xmp) . " >>\nstream\n"
            . $xmp . "\nendstream\nendobj\n";

        $xrefAt = strlen($pdf) + strlen($body);
        ksort($offsets);
        $xref = "xref\n";
        foreach ($offsets as $number => $offset) {
            // One subsection per object: the numbers are not contiguous in general.
            $xref .= $number . " 1\n" . sprintf("%010d 00000 n\r\n", $offset);
        }

        $id = preg_match('/\/ID\s*\[[^\]]*\]/', $trailer, $idMatch) === 1 ? ' ' . $idMatch[0] : '';

        return $pdf . $body . $xref
            . sprintf(
                "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R /Prev %d%s >>\nstartxref\n%d\n%%%%EOF\n",
                $xmpNumber + 1,
                (int) $root[1],
                $infoNumber,
                $previousXref,
                $id,
                $xrefAt,
            );
    }

    /**
     * The dictionary ("<< … >>", nesting included) of the last definition of object
     * $number, or null. The last definition wins, as in an incrementally updated file.
     */
    private function pdfObjectDictionary(string $pdf, int $number): ?string
    {
        if (preg_match_all('/(?:^|[\r\n])' . $number . '\s+0\s+obj\b/', $pdf, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $last = $matches[0][count($matches[0]) - 1] ?? null;

        return $last === null ? null : $this->pdfDictionary($pdf, $last[1]);
    }

    /**
     * The first dictionary at or after $offset with its nested dictionaries, or null.
     * Literal strings are skipped, so a "<<" inside "( … )" does not count.
     */
    private function pdfDictionary(string $pdf, int $offset): ?string
    {
        $start = strpos($pdf, '<<', $offset);
        if ($start === false) {
            return null;
        }

        $depth  = 0;
        $length = strlen($pdf);
        for ($i = $start; $i < $length - 1; ++$i) {
            $char = $pdf[$i];
            if ($char === '(') {
                $i = $this->pdfSkipString($pdf, $i);
                continue;
            }

            if ($char === '<' && $pdf[$i + 1] === '<') {
                ++$depth;
                ++$i;
                continue;
            }

            if ($char === '>' && $pdf[$i + 1] === '>') {
                --$depth;
                ++$i;
                if ($depth === 0) {
                    return substr($pdf, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /** The offset of the ")" that closes the literal string opening at $offset. */
    private function pdfSkipString(string $pdf, int $offset): int
    {
        $depth  = 0;
        $length = strlen($pdf);
        for ($i = $offset; $i < $length; ++$i) {
            $char = $pdf[$i];
            if ($char === '\\') {
                ++$i;
                continue;
            }

            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    /** A PDF literal string of printable ASCII, backslash and parentheses escaped. */
    private function pdfString(string $ascii): string
    {
        return '(' . strtr(AiProvenance::ascii($ascii), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
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

    /**
     * The chunk types of a complete PNG, in order, or null when the bytes are not one:
     * signature, IHDR first with length 13, every chunk inside the data, and IEND as the
     * last chunk ending exactly at the end of the bytes. A truncated render is refused
     * rather than labelled and stored.
     *
     * @return list<string>|null
     */
    private function pngChunkTypes(string $png): ?array
    {
        $length = strlen($png);
        if (!str_starts_with($png, self::PNG_SIGNATURE) || $length < 8 + 12 + 13 || substr($png, 8, 8) !== "\x00\x00\x00\x0DIHDR") {
            return null;
        }

        $types  = [];
        $offset = 8;
        while ($offset + 12 <= $length) {
            $chunkLength = $this->uint32(substr($png, $offset, 4));
            $type        = substr($png, $offset + 4, 4);
            $next        = $offset + 12 + $chunkLength;
            $types[]     = $type;
            if ($type === 'IEND') {
                return $next === $length ? $types : null;
            }

            $offset = $next;
        }

        return null;
    }

    /**
     * The encoder named by the TSSE frame of an ID3v2.3/v2.4 tag, as ASCII; null when the
     * tag has none or a layout this reader does not walk: v2.2 (three-letter frame ids)
     * and an unsynchronised tag (header flag 0x80), whose frame bytes may carry inserted
     * zero bytes. An extended header (flag 0x40) is skipped: in v2.3 its size field
     * excludes itself, in v2.4 it is syncsafe and includes itself.
     */
    private function id3Tsse(string $mp3, int $tagSize): ?string
    {
        $version = ord($mp3[3]);
        $flags   = ord($mp3[5]);
        if (!in_array($version, [3, 4], true) || ($flags & 0x80) !== 0) {
            return null;
        }

        $body   = substr($mp3, 10, $tagSize);
        $offset = 0;
        if (($flags & 0x40) !== 0 && strlen($body) >= 4) {
            $offset = $version === 4 ? $this->syncsafeDecode(substr($body, 0, 4)) : 4 + $this->uint32(substr($body, 0, 4));
        }

        while ($offset + 10 <= strlen($body) && $body[$offset] !== "\0") {
            $id   = substr($body, $offset, 4);
            $size = $version === 4 ? $this->syncsafeDecode(substr($body, $offset + 4, 4)) : $this->uint32(substr($body, $offset + 4, 4));
            $data = substr($body, $offset + 10, $size);
            $offset += 10 + $size;
            if ($id !== 'TSSE' || $data === '') {
                continue;
            }

            $text = match (ord($data[0])) {
                1       => mb_convert_encoding(substr($data, 1), 'UTF-8', 'UTF-16'),
                2       => mb_convert_encoding(substr($data, 1), 'UTF-8', 'UTF-16BE'),
                3       => substr($data, 1),
                default => mb_convert_encoding(substr($data, 1), 'UTF-8', 'ISO-8859-1'),
            };
            $text = AiProvenance::ascii(rtrim($text, "\0"));

            return $text === '' ? null : $text;
        }

        return null;
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

    private function uint32(string $bytes): int
    {
        return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
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
