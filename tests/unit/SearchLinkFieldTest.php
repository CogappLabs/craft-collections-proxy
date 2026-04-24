<?php

namespace cogapp\collectionsproxy\tests\unit;

use cogapp\collectionsproxy\fields\SearchLinkField;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchLinkField's per-field / global / hardcoded
 * fallback chain. Pure PHPUnit — no Craft bootstrap needed because
 * resolveTitleField() takes the global default as an argument.
 */
class SearchLinkFieldTest extends TestCase
{
    public function testTitleFieldPrefersPerFieldSetting(): void
    {
        $field = new SearchLinkField();
        $field->titleField = 'displayTitle';

        self::assertSame('displayTitle', $field->resolveTitleField('globalTitle'));
    }

    public function testTitleFieldFallsBackToGlobalWhenFieldEmpty(): void
    {
        $field = new SearchLinkField();

        self::assertSame('globalTitle', $field->resolveTitleField('globalTitle'));
    }

    public function testTitleFieldFallsBackToHardcodedTitleWhenGlobalEmpty(): void
    {
        $field = new SearchLinkField();

        self::assertSame('title', $field->resolveTitleField(''));
    }

    public function testTitleFieldFallsBackToHardcodedTitleWhenGlobalNull(): void
    {
        $field = new SearchLinkField();

        self::assertSame('title', $field->resolveTitleField(null));
    }
}
