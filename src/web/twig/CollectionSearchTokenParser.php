<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Parses:
 *
 *   {% collectionSearch 'index-name', query [, perPage [, page]] as varName %}
 *
 * Compiles to:
 *
 *   $context['varName'] = \cogapp\collectionsproxy\Plugin::getInstance()
 *       ->apiClient->search('index-name', $query, $perPage, $page);
 *
 * `perPage` defaults to 20, `page` defaults to 1. `as varName` is required.
 */
class CollectionSearchTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'collectionSearch';
    }

    public function parse(Token $token): CollectionSearchNode
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();
        $parser = $this->parser->getExpressionParser();

        $index = $parser->parseExpression();
        $stream->expect(Token::PUNCTUATION_TYPE, ',');
        $query = $parser->parseExpression();

        $perPage = new ConstantExpression(20, $lineno);
        $page = new ConstantExpression(1, $lineno);

        if ($stream->nextIf(Token::PUNCTUATION_TYPE, ',') !== null) {
            $perPage = $parser->parseExpression();
            if ($stream->nextIf(Token::PUNCTUATION_TYPE, ',') !== null) {
                $page = $parser->parseExpression();
            }
        }

        $stream->expect(Token::NAME_TYPE, 'as');
        $targetToken = $stream->expect(Token::NAME_TYPE);
        $target = new AssignNameExpression($targetToken->getValue(), $lineno);

        $stream->expect(Token::BLOCK_END_TYPE);

        return new CollectionSearchNode(
            ['index' => $index, 'query' => $query, 'perPage' => $perPage, 'page' => $page, 'target' => $target],
            [],
            $lineno,
            $this->getTag(),
        );
    }
}
