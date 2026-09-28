<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use DOMElement;
use Masterminds\HTML5;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Controller\BackendViewMarkupTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ArrayNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\EscapingNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\TextNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;

/**
 * No backend template carries inline styling, in any branch, whether a render reaches it or not.
 * The backend CSP allows inline styles, so any of them would override backend.css unseen.
 *
 * Read with parsers, not patterns:
 * - each template is parsed by Fluid's TemplateParser from the RenderingContext TYPO3 configures,
 *   which removes comments and normalises tag and inline ViewHelper syntax into one node tree; no
 *   ViewHelper may receive a `style` argument, and no array argument (such as
 *   `additionalAttributes`) may have a `style` key, at any nesting depth;
 * - the literal HTML of the template (its text nodes, with every Fluid node in between replaced by
 *   a placeholder) is tokenised by masterminds/html5, the HTML5 parser TYPO3 core installs; no
 *   element may have a `style` attribute, and there may be no `<style>` element.
 *
 * Limit: ArrayNode has no public accessor for its entries in Fluid 5.3, so they are read through
 * reflection; a Fluid major that renames the property fails this test instead of passing it.
 */
final class TemplateInlineStyleTest extends AbstractFunctionalTestCase
{
    /** @return array<string, array{0: string}> */
    public static function backendTemplates(): array
    {
        return BackendViewMarkupTest::backendTemplates();
    }

    #[DataProvider('backendTemplates')]
    public function testNoViewHelperReceivesAStyle(string $template): void
    {
        $found = [];
        $this->walk($this->parse($template), static function (NodeInterface $node) use (&$found): void {
            if ($node instanceof ViewHelperNode) {
                foreach (array_keys($node->getArguments()) as $name) {
                    if (strtolower((string) $name) === 'style') {
                        $found[] = $node->getViewHelperClassName() . ' argument style';
                    }
                }
            }

            if ($node instanceof ArrayNode) {
                foreach (array_keys(self::entries($node)) as $key) {
                    if (strtolower((string) $key) === 'style') {
                        $found[] = 'array key style';
                    }
                }
            }
        });

        self::assertSame([], $found, $template);
    }

    #[DataProvider('backendTemplates')]
    public function testTheTemplateHtmlHasNoStyle(string $template): void
    {
        $html = '';
        $this->collectHtml($this->parse($template), $html);
        self::assertNotSame('', trim($html), $template);

        $html5    = new HTML5(['disable_html_ns' => true]);
        $document = $html5->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');

        $styled = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->tagName === 'style') {
                $styled[] = '<style> element';
            }

            if ($element->hasAttribute('style')) {
                $styled[] = '<' . $element->tagName . ' style="' . $element->getAttribute('style') . '">';
            }
        }

        self::assertSame([], $styled, $template);
    }

    /** The parser sees the template: a control that a style in its HTML and in a ViewHelper argument is found. */
    public function testThePartsAreSeenWhereTheyStand(): void
    {
        $source = '<html data-namespace-typo3-fluid="true" xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers">'
            . '<h1 class="x"style=\'color: red\'>T</h1>{f:link.action(style: \'a\', action: \'list\')}'
            . '<f:link.action action="list" additionalAttributes="{style: \'b\'}">x</f:link.action></html>';
        $root = GeneralUtility::makeInstance(RenderingContextFactory::class)->create()->getTemplateParser()->parse($source)->getRootNode();

        $html = '';
        $this->collectHtml($root, $html);
        $h1 = (new HTML5(['disable_html_ns' => true]))->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>')->getElementsByTagName('h1')->item(0);
        self::assertInstanceOf(DOMElement::class, $h1);
        self::assertSame('color: red', $h1->getAttribute('style'));

        $styles = 0;
        $this->walk($root, static function (NodeInterface $node) use (&$styles): void {
            if ($node instanceof ViewHelperNode && array_key_exists('style', $node->getArguments())) {
                ++$styles;
            }

            if ($node instanceof ArrayNode && array_key_exists('style', self::entries($node))) {
                ++$styles;
            }
        });
        self::assertSame(2, $styles);
    }

    private function parse(string $template): NodeInterface
    {
        $source = (string) file_get_contents(GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/' . $template));

        return GeneralUtility::makeInstance(RenderingContextFactory::class)->create()->getTemplateParser()->parse($source, $template)->getRootNode();
    }

    /** Visit every node: children, ViewHelper arguments, escaped nodes and array entries. */
    private function walk(mixed $node, callable $visit): void
    {
        if (!$node instanceof NodeInterface) {
            return;
        }

        $visit($node);
        if ($node instanceof EscapingNode) {
            $this->walk($node->getNode(), $visit);
        }

        if ($node instanceof ViewHelperNode) {
            foreach ($node->getArguments() as $argument) {
                $this->walk($argument, $visit);
            }
        }

        if ($node instanceof ArrayNode) {
            $entries = self::entries($node);
            array_walk_recursive($entries, fn (mixed $value) => $this->walk($value, $visit));
        }

        foreach ($node->getChildNodes() as $child) {
            $this->walk($child, $visit);
        }
    }

    /** The literal HTML of the template: text nodes as they stand, any Fluid node as a placeholder, ViewHelper bodies included. */
    private function collectHtml(NodeInterface $node, string &$html): void
    {
        if ($node instanceof TextNode) {
            $html .= $node->getText();

            return;
        }

        if ($node instanceof ViewHelperNode || $node instanceof EscapingNode) {
            $html .= 'x';
        }

        foreach ($node->getChildNodes() as $child) {
            $this->collectHtml($child, $html);
        }
    }

    /** @return array<array-key, mixed> */
    private static function entries(ArrayNode $node): array
    {
        $entries = (new ReflectionProperty(ArrayNode::class, 'internalArray'))->getValue($node);
        self::assertIsArray($entries);

        return $entries;
    }
}
