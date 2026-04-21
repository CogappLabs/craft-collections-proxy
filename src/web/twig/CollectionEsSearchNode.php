<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Compiles {% collectionEsSearch %} to a single call into
 * `Plugin::runEsSearch()`, which does the string-or-array dispatch and
 * handles loader errors. Keeps this Node trivial — all the real logic
 * lives in the Plugin method, where it's testable as plain PHP.
 */
class CollectionEsSearchNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$_plugin = \\cogapp\\collectionsproxy\\Plugin::getInstance();' . "\n")
            ->write('$context[')
            ->repr($this->getNode('target')->getAttribute('name'))
            ->raw('] = $_plugin !== null ? $_plugin->runEsSearch(')
            ->subcompile($this->getNode('index'))
            ->raw(', ')
            ->subcompile($this->getNode('queryOrBody'))
            ->raw(', ')
            ->subcompile($this->getNode('params'))
            ->raw(') : \\cogapp\\collectionsproxy\\services\\ApiClient::emptyEsSearchResponse();' . "\n");
    }
}
