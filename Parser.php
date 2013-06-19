<?php

namespace Gregwar\RST;

use Gregwar\RST\Nodes\Node;
use Gregwar\RST\Nodes\CodeNode;
use Gregwar\RST\Nodes\QuoteNode;
use Gregwar\RST\Nodes\TitleNode;
use Gregwar\RST\Nodes\ListNode;
use Gregwar\RST\Nodes\SeparatorNode;

class Parser
{
    /**
     * Letters used as separators for titles and horizontal line
     */
    public static $letters = array(
        '=' => 1,
        '-' => 2,
        '*' => 3,
        '~' => 4
    );

    protected $document;
    protected $buffer;
    protected $specialLevel;
    protected $directive = false;
    protected $isBlock = false;
    protected $isCode = false;

    /**
     * Tells if the current buffer is announcing a block of code
     */
    protected function prepareCode()
    {
        if (!$this->buffer) {
            return false;
        }

        $lastLine = trim($this->buffer[count($this->buffer)-1]);

        if (strlen($lastLine) >= 2) {
            return substr($lastLine, -2) == '::';
        } else {
            return false;
        }
    }

    protected function init()
    {
        $this->isBlock = false;
        $this->specialLevel = 0;
        $this->isCode = $this->prepareCode() || $this->directive;
        $this->buffer = array();
    }

    /**
     * Tell if a line is a special separating line for title and separators,
     * returns the depth of the special line
     */
    protected function isSpecialLine($line)
    {
        if (strlen($line) < 3) {
            return false;
        }

        $letter = $line[0];

        if (!isset(self::$letters[$letter])) {
            return false;
        }

        for ($i=1; $i<strlen($line); $i++) {
            if ($line[$i] != $letter) {
                return false;
            }
        }

        return self::$letters[$letter];
    }

    /**
     * Parses a list line
     *
     * @param $line the string line
     * @return an array containing:
     *         - true if the list is ordered, false else
     *         - the depth of the list
     *         - the text of the first line without the tick
     */
    protected function parseListLine($line)
    {
        $depth = 0;
        for ($i=0; $i<strlen($line); $i++) {
            $char = $line[$i];

            if ($char == ' ') {
                $depth++;
            } else if ($char == "\t") {
                $depth += 2;
            } else {
                break;
            }
        }

        if (preg_match('/^((\*)|([\d]+)\.) (.+)$/', trim($line), $match)) {
            return array($line[$i] == '*' ? false : true,
                $depth, $match[4]);
        }

        return false;
    }

    /**
     * Is the current block a list ?
     *
     * @return bool true if the current buffer should be treated as a list
     */
    protected function isList()
    {
        if (!$this->buffer) {
            return false;
        }

        // A buffer is a list if at leas the first line is a list-style
        return $this->parseListLine($this->buffer[0]);
    }

    /**
     * Create a list node from the current buffer
     *
     * @return ListNode a list node containing all list items
     */
    public function createListNode()
    {
            $node = new ListNode();
            $lineInfo = null;
            $listLine = array();
            foreach ($this->buffer as $line) {
                $infos = $this->parseListLine($line);
                if ($infos) {
                    if ($listLine) {
                        $node->addLine($this->createSpan($listLine), $lineInfo[0], $lineInfo[1]);
                    }
                    $listLine = array($infos[2]);
                    $lineInfo = $infos;
                } else {
                    $listLine[] = $line;
                }
            }
            if ($listLine) {
                $node->addLine($this->createSpan($listLine), $lineInfo[0], $lineInfo[1]);
            }
            $node->close();

            return $node;
    }

    /**
     * A line is a code line if it's empty or if it begins with
     * a trimable caracter, for instance:
     *
     *     This is a block because there is a space in the front
     *     of the caracters
     *
     *     This is still part of the block, even if there is an empty line
     *
     * @param $line the line text
     * @return true if the line is still in a block
     */
    protected function isBlockLine($line)
    {
        if (strlen($line)) {
            return !trim($line[0]);
        } else {
            return !trim($line);
        }
    }

