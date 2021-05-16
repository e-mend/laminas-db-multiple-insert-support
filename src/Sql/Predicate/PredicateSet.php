<?php

namespace Laminas\Db\Sql\Predicate;

use Countable;
use Laminas\Db\Sql\Exception;

class PredicateSet implements PredicateInterface, Countable
{
    const COMBINED_BY_AND = 'AND';
    const OP_AND          = 'AND';

    const COMBINED_BY_OR  = 'OR';
    const OP_OR           = 'OR';

    protected $defaultCombination = self::COMBINED_BY_AND;
    protected $predicates         = [];

    /**
     * Constructor
     *
     * @param  null|array $predicates
     * @param  string $defaultCombination
     */
    public function __construct(array $predicates = null, $defaultCombination = self::COMBINED_BY_AND)
    {
        $this->defaultCombination = $defaultCombination;
        if ($predicates) {
            foreach ($predicates as $predicate) {
                $this->addPredicate($predicate);
            }
        }
    }

    /**
     * Add predicate to set
     *
     * @param  PredicateInterface $predicate
     * @param  string $combination
     * @return self Provides a fluent interface
     */
    public function addPredicate(PredicateInterface $predicate, $combination = null)
    {
        if ($combination === null || ! in_array($combination, [self::OP_AND, self::OP_OR])) {
            $combination = $this->defaultCombination;
        }

        if ($combination == self::OP_OR) {
            $this->orPredicate($predicate);
            return $this;
        }

        $this->andPredicate($predicate);
        return $this;
    }

    /**
     * Add predicates to set
     *
     * @param PredicateInterface|\Closure|string|array $predicates
     * @param string $combination
     * @return self Provides a fluent interface
     * @throws Exception\InvalidArgumentException
     */
    public function addPredicates($predicates, $combination = self::OP_AND)
    {
        if ($predicates === null) {
            throw new Exception\InvalidArgumentException('Predicate cannot be null');
        }
        if ($predicates instanceof PredicateInterface) {
            $this->addPredicate($predicates, $combination);
            return $this;
        }
        if ($predicates instanceof \Closure) {
            $predicates($this);
            return $this;
        }
        if (is_string($predicates)) {
            // String $predicate should be passed as an expression
            $predicate = (strpos($predicates, Expression::PLACEHOLDER) !== false)
                ? new Expression($predicates) : new Literal($predicates);
            $this->addPredicate($predicate, $combination);
            return $this;
        }
        if (is_array($predicates)) {
            foreach ($predicates as $pkey => $pvalue) {
                // loop through predicates
                if (is_string($pkey)) {
                    if (strpos($pkey, '?') !== false) {
                        // First, process strings that the abstraction replacement character ?
                        // as an Expression predicate
                        $predicate = new Expression($pkey, $pvalue);
                    } elseif ($pvalue === null) {
                        // Otherwise, if still a string, do something intelligent with the PHP type provided
                        // map PHP null to SQL IS NULL expression
                        $predicate = new IsNull($pkey);
                    } elseif (is_array($pvalue)) {
                        // if the value is an array, assume IN() is desired
                        $predicate = new In($pkey, $pvalue);
                    } elseif ($pvalue instanceof PredicateInterface) {
                        throw new Exception\InvalidArgumentException(
                            'Using Predicate must not use string keys'
                        );
                    } else {
                        // otherwise assume that array('foo' => 'bar') means "foo" = 'bar'
                        $predicate = new Operator($pkey, Operator::OP_EQ, $pvalue);
                    }
                } elseif ($pvalue instanceof PredicateInterface) {
                    // Predicate type is ok
                    $predicate = $pvalue;
                } else {
                    // must be an array of expressions (with int-indexed array)
                    $predicate = (strpos($pvalue, Expression::PLACEHOLDER) !== false)
                        ? new Expression($pvalue) : new Literal($pvalue);
                }
                $this->addPredicate($predicate, $combination);
            }
        }
        return $this;
    }

    /**
     * Return the predicates
     *
     * @return PredicateInterface[]
     */
    public function getPredicates()
    {
        return $this->predicates;
    }

    /**
     * Add predicate using OR operator
     *
     * @param  PredicateInterface $predicate
     * @return self Provides a fluent interface
     */
    public function orPredicate(PredicateInterface $predicate)
    {
        $this->predicates[] = [self::OP_OR, $predicate];
        return $this;
    }

    /**
     * Add predicate using AND operator
     *
     * @param  PredicateInterface $predicate
     * @return self Provides a fluent interface
     */
    public function andPredicate(PredicateInterface $predicate)
    {
        $this->predicates[] = [self::OP_AND, $predicate];
        return $this;
    }

    /**
     * Get predicate parts for where statement
     *
     * @return array
     */
    public function getExpressionData()
    {
        $parts = [];
        for ($i = 0, $count = count($this->predicates); $i < $count; $i++) {
            /** @var $predicate PredicateInterface */
            $predicate = $this->predicates[$i][1];

            if ($predicate instanceof PredicateSet) {
                $parts[] = '(';
            }

            $parts = array_merge($parts, $predicate->getExpressionData());

            if ($predicate instanceof PredicateSet) {
                $parts[] = ')';
            }

            if (isset($this->predicates[$i + 1])) {
                $parts[] = sprintf(' %s ', $this->predicates[$i + 1][0]);
            }
        }
        return $parts;
    }

    /**
     * Get count of attached predicates
     *
     * @return int
     */
    public function count()
    {
        return count($this->predicates);
    }
}
