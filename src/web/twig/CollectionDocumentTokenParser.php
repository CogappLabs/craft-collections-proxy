<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Node\Expression\AssignNameExpression;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Parses:
 *
 *   {% collectionDocument 'index-name' documentId as varName %}
 *
 * Compiles to:
 *
 *   $context['varName'] = \cogapp\collectionsproxy\Plugin::getInstance()
 *       ->apiClient->getDocument('index-name', $documentId);
 *
 * `as varName` is required — without it the tag has no side effect.
 */
class CollectionDocumentTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'collectionDocument';
    }

    public function parse(Token $token): CollectionDocumentNode
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();
        $parser = $this->parser->getExpressionParser();

        $index = $parser->parseExpression();
        $id = $parser->parseExpression();

        $stream->expect(Token::NAME_TYPE, 'as');
        $targetToken = $stream->expect(Token::NAME_TYPE);
        $target = new AssignNameExpression($targetToken->getValue(), $lineno);

        $stream->expect(Token::BLOCK_END_TYPE);

        return new CollectionDocumentNode(
            ['index' => $index, 'id' => $id, 'target' => $target],
            [],
            $lineno,
            $this->getTag(),
        );
    }
}
