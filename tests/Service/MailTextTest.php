<?php

namespace App\Tests\Service;

use App\Service\MailText;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for HTML-body extraction. strip_tags() keeps element
 * contents, so <style> CSS used to leak into indexed bodies (seen on
 * corporate HTML-only mail: "html, body, p, table … {font-size:12px;…}").
 */
final class MailTextTest extends TestCase
{
    public function testStyleBlocksDoNotLeakIntoText(): void
    {
        $html = <<<'HTML'
            <html><head>
            <style type="text/css">html, body, p, table, tr, td, li {font-size:12px;font-family:Arial, Helvetica, sans-serif;color:#000000;font-weight:normal}</style>
            <title>ignored</title>
            </head><body>
            <p>Bonjour,</p>
            <p>Votre dossier est en cours.</p>
            </body></html>
            HTML;

        $text = MailText::fromHtml($html);
        $this->assertStringNotContainsString('font-size', $text);
        $this->assertStringNotContainsString('Arial', $text);
        $this->assertStringNotContainsString('ignored', $text);
        $this->assertStringContainsString('Bonjour,', $text);
        $this->assertStringContainsString('Votre dossier est en cours.', $text);
    }

    public function testScriptsAndConditionalCommentsAreDropped(): void
    {
        $html = '<!--[if mso]><style>.mso {mso-style:1}</style><![endif]--><script>var x = 1;</script><div>Le contenu</div>';
        $text = MailText::fromHtml($html);
        $this->assertSame('Le contenu', $text);
    }

    public function testEntitiesDecodeAndWhitespaceCollapses(): void
    {
        $html = "<table><tr><td>T&eacute;l&nbsp;:</td><td>01 23 45 67 89</td></tr></table>\n\n\n<p>  fin  </p>";
        $text = MailText::fromHtml($html);
        $this->assertStringContainsString('Tél :', $text);
        $this->assertStringContainsString('01 23 45 67 89', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
    }

    public function testBlockEndsBecomeNewlines(): void
    {
        $text = MailText::fromHtml('<p>ligne 1</p><p>ligne 2</p>');
        $this->assertSame("ligne 1\nligne 2", $text);
    }

    public function testEscapedHtmlDoesNotRematerializeAsMarkup(): void
    {
        // Entity decoding turns &lt;div …&gt; into real tags after strip_tags
        // already ran; a second pass must strip those too.
        $html = '<p>Bonjour</p>&lt;body style=&quot;font-family:Arial&quot;&gt;&lt;style&gt;* {font-size:12px}&lt;/style&gt;Au revoir&lt;/body&gt;';
        $text = MailText::fromHtml($html);
        $this->assertStringNotContainsString('font-family', $text);
        $this->assertStringNotContainsString('font-size', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Bonjour', $text);
        $this->assertStringContainsString('Au revoir', $text);
    }
}
