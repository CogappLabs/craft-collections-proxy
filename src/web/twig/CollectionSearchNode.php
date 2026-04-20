<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Compiler;
use Twig\Node\Node;

class CollectionSearchNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$_plugin = \\cogapp\\collectionsproxy\\Plugin::getInstance();' . "\n")
            ->write('$context[')
            ->repr($this->getNode('target')->getAttribute('name'))
            ->raw('] = $_plugin !== null ? $_plugin->apiClient->search(')
            ->subcompile($this->getNode('index'))
            ->raw(', ')
            ->subcompile($this->getNode('query'))
            ->raw(', ')
            ->subcompile($this->getNode('perPage'))
            ->raw(', ')
            ->subcompile($this->getNode('page'))
            ->raw(") : ['results' => [], 'totalResults' => 0, 'took' => 0];\n");
    }
}