    /**
     * Get current directive if the buffer contains one
     *
     * .. [variable] name:: data
     *     :option: value
     *     :otherOption: otherValue
     *
     * @return false if this is not a directive, else an array containing :
     *         - variable: the variable name of the directive
     *         - name: the directive name
     *         - data: the data of the directive
     *         - options: an array of all the options and their values
     */
    protected function getDirective()
    {
        if (!$this->buffer) {
            return false;
        }

        if (preg_match('/^\.\. (\[(.+)\] |)(.+):: (.*)$/mUsi', $this->buffer[0], $match)) {
            $directive = array(
                'variable' => $match[2],
                'name' => $match[3],
                'data' => $match[4],
                'options' => array()
            );

            for ($i=1; $i<count($this->buffer); $i++) {
                if (preg_match('/^([ ]+):(.+): (.+)$/mUsi', $this->buffer[$i], $match)) {
                    $directive['options'][$match[2]] = $match[3];
                } else {
                    return false;
                }
            }

            return $directive;
        }

        return false;
    }

    /**
     * Flushes the current buffer to create a node
     */
    protected function flush()
    {
        $node = null;
        $directive = null;

        if ($this->buffer) {
            if ($this->specialLevel) {
                $data = implode("\n", $this->buffer);
                if ($data) {
                    $node = new TitleNode($data, $this->specialLevel);
                } else {
                    $node = new SeparatorNode;
                }
            } else if ($this->isBlock) {
                if ($this->isCode) {
                    $node = new CodeNode(implode("\n", $this->buffer));
                } else {
                    $node = new QuoteNode(implode("\n", $this->buffer));
                }
            } else {
                if ($this->isList()) {
                    $node = $this->createListNode();
                } else {
                    $directive = $this->getDirective();
                    if (!$directive) {
                        $node = new Node($this->createSpan($this->buffer));
                    }
                }
            }
        }

        if ($this->directive) {
        //    throw new \Exception('Unknown directive: '.$this->directive['name']);
        }

        $this->directive = $directive;

        if ($node) {
            $this->document->addNode($node);
        }
        
        $this->init();
    }

    /**
     * Process one line
     *
     * @param $line the line string
     */
    protected function parseLine(&$line)
    {
        if ($this->isBlockLine($line)) {
            if (!$this->buffer && trim($line)) {
                $this->isBlock = true;
            }
        } else {
            if ($this->isBlock) {
                $this->flush();
            }
        }

        if (!$this->isBlock) {
            if (!trim($line)) {
                $this->flush();
            } else {
                $specialLevel = $this->isSpecialLine($line);

                if ($specialLevel) {
                    $lastLine = array_pop($this->buffer);
                    $this->flush();

                    $this->specialLevel = $specialLevel;
                    $this->buffer = array($lastLine);
                    $this->flush();
                } else {
                    $this->buffer[] = $line;
                }
            }
        } else {
            $this->buffer[] = $line;
        }
    }

    /**
     * Process all the lines of a document string
     *
     * @param $document the string (content) of the document
     */
    protected function parseLines(&$document)
    {
        $lines = explode("\n", $document);

        foreach ($lines as $line) {
            $this->parseLine($line);
        }

        // Document is flushed twice to trigger the directives
        $this->flush();
        $this->flush();
    }

    /**
     * Parse a document and return a Document instance
     *
     * @param $document the contents (string) of the document
     * @return $document the created document
     */
    public function parse(&$document)
    {
        $this->document = new Document;
        $this->init();
        $this->parseLines(trim($document));

        return $this->document;
    }

    /**
     * Create a span, which is a text with inline style
     *
     * @param $span the content string
     * @return Span a span object
     */
    public function createSpan($span)
    {
        return new Span($this, $span);
    }
}
