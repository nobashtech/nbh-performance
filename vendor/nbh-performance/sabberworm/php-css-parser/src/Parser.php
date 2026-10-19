<?php

declare (strict_types=1);
namespace NBH\Performance\Vendor\Sabberworm\CSS;

use NBH\Performance\Vendor\Sabberworm\CSS\CSSList\Document;
use NBH\Performance\Vendor\Sabberworm\CSS\Parsing\ParserState;
use NBH\Performance\Vendor\Sabberworm\CSS\Parsing\SourceException;
/**
 * This class parses CSS from text into a data structure.
 * @internal
 */
class Parser
{
    /**
     * @var ParserState
     */
    private $parserState;
    /**
     * @param string $text the complete CSS as text (i.e., usually the contents of a CSS file)
     * @param int<1, max> $lineNumber the line number (starting from 1, not from 0)
     */
    public function __construct(string $text, ?Settings $parserSettings = null, int $lineNumber = 1)
    {
        if ($parserSettings === null) {
            $parserSettings = Settings::create();
        }
        $this->parserState = new ParserState($text, $parserSettings, $lineNumber);
    }
    /**
     * Parses the CSS provided to the constructor and creates a `Document` from it.
     *
     * @throws SourceException
     */
    public function parse() : Document
    {
        return Document::parse($this->parserState);
    }
}
