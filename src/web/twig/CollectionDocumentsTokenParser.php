<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Parses:
 *
 *   {% collectionDocuments 'index-name', ids [, 'fields,csv'] as varName %}
 *
 * Compiles to:
 *
 *   $context['varName'] = \cogapp\collectionsproxy\Plugin::getInstance()
 *       ->apiClient->getDocuments('index-name', $ids, 'fields,csv');
 *
 * Returns an array keyed by document ID → _source. `as varName` is required.
 */
class CollectionDocumentsTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'collectionDocuments';
    }

    public function parse(Token $token): CollectionDocumentsNode
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();
        $parser = $this->parser->getExpressionParser();

        $index = $parser->parseExpression();
        $stream->expect(Token::PUNCTUATION_TYPE, ',');
        $ids = $parser->parseExpression();

        $fields = new ConstantExpression(null, $lineno);
        if ($stream->nextIf(Token::PUNCTUATION_TYPE, ',') !== null) {
            $fields = $parser->parseExpression();
        }

        $stream->expect(Token::NAME_TYPE, 'as');
        $targetToken = $stream->expect(Token::NAME_TYPE);
        $target = new AssignNameExpression($targetToken->getValue(), $lineno);

        $stream->expect(Token::BLOCK_END_TYPE);

        return new CollectionDocumentsNode(
            ['index' => $index, 'ids' => $ids, 'fields' => $fields, 'target' => $target],
            [],
            $lineno,
            $this->getTag(),
        );
    }
}
