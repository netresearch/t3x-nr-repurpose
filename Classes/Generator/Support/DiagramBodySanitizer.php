<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Behavior\Attr;
use TYPO3\HtmlSanitizer\Behavior\Tag;
use TYPO3\HtmlSanitizer\Sanitizer;
use TYPO3\HtmlSanitizer\Visitor\CommonVisitor;

/**
 * Reduces the model's diagram body to static, styled markup before it goes into the
 * Schaubild template: text-level and block elements, lists and tables, with the
 * attributes `class`, `style`, `id`, `title`, `lang`, `dir`, `role` and `aria-*`
 * (plus `colspan`, `rowspan`, `scope` on table cells and `start`, `reversed` on
 * lists). Everything else is removed together with its content: scripts, styles,
 * links, images, media, frames, forms, SVG and event-handler attributes.
 *
 * The model is asked for an infographic laid out with inline CSS, so `style` stays.
 * Built on typo3/html-sanitizer directly rather than TYPO3's DefaultSanitizerBuilder,
 * whose URL checks need a frontend or backend request, which the worker does not have.
 */
final class DiagramBodySanitizer
{
    /** @var list<string> */
    private const array TAGS = [
        'div', 'span', 'p', 'section', 'article', 'header', 'footer', 'figure', 'figcaption', 'blockquote',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark', 'code', 'pre', 'abbr', 'q', 'cite', 'time',
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup',
    ];

    /** @var list<string> elements without content */
    private const array VOID_TAGS = ['br', 'hr', 'col'];

    /** @var list<string> */
    private const array GLOBAL_ATTRS = ['class', 'style', 'id', 'title', 'lang', 'dir', 'role'];

    /** @var array<string, list<string>> */
    private const array TAG_ATTRS = [
        'td'       => ['colspan', 'rowspan'],
        'th'       => ['colspan', 'rowspan', 'scope'],
        'col'      => ['span'],
        'colgroup' => ['span'],
        'ol'       => ['start', 'reversed'],
    ];

    private static ?Sanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): Sanitizer
    {
        if (self::$sanitizer instanceof Sanitizer) {
            return self::$sanitizer;
        }

        $globalAttrs   = array_map(static fn (string $name): Attr => new Attr($name), self::GLOBAL_ATTRS);
        $globalAttrs[] = new Attr('aria-', Attr::NAME_PREFIX);

        $tags = [];
        foreach (self::TAGS as $name) {
            $tags[] = self::tag(new Tag($name, Tag::ALLOW_CHILDREN), $globalAttrs);
        }

        foreach (self::VOID_TAGS as $name) {
            $tags[] = self::tag(new Tag($name), $globalAttrs);
        }

        $behavior = (new Behavior())
            ->withFlags(Behavior::REMOVE_UNEXPECTED_CHILDREN)
            ->withName('nr-repurpose-diagram-body')
            ->withTags(...$tags);

        return self::$sanitizer = new Sanitizer($behavior, new CommonVisitor($behavior));
    }

    /** @param list<Attr> $globalAttrs */
    private static function tag(Tag $tag, array $globalAttrs): Tag
    {
        $attrs = $globalAttrs;
        foreach (self::TAG_ATTRS[$tag->getName()] ?? [] as $name) {
            $attrs[] = new Attr($name);
        }

        return $tag->addAttrs(...$attrs);
    }
}
