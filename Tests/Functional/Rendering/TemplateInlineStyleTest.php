<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

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
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\RootNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\TextNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;

/**
 * No backend template writes inline styling into its own source. The backend CSP allows inline
 * styles, so any of them would override backend.css unseen.
 *
 * Read with parsers, not patterns, over every listed template and every branch in it, whether a
 * render reaches that branch or not:
 * - each template is parsed by Fluid's TemplateParser from the RenderingContext TYPO3 configures,
 *   which removes comments and normalises tag and inline ViewHelper syntax into one node tree; no
 *   ViewHelper may receive a `style` argument, and no array argument (such as
 *   `additionalAttributes`) may have a `style` key, at any nesting depth;
 * - the literal HTML is tokenised by masterminds/html5, the HTML5 parser TYPO3 core installs: the
 *   template's text nodes, and separately every string a ViewHelper argument or array entry holds
 *   (`{f:format.raw(value: '<span style=...>')}`). Every Fluid node that renders output (a
 *   ViewHelper, a variable such as `{job.uid}`, a literal, an expression) is replaced once by `x`
 *   and once by nothing, so an attribute split by a node (`class="a"{...}style=`, `sty{...}le=`)
 *   or an unquoted value made of one (`class={job.uid} style=`) is seen in one of the two readings.
 *   No element may have a `style` attribute, and there may be no `<style>` element.
 *
 * Not covered: markup produced at runtime rather than written in a template, such as a style built
 * from a variable (`{job.someHtml -> f:format.raw()}`) or a ViewHelper that emits one itself; a
 * partial rendered by a name held in a variable, beyond the templates listed and globbed in
 * BackendViewMarkupTest. The functional page renders in JobControllerTest cover what the fixtures
 * reach. The empty reading can report a style that never renders: a node that outputs an attribute
 * name right before `style` (`<b {f:if(..., then: 'data-a')}style=...>` renders `data-astyle`) is
 * read as `<b style=...>`. And ArrayNode has no public accessor for its entries in Fluid 5.3, so they
 * are read through reflection; a Fluid major that renames the property fails this test instead of
 * passing it.
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
        $fragments = $this->htmlFragments($this->parse($template));
        self::assertNotSame('', trim(implode('', $fragments)), $template);

        self::assertSame([], $this->styledIn($fragments), $template);
    }

    /** A control: a style in each form the two parts are meant to see is found. */
    public function testThePartsAreSeenWhereTheyStand(): void
    {
        $source = '<html data-namespace-typo3-fluid="true" xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers">'
            . '<h1 class="x"style=\'color: red\'>T</h1>{f:link.action(style: \'a\', action: \'list\')}'
            . '<p class="a"{f:if(condition: 0, then: \'x\')}style=\'color: blue\'>P</p>'
            . "<b sty{f:if(condition: 0, then: 'x')}le='color: green'>B</b>"
            . '{f:format.raw(value: \'<span style="color: gray">S</span>\')}'
            . '{f:format.raw(value: \'<i class={v} style="color: teal">I</i>\')}'
            . '<f:link.action action="list" additionalAttributes="{style: \'b\'}">x</f:link.action></html>';
        $root = GeneralUtility::makeInstance(RenderingContextFactory::class)->create()->getTemplateParser()->parse($source)->getRootNode();

        $styled = $this->styledIn($this->htmlFragments($root));
        sort($styled);
        // A style written plainly, split from its tag by a Fluid node, split inside its name, inside a
        // ViewHelper argument, and after an unquoted value made of a variable: each one is found.
        self::assertSame(['<b style="color: green">', '<h1 style="color: red">', '<i style="color: teal">', '<p style="color: blue">', '<span style="color: gray">'], $styled);

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

    /**
     * The HTML to tokenise: the template's text in two readings (each Fluid node replaced by `x`,
     * then by nothing), plus every string held in a ViewHelper argument or array entry.
     *
     * @return list<string>
     */
    private function htmlFragments(NodeInterface $root): array
    {
        $fragments = [];
        foreach (['x', ''] as $placeholder) {
            $html = '';
            $this->collectHtml($root, $html, $placeholder);
            $fragments[] = $html;
        }

        $this->walk($root, function (NodeInterface $node) use (&$fragments): void {
            $values = [];
            if ($node instanceof ViewHelperNode) {
                $values = array_values($node->getArguments());
            }

            if ($node instanceof ArrayNode) {
                $entries = self::entries($node);
                array_walk_recursive($entries, static function (mixed $value) use (&$values): void {
                    $values[] = $value;
                });
            }

            foreach ($values as $value) {
                if (is_string($value)) {
                    $fragments[] = $value;
                } elseif ($value instanceof NodeInterface && !$value instanceof ArrayNode) {
                    foreach (['x', ''] as $placeholder) {
                        $html = '';
                        $this->collectHtml($value, $html, $placeholder);
                        $fragments[] = $html;
                    }
                }
            }
        });

        return $fragments;
    }

    /**
     * @param list<string> $fragments
     *
     * @return list<string> every `<style>` element and `style` attribute the HTML5 parser finds
     */
    private function styledIn(array $fragments): array
    {
        $styled = [];
        foreach (array_unique($fragments) as $fragment) {
            $document = (new HTML5(['disable_html_ns' => true]))->loadHTML('<!DOCTYPE html><html><body>' . $fragment . '</body></html>');
            foreach ($document->getElementsByTagName('*') as $element) {
                if ($element->tagName === 'style') {
                    $styled[] = '<style> element';
                }

                if ($element->hasAttribute('style')) {
                    $styled[] = '<' . $element->tagName . ' style="' . $element->getAttribute('style') . '">';
                }
            }
        }

        return array_values(array_unique($styled));
    }

    /**
     * Text nodes as they stand; every other node that renders output stands as one placeholder,
     * ViewHelper bodies included. The rule is by exclusion rather than a list: of the node types
     * Fluid 5.3 produces, only TextNode (literal text) and RootNode (a container) render nothing of
     * their own, and EscapingNode only wraps the node it escapes. Everything else renders a value:
     * ViewHelperNode, ObjectAccessorNode (`{job.uid}`, unescaped inside f:format.raw or an argument
     * string), NumericNode, BooleanNode, ArrayNode and the expression nodes (ternary, math, casting).
     */
    private function collectHtml(NodeInterface $node, string &$html, string $placeholder): void
    {
        if ($node instanceof TextNode) {
            $html .= $node->getText();

            return;
        }

        if ($node instanceof EscapingNode) {
            $this->collectHtml($node->getNode(), $html, $placeholder);

            return;
        }

        if (!$node instanceof RootNode) {
            $html .= $placeholder;
        }

        foreach ($node->getChildNodes() as $child) {
            $this->collectHtml($child, $html, $placeholder);
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
