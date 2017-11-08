<?php

namespace Finesse\Wired;

use Finesse\MiniDB\Query;
use Finesse\MiniDB\QueryProxy;
use Finesse\MiniDB\Exceptions\DatabaseException as DBDatabaseException;
use Finesse\MiniDB\Exceptions\IncorrectQueryException as DBIncorrectQueryException;
use Finesse\MiniDB\Exceptions\InvalidArgumentException as DBInvalidArgumentException;
use Finesse\QueryScribe\Query as QSQuery;
use Finesse\QueryScribe\QueryBricks\Criterion;
use Finesse\Wired\Exceptions\DatabaseException;
use Finesse\Wired\Exceptions\ExceptionInterface;
use Finesse\Wired\Exceptions\IncorrectQueryException;
use Finesse\Wired\Exceptions\InvalidArgumentException;
use Finesse\Wired\Exceptions\RelationException;

/**
 * Query builder for targeting a model.
 *
 * @author Surgie
 */
class ModelQuery extends QueryProxy
{
    /**
     * @var string|null|ModelInterface Target model class name
     */
    protected $modelClass;

    /**
     * {@inheritDoc}
     * @param Query $baseQuery Underlying database query object
     * @param string|null $modelClass Target model class name (already checked)
     */
    public function __construct(Query $baseQuery, string $modelClass = null)
    {
        parent::__construct($baseQuery);
        $this->setModelClass($modelClass);
    }

    /**
     * {@inheritDoc}
     * @return Query
     */
    public function getBaseQuery(): QSQuery
    {
        return parent::getBaseQuery();
    }

    /**
     * FOR INNER USAGE ONLY!
     *
     * @param string|null $modelClass Target model class name (already checked)
     */
    public function setModelClass(string $modelClass = null)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Gets the model by the identifier.
     *
     * @param int|string|int[]|string[] $id Identifier or an array of identifiers
     * @return ModelInterface|null|ModelInterface[] If an identifier is given, a model object or null is returned. If an
     *     array of identifiers is given, an array of models is returned (order is not defined).
     * @throws InvalidArgumentException
     * @throws IncorrectQueryException
     * @throws DatabaseException
     */
    public function find($id)
    {
        if ($this->modelClass === null) {
            throw new IncorrectQueryException('This query is not a model query');
        }

        $idField = $this->modelClass::getIdentifierField();

        if (is_array($id)) {
            return (clone $this)->whereIn($idField, $id)->get();
        } else {
            return (clone $this)->where($idField, $id)->first();
        }
    }

    /**
     * Adds a model relation criterion.
     *
     * @param string $relationName Current model relation name
     * @param ModelInterface|\Closure|null $target Relation target. ModelInterface means "must be related to the
     *     specified model". Closure means "must be related to a model that fit the clause in the closure". Null means
     *     "must be related to anything".
     * @param bool $not Whether the rule should be "not related"
     * @param int $appendRule How the criterion should be appended to the others (on of Criterion::APPEND_RULE_*
     *    constants)
     * @return $this
     * @throws RelationException
     * @throws InvalidArgumentException
     * @throws IncorrectQueryException
     */
    public function whereRelation(
        string $relationName,
        $target = null,
        bool $not = false,
        int $appendRule = Criterion::APPEND_RULE_AND
    ): self {
        if ($this->modelClass === null) {
            throw new IncorrectQueryException('This query is not a model query');
        }

        $relation = $this->modelClass::getRelation($relationName);

        if ($relation === null) {
            throw new RelationException(sprintf(
                'The relation `%s` is not defined in the %s model',
                $relationName,
                $this->modelClass
            ));
        }

        $applyRelation = function (self $query) use ($relation, $target) {
            $relation->applyToQueryWhere($query, [$target]);
        };

        if ($not) {
            return $this->whereNot($applyRelation, $appendRule);
        } else {
            return $this->where($applyRelation, null, null, $appendRule);
        }
    }

    /**
     * Adds a model relation criterion with the OR append rule.
     *
     * @see whereRelation For the arguments and exceptions reference
     * @return $this
     */
    public function orWhereRelation(string $relationName, $target = null)
    {
        return $this->whereRelation($relationName, $target, false, Criterion::APPEND_RULE_OR);
    }

    /**
     * Adds a model relation absence criterion.
     *
     * @see whereRelation For the arguments and exceptions reference
     * @return $this
     */
    public function whereNoRelation(string $relationName, $target = null)
    {
        return $this->whereRelation($relationName, $target, true);
    }

    /**
     * Adds a model relation absence criterion with the OR append rule.
     *
     * @see whereRelation For the arguments and exceptions reference
     * @return $this
     */
    public function orWhereNoRelation(string $relationName, $target = null)
    {
        return $this->whereRelation($relationName, $target, true, Criterion::APPEND_RULE_OR);
    }

    /**
     * {@inheritDoc}
     * @return static
     */
    public function resolveCriteriaGroupClosure(\Closure $callback): QSQuery
    {
        $query = new static($this->baseQuery->makeCopyForCriteriaGroup(), $this->modelClass);
        return $this->resolveClosure($callback, $query);
    }

    /**
     * {@inheritDoc}
     * @return ModelInterface|mixed
     */
    protected function processFetchedRow(array $row)
    {
        if ($this->modelClass !== null) {
            return $this->modelClass::createFromRow($row);
        }

        return parent::processFetchedRow($row);
    }

    /**
     * {@inheritdoc}
     * @throws ExceptionInterface|\Throwable
     */
    protected function handleBaseQueryException(\Throwable $exception)
    {
        if ($exception instanceof DBInvalidArgumentException) {
            throw new InvalidArgumentException($exception->getMessage(), $exception->getCode(), $exception);
        }
        if ($exception instanceof DBIncorrectQueryException) {
            throw new IncorrectQueryException($exception->getMessage(), $exception->getCode(), $exception);
        }
        if ($exception instanceof DBDatabaseException) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return parent::handleBaseQueryException($exception);
    }
}
