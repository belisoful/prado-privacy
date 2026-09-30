<?php

/**
 * TPrivacyDbConnectionTrait class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-privacy
 * @license https://github.com/belisoful/prado-privacy/blob/main/LICENSE
 */

namespace belisoful\Privacy\Data;

use Prado\Data\TDbConnection;
use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;

/**
 * TPrivacyDbConnectionTrait trait.
 *
 * TPrivacyDbConnectionTrait gives a component the database connection its records use:
 *
 * | Configuration | Connection |
 * |---|---|
 * | `DbConnection` set (an instance) | that connection |
 * | `ConnectionID="db"` | the connection of that module (a `TDataSourceConfig`, `TActiveRecordConfig`, or any module with `getDbConnection()`) |
 * | neither | `sqlite:<runtime>/privacy.db`; needs `pdo_sqlite` |
 *
 * With `AutoCreateTables` (the default) the component creates its tables on first use.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
trait TPrivacyDbConnectionTrait
{
	/** @var string the id of the module holding the connection */
	private string $_connectionID = '';

	/** @var ?TDbConnection the connection */
	private ?TDbConnection $_dbConnection = null;

	/** @var bool whether tables are created on first use */
	private bool $_autoCreateTables = true;

	/**
	 * @return string the id of the module holding the connection; empty for the runtime sqlite database
	 */
	public function getConnectionID(): string
	{
		return $this->_connectionID;
	}

	/**
	 * @param mixed $value the id of the module holding the connection; empty for the runtime sqlite database
	 * @return static the component
	 */
	public function setConnectionID($value): static
	{
		$this->_connectionID = \trim(TPropertyValue::ensureString($value));
		$this->_dbConnection = null;
		return $this;
	}

	/**
	 * @return bool whether tables are created on first use
	 */
	public function getAutoCreateTables(): bool
	{
		return $this->_autoCreateTables;
	}

	/**
	 * @param mixed $value whether tables are created on first use
	 * @return static the component
	 */
	public function setAutoCreateTables($value): static
	{
		$this->_autoCreateTables = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * Returns the active connection, resolving it on first use.
	 * @throws TConfigurationException when the ConnectionID module has no connection, or pdo_sqlite is missing for the default database
	 * @return TDbConnection the connection
	 */
	public function getDbConnection(): TDbConnection
	{
		if ($this->_dbConnection === null) {
			$this->_dbConnection = $this->createDbConnection();
		}
		$this->_dbConnection->setActive(true);
		return $this->_dbConnection;
	}

	/**
	 * @param ?TDbConnection $value the connection; null resolves it again from `ConnectionID`
	 * @return static the component
	 */
	public function setDbConnection(?TDbConnection $value): static
	{
		$this->_dbConnection = $value;
		return $this;
	}

	/**
	 * Returns the connection with a table ready.
	 * @param string $table the table the caller uses
	 * @return TDbConnection the connection
	 */
	protected function getTableConnection(string $table): TDbConnection
	{
		$connection = $this->getDbConnection();
		if ($this->getAutoCreateTables()) {
			TPrivacySchema::ensureTable($connection, $table);
		}
		return $connection;
	}

	/**
	 * @throws TConfigurationException when the ConnectionID module has no connection, or pdo_sqlite is missing for the default database
	 * @return TDbConnection the connection named by `ConnectionID`, or the runtime sqlite database
	 */
	protected function createDbConnection(): TDbConnection
	{
		$app = Prado::getApplication();
		if (($id = $this->getConnectionID()) !== '') {
			$module = $app->getModule($id);
			$connection = ($module !== null && \method_exists($module, 'getDbConnection')) ? $module->getDbConnection() : null;
			if (!($connection instanceof TDbConnection)) {
				throw new TConfigurationException('privacy_connection_invalid', $id);
			}
			return $connection;
		}
		if (!$this->hasSqliteDriver()) {
			throw new TConfigurationException('privacy_sqlite_unavailable', static::class);
		}
		return new TDbConnection('sqlite:' . $app->getRuntimePath() . DIRECTORY_SEPARATOR . 'privacy.db');
	}

	/**
	 * @return bool whether PDO can open a sqlite database
	 */
	protected function hasSqliteDriver(): bool
	{
		return \in_array('sqlite', \PDO::getAvailableDrivers(), true);
	}
}
