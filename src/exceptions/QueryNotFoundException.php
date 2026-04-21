<?php

namespace cogapp\collectionsproxy\exceptions;

/**
 * Thrown when `QueryLoader::load()` cannot find a `.php` file matching
 * the requested query name under the configured `queryPath`, or the
 * name contains disallowed characters (`..`, leading slash, leading dot).
 */
class QueryNotFoundException extends \RuntimeException
{
}
