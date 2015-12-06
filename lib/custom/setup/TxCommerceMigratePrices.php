<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates price from TYPO3 commerce extension
 */
class TxCommerceMigratePrices extends TxCommerceBase
{
	private $map = array();
	private $size = 100;

	private $sql = '
		SELECT a."uid", a."tax", p."price_gross", p."hidden"
		FROM "tx_commerce_article_prices" p
		JOIN "tx_commerce_articles" a ON a."uid" = p."uid_article"
		WHERE p."deleted" = 0 AND a."deleted" = 0
		LIMIT ? OFFSET ?
	';


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'TxCommerceMigrateArticles' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate prices', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_article_prices' ) === true
			&& $this->schema->tableExists( 'tx_commerce_articles' ) === true )
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
					$list[$row['uid']][] = $row;
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
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.code', array_keys( $list ) ) );
		$search->setSlice( 0, count( $list ) );

		foreach( $manager->searchItems( $search, array( 'price' ) ) as $id => $item ) {
			$map[$item->getCode()] = $item;
		}

		$manager->begin();

		$this->updatePrices( $list, $map );

		$manager->commit();
	}


	protected function updatePrices( array $list, array $map )
	{
		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'price' );
		$listManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$typeId = $this->getTypeId( 'price/type', 'product', 'default' );
		$listTypeId = $this->getTypeId( 'product/lists/type', 'price', 'default' );

		$manager->begin();

		foreach( $map as $code => $item )
		{
			$pos = 0;
			$listItems = $item->getListItems( 'price', 'default' );

			foreach( (array) $list[$code] as $entry )
			{
				if( ( $listItem = array_shift( $listItems ) ) === null )
				{
					$listItem = $listManager->createItem();
					$priceItem = $manager->createItem();
				}
				else
				{
					$priceItem = $listItem->getRefItem();
				}

				$priceItem->setTypeId( $typeId );
				$priceItem->setLabel( $item->getLabel() );
				$priceItem->setDomain( 'product' );
				$priceItem->setCurrencyId( 'EUR' );
				$priceItem->setTaxRate( $entry['tax'] );
				$priceItem->setValue( $entry['price_gross'] / 100 );
				$priceItem->setCosts( '0.00' );
				$priceItem->setRebate( '0.00' );
				$priceItem->setQuantity( 1 );
				$priceItem->setStatus( 1 );

				$manager->saveItem( $priceItem );

				$listItem->setTypeId( $listTypeId );
				$listItem->setRefId( $priceItem->getId() );
				$listItem->setParentId( $item->getId() );
				$listItem->setPosition( $pos++ );
				$listItem->setDomain( 'price' );
				$listItem->setStatus( 1 );

				$listManager->saveItem( $listItem, false );
			}
		}

		$manager->commit();
	}
}