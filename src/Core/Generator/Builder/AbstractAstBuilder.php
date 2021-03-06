<?php

/**
 * Part of starter project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Core\Generator\Builder;

use PhpParser\BuilderFactory;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;

/**
 * The AbstractAstBuilder class.
 */
abstract class AbstractAstBuilder
{
    private ?Parser $parser = null;

    protected ?\Closure $handler = null;

    abstract public function process(array $options = []): string;

    protected static function getLastIndexOf(array $elements, callable $handler): string|int|null
    {
        $elements = array_filter($elements, $handler);

        return array_key_last($elements);
    }

    protected function getParser(): Parser
    {
        if (!$this->parser) {
            $lexer = $this->getLexer();

            $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        }

        return $this->parser;
    }

    protected function getLexer(): Emulative
    {
        return $this->lexer ??= new Emulative(
            [
                'usedAttributes' => [
                    'comments',
                    'startLine',
                    'endLine',
                    'startTokenPos',
                    'endTokenPos',
                ],
            ]
        );
    }

    public function createNodeFactory(): BuilderFactory
    {
        return new BuilderFactory();
    }

    public function attributeGroup(...$attrs): Node\AttributeGroup
    {
        return new Node\AttributeGroup($attrs);
    }

    public function attribute(string $name, ...$args): Node\Attribute
    {
        $args = array_map(fn ($arg) => new Node\Arg($arg), $args);

        return new Node\Attribute(
            new Node\Name($name),
            $args
        );
    }

    protected function convertCode(
        string $code,
        ?\Closure $enterNode = null,
        ?\Closure $leaveNode = null
    ): string {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());

        $parser = $this->getParser();
        $oldAst    = $parser->parse($code);
        $oldTokens = $this->getLexer()->getTokens();

        $traverser->addVisitor($this->createVisitor($enterNode, $leaveNode));
        $newAst = $traverser->traverse($oldAst);

        $prettyPrinter = new \PhpParser\PrettyPrinter\Standard();

        return $prettyPrinter->printFormatPreserving($newAst, $oldAst, $oldTokens);
    }

    protected function createVisitor(?\Closure $enterNode, ?\Closure $leaveNode): NodeVisitorAbstract
    {
        return new class($enterNode, $leaveNode) extends NodeVisitorAbstract {
            public function __construct(
                protected ?\Closure $enterNode = null,
                protected ?\Closure $leaveNode = null
            ) {
                //
            }

            public function enterNode(Node $node)
            {
                if (!$this->enterNode) {
                    return null;
                }

                return ($this->enterNode)($node);
            }

            public function leaveNode(Node $node)
            {
                if (!$this->leaveNode) {
                    return null;
                }

                return ($this->leaveNode)($node);
            }
        };
    }
}
