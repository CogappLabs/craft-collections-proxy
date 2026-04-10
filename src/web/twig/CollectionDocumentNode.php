<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Compiler;
use Twig\Node\Node;

class CollectionDocumentNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$context[')
            ->repr($this->getNode('target')->getAttribute('name'))
            ->raw('] = \\cogapp\\collectionsproxy\\Plugin::getInstance()->apiClient->getDocument(')
            ->subcompile($this->getNode('index'))
            ->raw(', ')
            ->subcompile($this->getNode('id'))
            ->raw(");\n");
    }
}
