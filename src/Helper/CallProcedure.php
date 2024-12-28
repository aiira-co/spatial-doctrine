<?php
declare(strict_types=1);
namespace Spatial\Entity\Helper;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Custom DQL function to call a stored procedure.
 */
class CallProcedure extends FunctionNode
{
    private array $parameters = [];

    public function parse(Parser $parser): void
    {
        $parser->match(Lexer::T_IDENTIFIER); // Match the function name
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        while ($parser->getLexer()->lookahead['type'] !== Lexer::T_CLOSE_PARENTHESIS) {
            $this->parameters[] = $parser->ArithmeticPrimary();
            if ($parser->getLexer()->lookahead['type'] === Lexer::T_COMMA) {
                $parser->match(Lexer::T_COMMA);
            }
        }

        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $parameters = [];
        foreach ($this->parameters as $parameter) {
            $parameters[] = $sqlWalker->walkArithmeticPrimary($parameter);
        }

        return sprintf('CALL %s(%s)', $this->parameters[0], implode(', ', array_slice($parameters, 1)));
    }
}