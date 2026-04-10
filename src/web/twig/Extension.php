<?php

namespace cogapp\collectionsproxy\web\twig;

use Twig\Extension\AbstractExtension;

class Extension extends AbstractExtension
{
    public function getTokenParsers(): array
    {
        return [
            new CollectionDocumentTokenParser(),
        ];
    }
}
