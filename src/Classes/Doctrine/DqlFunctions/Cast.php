<?php

namespace Classes\Doctrine\DqlFunctions;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * "CAST" "(" Expression "AS" type ")"
 */
class Cast extends FunctionNode
{
    public $expression;
    public $type;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'CAST(' .
            $this->expression->dispatch($sqlWalker) . ' AS ' .
            str_replace(['\'', '"'], '', $this->type->dispatch($sqlWalker)) .
            ')';
    }

    public function parse(Parser $parser): void
    {
        $parser->match(Lexer::T_OPEN_PARENTHESIS);
        $this->expression = $parser->ArithmeticExpression();
        $parser->match(Lexer::T_COMMA);
        $this->type = $parser->ArithmeticPrimary();
        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }
}
