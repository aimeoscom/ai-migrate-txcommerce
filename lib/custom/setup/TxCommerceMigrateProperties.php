<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates properties from TYPO3 commerce extension
 */
class TxCommerceMigrateProperties extends TxCommerceBase
{
	private $map = array();
	private $size = 100;

	private $sql = '
		SELECT a."title", av."hidden", av."value"
		FROM "tx_commerce_attribute_values" av
		JOIN "tx_commerce_attributes" a ON av."attributes_uid" = a."uid"
		WHERE av."deleted" = 0 AND a."deleted" = 0 AND a."has_valuelist" = 0
		ORDER BY av."sorting", av."uid"
		LIMIT ? OFFSET ?
	';


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'TxCommerceMigrateArticles', 'TxCommerceMigratePropertyTypes' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate properties', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_attributes' ) === true
			&& $this->schema->tableExists( 'tx_commerce_attribute_values' ) === true )
		{
			$offset = 0;
			$conn = $this->getConnection( 'db' );

			$stmt = $conn->create( $this->sql );

			do
			{
				$this->msg( 'From ' . ($offset + 1) . ' to ' . ($offset + $this->size), 1 );

				$stmt->bind( 1, $this->size, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $offset, \Aimeos\MW\DB\Statement\Base::PARAM_INT );

				$result = $stmt->execute();
				$list = array();

				while( ( $row = $result->fetch() ) !== false ) {
					$list[$row['value']] = $row;
				}

				$result->finish();

//				$this->update( $list );

				$offset += count( $list );

				$this->status( 'done' );
			}
			while( count( $list ) === $this->size );
		}
	}


	protected function update( array $list )
	{
		$map = array();
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/property' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.property.code', array_keys( $list ) ) );
		$search->setSlice( 0, count( $list ) );

		foreach( $manager->searchItems( $search ) as $id => $item ) {
			$map[$item->getCode()] = $item;
		}

		$manager->begin();

		foreach( $list as $code => $entry )
		{
			if( !isset( $map[$code] ) ) {
				$item = $manager->createItem();
			} else {
				$item = $map[$code];
			}

			$item->setParentId( '...' );
			$item->setValue( $entry['value'] );
			$item->setStatus( ! (bool) $entry['hidden'] );
			$item->setTypeId( $this->getTypeId( 'product/property/type', 'product', $entry['title'] ) );

			$manager->saveItem( $item );
		}

		$manager->commit();
	}
}