<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ArrayExpression;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Experimental. Signature and returned shape may change without a
 * deprecation cycle until this stabilises.
 *
 * Parses:
 *
 *   {% collectionEsSearch 'index-name', queryOrBody [, params] as varName %}
 *
 * Where `queryOrBody` is either:
 *
 *   - a **string** — the name of a PHP query file under `queryPath`, loaded
 *     via `QueryLoader::load($name, $params)`
 *   - an **array/hash** — an inline ES body, passed straight to
 *     `ApiClient::esSearch()`
 *
 * The distinction is resolved at runtime (`is_string` vs `is_array`), so
 * either form parses the same way.
 *
 * `params` is only meaningful for the file form — inline bodies embed
 * their own variables via Twig.
 *
 * Returns the richer shape `{results, totalResults, took, aggregations, raw}`
 * — so templates can render facet buckets alongside result cards.
 */
class CollectionEsSearchTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'collectionEsSearch';
    }

    public function parse(Token $token): CollectionEsSearchNode
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();
        $parser = $this->parser->getExpressionParser();

        $index = $parser->parseExpression();
        $stream->expect(Token::PUNCTUATION_TYPE, ',');
        $queryOrBody = $parser->parseExpression();

        $params = new ArrayExpression([], $lineno);
        if ($stream->nextIf(Token::PUNCTUATION_TYPE, ',') !== null) {
            $params = $parser->parseExpression();
        }

        $stream->expect(Token::NAME_TYPE, 'as');
        $targetToken = $stream->expect(Token::NAME_TYPE);
        $target = new AssignNameExpression($targetToken->getValue(), $lineno);

        $stream->expect(Token::BLOCK_END_TYPE);

        return new CollectionEsSearchNode(
            ['index' => $index, 'queryOrBody' => $queryOrBody, 'params' => $params, 'target' => $target],
            [],
            $lineno,
            $this->getTag(),
        );
    }
}
