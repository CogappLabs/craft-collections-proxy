<?php

namespace cogapp\collectionsproxy\exceptions;

/**
 * Thrown when a `.php` query file is found but can't be turned into a
 * usable Elasticsearch body — e.g. the file doesn't return a callable
 * or array, or the callable throws.
 */
class QueryEvaluationException extends \RuntimeException
{
}
