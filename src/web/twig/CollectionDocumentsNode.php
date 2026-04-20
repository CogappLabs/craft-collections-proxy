<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Compiler;
use Twig\Node\Node;

class CollectionDocumentsNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$_plugin = \\cogapp\\collectionsproxy\\Plugin::getInstance();' . "\n")
            ->write('$context[')
            ->repr($this->getNode('target')->getAttribute('name'))
            ->raw('] = $_plugin !== null ? $_plugin->apiClient->getDocuments(')
            ->subcompile($this->getNode('index'))
            ->raw(', ')
            ->subcompile($this->getNode('ids'))
            ->raw(', ')
            ->subcompile($this->getNode('fields'))
            ->raw(") : [];\n");
    }
}
