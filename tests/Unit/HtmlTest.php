<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testEmptyInput(): void
    {
        $this->assertSame('', Html::toPlainText(''));
        $this->assertSame('', Html::toPlainText(null));
    }

    public function testStripsTagsAndDecodesEntities(): void
    {
        $html = '<p>Привет&nbsp;<b>мир</b></p><br>строка';
        $text = Html::toPlainText($html);
        $this->assertStringContainsString('Привет', $text);
        $this->assertStringContainsString('мир', $text);
        $this->assertStringNotContainsString('<b>', $text);
    }

    public function testListItems(): void
    {
        $text = Html::toPlainText('<ul><li>один</li><li>два</li></ul>');
        $this->assertStringContainsString('• один', $text);
        $this->assertStringContainsString('• два', $text);
    }
}
