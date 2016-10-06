<?php

namespace Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Connections;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Driver\ServerGoneAwayExceptionsAwareInterface;

class MasterSlaveConnection extends \Doctrine\DBAL\Connections\MasterSlaveConnection
{
    /**
     * @var int
     */
    protected $reconnectAttempts = 0;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $params, Driver $driver, Configuration $config = null, EventManager $eventManager = null)
    {
        if (!$driver instanceof ServerGoneAwayExceptionsAwareInterface) {
            throw new \InvalidArgumentException(
                sprintf('%s needs a driver that implements ServerGoneAwayExceptionsAwareInterface', get_class($this))
            );
        }

        if (isset($params['driverOptions']['x_reconnect_attempts'])) {
            $this->reconnectAttempts = (int) $params['driverOptions']['x_reconnect_attempts'];
        }

        parent::__construct($params, $driver, $config, $eventManager);
    }

    /**
     * {@inheritDoc}
     */
    public function executeQuery($query, array $params = array(), $types = array(), QueryCacheProfile $qcp = null)
    {
        $stmt = null;
        $attempt = 0;
        $retry = true;
        while ($retry) {
            $retry = false;
            try {
                $stmt = parent::executeQuery($query, $params, $types, $qcp);
            } catch (\Exception $e) {
                if ($this->canTryAgain($attempt) && $this->isRetryableException($e, $query)) {
                    $this->close();
                    ++$attempt;
                    $retry = true;
                } else {
                    throw $e;
                }
            }
        }

        return $stmt;
    }

    /**
     * @param $attempt
     * @param bool $ignoreTransactionLevel
     *
     * @return bool
     */
    public function canTryAgain($attempt, $ignoreTransactionLevel = false)
    {
        $canByAttempt = ($attempt < $this->reconnectAttempts);
        $canByTransactionNestingLevel = $ignoreTransactionLevel ? true : (0 === $this->getTransactionNestingLevel());

        return $canByAttempt && $canByTransactionNestingLevel;
    }

    /**
     * @param \Exception  $e
     * @param string|null $query
     *
     * @return bool
     */
    public function isRetryableException(\Exception $e, $query = null)
    {
        if (null === $query || $this->isUpdateQuery($query)) {
            return $this->_driver->isGoneAwayInUpdateException($e);
        }

        return $this->_driver->isGoneAwayException($e);
    }

    /**
     * @param string $query
     *
     * @return bool
     */
    public function isUpdateQuery($query)
    {
        return !preg_match('/^[\s\n\r\t(]*(select|show|describe)[\s\n\r\t(]+/i', $query);
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        parent::close();

        $this->_conn = null;
        $this->connections = array('master' => null, 'slave' => null);
    }
}
