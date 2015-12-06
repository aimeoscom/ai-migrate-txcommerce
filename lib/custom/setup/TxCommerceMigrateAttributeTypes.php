<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates attribute types from TYPO3 commerce extension
 */
class TxCommerceMigrateAttributeTypes extends TxCommerceBase
{
	private $map = array();
	private $size = 100;

	private $sql = '
		SELECT * FROM "tx_commerce_attributes"
		WHERE "deleted" = 0 AND "has_valuelist" = 1
		ORDER BY "uid"
		LIMIT ? OFFSET ?
	';


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate attribute types', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_attributes' ) === true )
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
					$list[$row['title']] = $row;
				}

				$result->finish();

				$this->update( $list );

				$offset += count( $list );

				$this->status( 'done' );
			}
			while( count( $list ) === $this->size );
		}
	}


	protected function update( array $list )
	{
		$map = array();
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'attribute/type' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'attribute.type.code', array_keys( $list ) ) );
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

			$item->setDomain( 'product' );
			$item->setCode( $entry['title'] );
			$item->setLabel( $entry['title'] );
			$item->setStatus( ! (bool) $entry['hidden'] );

			$manager->saveItem( $item );
		}

		$manager->commit();
	}
}